<?php
/**
 * Blink Debit API client for WordPress.
 *
 * @package blinkpay-nz-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\BlinkDebitClient;

/**
 * Adapts the official Blink Debit PHP SDK to WordPress conventions.
 *
 * The SDK owns the protocol — authentication, token caching, retries on 429,
 * 5xx and transport failures, local validation and the mapping of statuses to
 * typed exceptions — while this class owns the two things that must be
 * WordPress's: HTTP goes through the WordPress HTTP API, and tokens are cached
 * in WordPress storage. Failures are converted to WP_Error so the gateway
 * keeps its is_wp_error() contract; error data carries the HTTP status and
 * decoded body the gateway inspects to tell an idempotency conflict, a missing
 * scope and an unregistered redirect URI apart.
 */
class WC_BlinkPay_API_Client {

	/** @var BlinkDebitClient */
	private $client;

	/** @var bool */
	private $debug;

	/**
	 * @param string $client_id     The BlinkPay client ID.
	 * @param string $client_secret The BlinkPay client secret.
	 * @param bool   $sandbox       Whether to use the sandbox environment.
	 * @param bool   $debug         Whether to write debug entries to the WooCommerce log.
	 */
	public function __construct( $client_id, $client_secret, $sandbox = true, $debug = false ) {
		$this->debug  = (bool) $debug;
		$this->client = new BlinkDebitClient(
			(string) $client_id,
			(string) $client_secret,
			(bool) $sandbox,
			new WC_BlinkPay_Token_Cache(),
			new WC_BlinkPay_HTTP_Transport()
		);
		$this->client->setSleep(
			function ( $milliseconds ) {
				$this->pause( $milliseconds );
			}
		);
	}

	/**
	 * Waits between the SDK's retry attempts. A seam so tests can skip real
	 * sleeps, as WC_BlinkPay_Gateway::pause() is for its polling loops.
	 *
	 * @param int $milliseconds How long to pause.
	 */
	protected function pause( $milliseconds ) {
		usleep( (int) $milliseconds * 1000 );
	}

	/**
	 * Overrides the request timeout for this client instance, covering the
	 * token fetch too. The default suits checkout and cron, where correctness
	 * beats latency; an admin screen rendering inline must fail fast instead
	 * of holding the page open for the full budget.
	 *
	 * @param int $seconds The timeout in seconds.
	 */
	public function set_request_timeout( $seconds ) {
		$this->client->setRequestTimeout( (int) $seconds );
	}

	/**
	 * Whether both credentials are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return $this->client->isConfigured();
	}

	/**
	 * The scopes granted at the last token fetch, or null when they are
	 * unknown — no token has been fetched yet, or the token response carried
	 * no scope. Reads only the cache; never makes a request.
	 *
	 * @return string[]|null
	 */
	public function get_granted_scopes() {
		return $this->client->getGrantedScopes();
	}

	/**
	 * Returns a cached access token, fetching a new one when missing or forced.
	 *
	 * @param bool $force_refresh Discard any cached token first.
	 * @return string|WP_Error
	 */
	public function get_access_token( $force_refresh = false ) {
		try {
			return $this->client->getAccessToken( (bool) $force_refresh );
		} catch ( BlinkDebitApiException $exception ) {
			$this->log( 'Access token request failed with HTTP ' . $exception->getStatusCode() );
			return $this->to_wp_error( $exception );
		}
	}

	/**
	 * Creates a quick payment (single consent + one-off debit).
	 *
	 * @param array  $payload         The quick payment request body.
	 * @param string $idempotency_key Idempotency key so a checkout retry cannot double-create.
	 * @return array|WP_Error
	 */
	public function create_quick_payment( array $payload, $idempotency_key ) {
		return $this->call(
			'POST /quick-payments',
			function () use ( $payload, $idempotency_key ) {
				return $this->client->createQuickPayment( $payload, (string) $idempotency_key );
			}
		);
	}

	/**
	 * Retrieves a quick payment. The first call after authorisation initiates
	 * the debit, so callers must treat errors as "outcome not yet known".
	 *
	 * @param string $quick_payment_id The quick payment ID.
	 * @return array|WP_Error
	 */
	public function get_quick_payment( $quick_payment_id ) {
		return $this->call(
			'GET /quick-payments',
			function () use ( $quick_payment_id ) {
				return $this->client->getQuickPayment( (string) $quick_payment_id );
			}
		);
	}

	/**
	 * Creates a refund against a settled payment.
	 *
	 * @param array       $payload         The refund request body.
	 * @param string|null $idempotency_key Idempotency key so a retried refund replays
	 *                                     instead of refunding twice, or null for none.
	 * @return array|WP_Error
	 */
	public function create_refund( array $payload, $idempotency_key = null ) {
		return $this->call(
			'POST /refunds',
			function () use ( $payload, $idempotency_key ) {
				return $this->client->createRefund(
					$payload,
					null === $idempotency_key ? null : (string) $idempotency_key
				);
			}
		);
	}

	/**
	 * @param string $refund_id The refund ID.
	 * @return array|WP_Error
	 */
	public function get_refund( $refund_id ) {
		return $this->call(
			'GET /refunds',
			function () use ( $refund_id ) {
				return $this->client->getRefund( (string) $refund_id );
			}
		);
	}

	/**
	 * Runs one SDK call, logging its outcome and converting any failure to a
	 * WP_Error.
	 *
	 * @param string   $operation The operation name, for the debug log only.
	 * @param callable $request   The SDK call.
	 * @return array|WP_Error
	 */
	private function call( $operation, callable $request ) {
		try {
			$response = $request();
			$this->log( $operation . ' succeeded' );

			return $response;
		} catch ( BlinkDebitApiException $exception ) {
			// Status 0 covers local validation and transport failures, which
			// never reached the API.
			$this->log( $operation . ' failed with HTTP ' . $exception->getStatusCode() );

			return $this->to_wp_error( $exception );
		}
	}

	/**
	 * Converts an SDK exception to the WP_Error shape the gateway reads. The
	 * SDK guarantees its messages carry no credentials, tokens or request
	 * bodies, so they are safe to put in an order note or a log.
	 *
	 * @param BlinkDebitApiException $exception The SDK exception.
	 * @return WP_Error
	 */
	private function to_wp_error( BlinkDebitApiException $exception ) {
		return new WP_Error(
			'blinkpay_api_error',
			$exception->getMessage(),
			array(
				'status' => $exception->getStatusCode(),
				'body'   => $exception->getResponseBody(),
			)
		);
	}

	/**
	 * Writes to the WooCommerce log when debug logging is enabled. Never logs
	 * credentials, tokens or request bodies.
	 *
	 * @param string $message The log message.
	 */
	private function log( $message ) {
		if ( $this->debug && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message, array( 'source' => 'blinkpay' ) );
		}
	}
}
