<?php
/**
 * Minimal Blink Debit API client for WordPress.
 *
 * Covers the OAuth token endpoint plus the quick payment and refund endpoints,
 * using the gateway authorisation flow.
 *
 * @package blinkpay-nz-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Blink Debit API using WordPress HTTP functions only.
 */
class WC_BlinkPay_API_Client {

	const PRODUCTION_BASE_URL = 'https://debit.blinkpay.co.nz';
	const SANDBOX_BASE_URL    = 'https://sandbox.debit.blinkpay.co.nz';

	// Access tokens last one hour; refresh five minutes early so an in-flight
	// checkout never crosses the expiry boundary with a stale token.
	const TOKEN_EXPIRY_BUFFER = 300;

	const REQUEST_TIMEOUT = 30;

	/** @var string */
	private $client_id;

	/** @var string */
	private $client_secret;

	/** @var bool */
	private $sandbox;

	/** @var bool */
	private $debug;

	/**
	 * @param string $client_id     The BlinkPay client ID.
	 * @param string $client_secret The BlinkPay client secret.
	 * @param bool   $sandbox       Whether to use the sandbox environment.
	 * @param bool   $debug         Whether to write debug entries to the WooCommerce log.
	 */
	public function __construct( $client_id, $client_secret, $sandbox = true, $debug = false ) {
		$this->client_id     = trim( (string) $client_id );
		$this->client_secret = trim( (string) $client_secret );
		$this->sandbox       = (bool) $sandbox;
		$this->debug         = (bool) $debug;
	}

	/**
	 * Whether both credentials are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->client_id && '' !== $this->client_secret;
	}

	/**
	 * @return string
	 */
	private function base_url() {
		return $this->sandbox ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
	}

	/**
	 * Transient key scoped to the environment and client, so switching either
	 * never reuses a token issued for the other.
	 *
	 * @return string
	 */
	private function token_cache_key() {
		return 'wc_blinkpay_token_' . hash( 'sha256', ( $this->sandbox ? 'sandbox' : 'production' ) . '|' . $this->client_id );
	}

	/**
	 * Option key holding the scopes last granted to this client, scoped like
	 * the token cache. An option rather than a transient, so the grant is
	 * still known after the token itself has expired.
	 *
	 * @return string
	 */
	private function scope_cache_key() {
		return 'wc_blinkpay_scopes_' . hash( 'sha256', ( $this->sandbox ? 'sandbox' : 'production' ) . '|' . $this->client_id );
	}

	/**
	 * The scopes granted at the last token fetch, or null when they are
	 * unknown — no token has been fetched yet, or the token response carried
	 * no scope. Reads only the cache; never makes a request.
	 *
	 * @return string[]|null
	 */
	public function get_granted_scopes() {
		$scope = get_option( $this->scope_cache_key(), '' );

		return is_string( $scope ) && '' !== $scope ? preg_split( '/\s+/', trim( $scope ) ) : null;
	}

	/**
	 * Returns a cached access token, fetching a new one when missing or forced.
	 *
	 * @param bool $force_refresh Discard any cached token first.
	 * @return string|WP_Error
	 */
	public function get_access_token( $force_refresh = false ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'blinkpay_not_configured',
				__( 'BlinkPay is not configured. Enter the client ID and client secret in the gateway settings.', 'blinkpay-nz-for-woocommerce' )
			);
		}

		if ( $force_refresh ) {
			delete_transient( $this->token_cache_key() );
		} else {
			$cached = get_transient( $this->token_cache_key() );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		// The documented token contract is OAuth 2.0 form encoding. The server
		// happens to accept a JSON body too, but that is undocumented
		// behaviour a hardening change could withdraw without notice.
		$response = wp_remote_post(
			$this->base_url() . '/oauth2/token',
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'    => http_build_query(
					array(
						'grant_type'    => 'client_credentials',
						'client_id'     => $this->client_id,
						'client_secret' => $this->client_secret,
					),
					'',
					'&'
				),
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Access token request failed: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$this->log( 'Access token request rejected with HTTP ' . $code );
			return new WP_Error(
				'blinkpay_auth_failed',
				__( 'Could not authenticate with BlinkPay. Check the client ID and client secret.', 'blinkpay-nz-for-woocommerce' )
			);
		}

		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		set_transient( $this->token_cache_key(), $body['access_token'], max( 60, $expires_in - self::TOKEN_EXPIRY_BUFFER ) );

		// The granted scope decides which features (refunds) are offered, so
		// it is retained beyond the token's own lifetime.
		if ( isset( $body['scope'] ) && is_string( $body['scope'] ) && '' !== $body['scope'] ) {
			update_option( $this->scope_cache_key(), $body['scope'], false );
		} else {
			delete_option( $this->scope_cache_key() );
		}

		return $body['access_token'];
	}

	/**
	 * Creates a quick payment (single consent + one-off debit).
	 *
	 * @param array  $payload         The quick payment request body.
	 * @param string $idempotency_key Idempotency key so a checkout retry cannot double-create.
	 * @return array|WP_Error
	 */
	public function create_quick_payment( array $payload, $idempotency_key ) {
		return $this->request( 'POST', '/quick-payments', $payload, array( 'idempotency-key' => $idempotency_key ) );
	}

	/**
	 * Retrieves a quick payment. The first call after authorisation initiates
	 * the debit, so callers must treat errors as "outcome not yet known".
	 *
	 * @param string $quick_payment_id The quick payment ID.
	 * @return array|WP_Error
	 */
	public function get_quick_payment( $quick_payment_id ) {
		return $this->request( 'GET', '/quick-payments/' . rawurlencode( $quick_payment_id ) );
	}

	/**
	 * Creates a refund against a settled payment.
	 *
	 * @param array $payload The refund request body.
	 * @return array|WP_Error
	 */
	public function create_refund( array $payload ) {
		return $this->request( 'POST', '/refunds', $payload );
	}

	/**
	 * @param string $refund_id The refund ID.
	 * @return array|WP_Error
	 */
	public function get_refund( $refund_id ) {
		return $this->request( 'GET', '/refunds/' . rawurlencode( $refund_id ) );
	}

	/**
	 * Sends an authenticated request to the Blink Debit API.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $path     Path under /payments/v1.
	 * @param array|null $body     JSON body, if any.
	 * @param array      $headers  Extra headers.
	 * @param bool       $retrying Internal: whether this is the post-401 retry.
	 * @return array|WP_Error Decoded JSON body (empty array for 204 responses).
	 */
	private function request( $method, $path, ?array $body = null, array $headers = array(), $retrying = false ) {
		$token = $this->get_access_token( $retrying );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
				array_filter( $headers )
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url() . '/payments/v1' . $path, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( $method . ' ' . $path . ' transport error: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		$this->log( $method . ' ' . $path . ' returned HTTP ' . $code );

		// A cached token can outlive a credential rotation; refresh once and retry.
		if ( 401 === $code && ! $retrying ) {
			return $this->request( $method, $path, $body, $headers, true );
		}

		if ( $code >= 400 ) {
			return new WP_Error(
				'blinkpay_api_error',
				$this->extract_error_message( $data, $code ),
				array(
					'status' => $code,
					'body'   => $data,
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Builds a developer-facing message from a Blink Debit error response.
	 *
	 * @param array|null $data The decoded error body.
	 * @param int        $code The HTTP status code.
	 * @return string
	 */
	private function extract_error_message( $data, $code ) {
		$message = '';
		if ( is_array( $data ) ) {
			if ( ! empty( $data['message'] ) ) {
				$message = $data['message'];
			} elseif ( ! empty( $data['error'] ) ) {
				$message = $data['error'];
			}
			if ( ! empty( $data['code'] ) ) {
				$message .= ' (' . $data['code'] . ')';
			}
		}
		if ( '' === $message ) {
			/* translators: %d: HTTP status code */
			$message = sprintf( __( 'BlinkPay request failed with HTTP %d.', 'blinkpay-nz-for-woocommerce' ), $code );
		}

		return $message;
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
