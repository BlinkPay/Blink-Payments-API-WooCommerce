<?php
/**
 * The BlinkPay WooCommerce payment gateway.
 *
 * Orders are paid with Blink PayNow quick payments through BlinkPay's hosted
 * gateway flow.
 *
 * @package blinkpay-nz-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * BlinkPay payment gateway.
 */
class WC_BlinkPay_Gateway extends WC_Payment_Gateway {

	const STATUS_CHECK_HOOK = 'wc_blinkpay_check_payment_status';

	// Inline polling on the return page: attempts x delay, keeping the redirect responsive.
	const INLINE_POLL_ATTEMPTS = 5;
	const INLINE_POLL_DELAY    = 2;

	// Deferred WP-Cron checks: once a minute, giving up after roughly half an hour.
	const MAX_STATUS_CHECKS      = 30;
	const STATUS_CHECK_DELAY     = 60;

	/** @var bool */
	public $sandbox;

	/** @var bool */
	public $debug;

	/** @var string */
	public $pcr_particulars;

	public function __construct() {
		$this->id                 = 'blinkpay';
		$this->icon               = apply_filters( 'wc_blinkpay_icon', WC_BLINKPAY_PLUGIN_URL . 'assets/images/blinkpay-logo.png' );
		$this->has_fields         = false;
		$this->method_title       = __( 'BlinkPay', 'blinkpay-nz-for-woocommerce' );
		$this->method_description = __( 'Accept New Zealand bank payments through BlinkPay open banking. Orders are paid with Blink PayNow quick payments.', 'blinkpay-nz-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->sandbox         = 'yes' === $this->get_option( 'sandbox', 'yes' );
		$this->debug           = 'yes' === $this->get_option( 'debug', 'no' );
		$this->pcr_particulars = $this->get_option( 'pcr_particulars', 'Order' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * The logo shown next to the payment method title at checkout, height
	 * constrained because the source image is full size.
	 *
	 * @return string
	 */
	public function get_icon() {
		if ( ! $this->icon ) {
			return '';
		}

		$icon = '<img src="' . esc_url( $this->icon ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="max-height: 24px; vertical-align: middle;" />';

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core's own filter, applied by WC_Payment_Gateway::get_icon(), kept so icon customisations still apply.
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * The gateway settings screen, with the BlinkPay logo above the fields.
	 */
	public function admin_options() {
		if ( $this->icon ) {
			echo '<img src="' . esc_url( $this->icon ) . '" alt="BlinkPay" style="max-height: 40px; margin-top: 1em;" />';
		}
		parent::admin_options();
	}

	/**
	 * Settings fields shown under WooCommerce > Settings > Payments > BlinkPay.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'blinkpay-nz-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable BlinkPay', 'blinkpay-nz-for-woocommerce' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'blinkpay-nz-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The payment method name the customer sees at checkout.', 'blinkpay-nz-for-woocommerce' ),
				'default'     => __( 'Checkout with BlinkPay', 'blinkpay-nz-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'blinkpay-nz-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'The payment method description the customer sees at checkout.', 'blinkpay-nz-for-woocommerce' ),
				'default'     => __( 'Pay securely from your New Zealand bank account.', 'blinkpay-nz-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'sandbox'         => array(
				'title'       => __( 'Sandbox mode', 'blinkpay-nz-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use the BlinkPay sandbox environment', 'blinkpay-nz-for-woocommerce' ),
				'description' => __( 'Sandbox payments never move real money. Untick this only with production credentials.', 'blinkpay-nz-for-woocommerce' ),
				'default'     => 'yes',
			),
			'client_id'       => array(
				'title'       => __( 'Client ID', 'blinkpay-nz-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Issued by BlinkPay for the selected environment. Can also be set with the BLINKPAY_CLIENT_ID constant in wp-config.php, which takes precedence.', 'blinkpay-nz-for-woocommerce' ),
				'default'     => '',
			),
			'client_secret'   => array(
				'title'       => __( 'Client secret', 'blinkpay-nz-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Issued by BlinkPay for the selected environment. For stronger protection define the BLINKPAY_CLIENT_SECRET constant in wp-config.php instead of storing it in the database.', 'blinkpay-nz-for-woocommerce' ),
				'default'     => '',
			),
			'pcr_particulars' => array(
				'title'       => __( 'Bank statement particulars', 'blinkpay-nz-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Shown on the customer\'s bank statement, up to 12 characters. The order number is sent as the reference.', 'blinkpay-nz-for-woocommerce' ),
				'default'     => 'Order',
				'desc_tip'    => true,
			),
			'debug'           => array(
				'title'       => __( 'Debug logging', 'blinkpay-nz-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log API activity to WooCommerce > Status > Logs (source: blinkpay)', 'blinkpay-nz-for-woocommerce' ),
				'description' => __( 'Credentials and tokens are never logged.', 'blinkpay-nz-for-woocommerce' ),
				'default'     => 'no',
			),
		);
	}

	/**
	 * BlinkPay processes NZD bank payments only.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available()
			&& 'NZD' === get_woocommerce_currency()
			&& $this->get_api_client()->is_configured();
	}

	/**
	 * Builds an API client from the configured credentials. Constants defined
	 * in wp-config.php override the settings stored in the database.
	 *
	 * @return WC_BlinkPay_API_Client
	 */
	public function get_api_client() {
		$client_id     = defined( 'BLINKPAY_CLIENT_ID' ) ? BLINKPAY_CLIENT_ID : $this->get_option( 'client_id' );
		$client_secret = defined( 'BLINKPAY_CLIENT_SECRET' ) ? BLINKPAY_CLIENT_SECRET : $this->get_option( 'client_secret' );

		return new WC_BlinkPay_API_Client( $client_id, $client_secret, $this->sandbox, $this->debug );
	}

	/**
	 * Starts the payment journey: creates a quick payment and returns the
	 * hosted gateway URL.
	 *
	 * @param int $order_id The order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || 'NZD' !== $order->get_currency() ) {
			wc_add_notice( __( 'BlinkPay can only process payments in New Zealand dollars.', 'blinkpay-nz-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		return $this->start_quick_payment( $order );
	}

	/**
	 * Creates a gateway-flow quick payment and redirects the customer to it.
	 *
	 * @param WC_Order $order The order.
	 * @return array
	 */
	private function start_quick_payment( $order ) {
		$payload = array(
			'flow'                       => array(
				'detail' => array(
					'type'         => 'gateway',
					'redirect_uri' => $this->get_gateway_return_url( $order ),
				),
			),
			'amount'                     => array(
				'currency' => 'NZD',
				'total'    => $this->format_amount( $order->get_total() ),
			),
			'pcr'                        => $this->build_pcr( $this->pcr_particulars, $order->get_order_number() ),
			'hashed_customer_identifier' => hash( 'sha256', strtolower( (string) $order->get_billing_email() ) ),
		);

		$payload = apply_filters( 'wc_blinkpay_quick_payment_payload', $payload, $order );

		$response = $this->get_api_client()->create_quick_payment(
			$payload,
			$this->get_idempotency_key( $order, 'quick_payment' )
		);

		if ( is_wp_error( $response ) || empty( $response['redirect_uri'] ) || empty( $response['quick_payment_id'] ) ) {
			$detail = is_wp_error( $response ) ? $response->get_error_message() : __( 'No redirect URI was returned.', 'blinkpay-nz-for-woocommerce' );
			/* translators: %s: API error detail */
			$order->add_order_note( sprintf( __( 'BlinkPay quick payment creation failed: %s', 'blinkpay-nz-for-woocommerce' ), $detail ) );
			wc_add_notice( __( 'We could not start your BlinkPay payment. Please try again or choose another payment method.', 'blinkpay-nz-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_blinkpay_quick_payment_id', $response['quick_payment_id'] );
		/* translators: %s: quick payment ID */
		$order->add_order_note( sprintf( __( 'BlinkPay quick payment %s created; customer redirected to the Blink gateway.', 'blinkpay-nz-for-woocommerce' ), $response['quick_payment_id'] ) );
		$order->save();

		// The debit is only initiated by retrieving the quick payment, so the
		// deferred check must not depend on the customer's browser returning.
		$this->schedule_status_check( $order );

		return array(
			'result'   => 'success',
			'redirect' => $response['redirect_uri'],
		);
	}

	/**
	 * Handles the customer returning from the Blink gateway
	 * (/wc-api/blinkpay_return/). Blink appends `cid` plus, on non-success,
	 * a `status` of cancelled/rejected/timeout/error, or `pending` while the
	 * outcome is unknown. A return with no `status` is not confirmation — the
	 * consent must be checked through the API.
	 */
	public function handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key authenticates this bank redirect.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable

		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->key_is_valid( $key ) || $this->id !== $order->get_payment_method() ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		// Any status other than blank or "pending" is a non-success outcome,
		// including values this version does not recognise.
		if ( '' !== $status && 'pending' !== $status ) {
			/* translators: %s: gateway status parameter */
			$this->fail_order( $order, sprintf( __( 'The customer did not complete the BlinkPay gateway journey (status: %s).', 'blinkpay-nz-for-woocommerce' ), $status ) );
			wc_add_notice( __( 'Your payment was not completed and you have not been charged. Please try again.', 'blinkpay-nz-for-woocommerce' ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		$this->confirm_quick_payment( $order );

		if ( $order->has_status( 'failed' ) ) {
			wc_add_notice( __( 'Your payment was not completed. Please try again.', 'blinkpay-nz-for-woocommerce' ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	/**
	 * Polls the quick payment until it reaches a terminal state or the inline
	 * budget is exhausted. The first retrieval initiates the debit, so an
	 * error response means "outcome not yet known", never "failed".
	 *
	 * @param WC_Order $order The order.
	 * @return string One of 'paid', 'failed' or 'pending'.
	 */
	public function confirm_quick_payment( $order ) {
		$quick_payment_id = $order->get_meta( '_blinkpay_quick_payment_id' );
		if ( ! $quick_payment_id ) {
			$this->fail_order( $order, __( 'No BlinkPay quick payment ID was stored against this order.', 'blinkpay-nz-for-woocommerce' ) );
			return 'failed';
		}

		$client = $this->get_api_client();

		for ( $attempt = 0; $attempt < self::INLINE_POLL_ATTEMPTS; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( self::INLINE_POLL_DELAY );
			}

			$response = $client->get_quick_payment( $quick_payment_id );
			if ( is_wp_error( $response ) ) {
				continue;
			}

			$outcome = $this->evaluate_consent( $order, isset( $response['consent'] ) ? $response['consent'] : array() );
			if ( 'pending' !== $outcome ) {
				return $outcome;
			}
		}

		$this->await_confirmation( $order, __( 'BlinkPay has not yet confirmed the payment. The order will update automatically once the outcome is known.', 'blinkpay-nz-for-woocommerce' ) );

		return 'pending';
	}

	/**
	 * Applies a consent's state to the order.
	 *
	 * @param WC_Order $order   The order.
	 * @param array    $consent The consent model from the API.
	 * @return string One of 'paid', 'failed' or 'pending'.
	 */
	public function evaluate_consent( $order, array $consent ) {
		if ( ! empty( $consent['payments'][0] ) ) {
			return $this->apply_payment_result( $order, $consent['payments'][0] );
		}

		$status = isset( $consent['status'] ) ? $consent['status'] : '';
		if ( in_array( $status, array( 'Rejected', 'Revoked', 'GatewayTimeout' ), true ) ) {
			/* translators: %s: consent status */
			$this->fail_order( $order, sprintf( __( 'The BlinkPay consent ended with status %s.', 'blinkpay-nz-for-woocommerce' ), $status ) );
			return 'failed';
		}

		return 'pending';
	}

	/**
	 * Applies a payment's state to the order. Only AcceptedSettlementCompleted
	 * counts as paid; AcceptedSettlementInProcess and Pending stay pending and
	 * are resolved by the deferred status checks.
	 *
	 * @param WC_Order $order   The order.
	 * @param array    $payment The payment model from the API.
	 * @return string One of 'paid', 'failed' or 'pending'.
	 */
	public function apply_payment_result( $order, array $payment ) {
		$status     = isset( $payment['status'] ) ? $payment['status'] : '';
		$payment_id = isset( $payment['payment_id'] ) ? $payment['payment_id'] : '';

		if ( $payment_id && ! $order->get_meta( '_blinkpay_payment_id' ) ) {
			$order->update_meta_data( '_blinkpay_payment_id', $payment_id );
			$order->save();
		}

		if ( 'AcceptedSettlementCompleted' === $status ) {
			$order->payment_complete( $payment_id );
			/* translators: %s: payment ID */
			$order->add_order_note( sprintf( __( 'BlinkPay payment %s completed.', 'blinkpay-nz-for-woocommerce' ), $payment_id ) );
			return 'paid';
		}

		if ( 'Rejected' === $status ) {
			/* translators: %s: payment ID */
			$this->fail_order( $order, sprintf( __( 'BlinkPay payment %s was rejected by the bank.', 'blinkpay-nz-for-woocommerce' ), $payment_id ) );
			return 'failed';
		}

		return 'pending';
	}

	/**
	 * Parks the order on hold and schedules deferred status checks.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $note  The order note explaining why.
	 */
	public function await_confirmation( $order, $note ) {
		if ( $order->has_status( 'on-hold' ) ) {
			$order->add_order_note( $note );
		} else {
			$order->update_status( 'on-hold', $note );
		}
		$this->schedule_status_check( $order );
	}

	/**
	 * @param WC_Order $order The order.
	 */
	public function schedule_status_check( $order ) {
		if ( ! wp_next_scheduled( self::STATUS_CHECK_HOOK, array( $order->get_id() ) ) ) {
			wp_schedule_single_event( time() + self::STATUS_CHECK_DELAY, self::STATUS_CHECK_HOOK, array( $order->get_id() ) );
		}
	}

	/**
	 * WP-Cron callback: re-checks a not-yet-terminal payment.
	 *
	 * @param int $order_id The order ID.
	 */
	public function check_payment_status( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order
			|| $this->id !== $order->get_payment_method()
			|| $order->is_paid()
			|| $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ) ) {
			return;
		}

		$attempts = (int) $order->get_meta( '_blinkpay_status_checks' ) + 1;
		$order->update_meta_data( '_blinkpay_status_checks', $attempts );
		$order->save();

		$outcome = 'pending';

		$quick_payment_id = $order->get_meta( '_blinkpay_quick_payment_id' );
		if ( $quick_payment_id ) {
			$response = $this->get_api_client()->get_quick_payment( $quick_payment_id );
			if ( ! is_wp_error( $response ) ) {
				$outcome = $this->evaluate_consent( $order, isset( $response['consent'] ) ? $response['consent'] : array() );
			}
		}

		if ( 'pending' !== $outcome ) {
			return;
		}

		if ( $attempts < self::MAX_STATUS_CHECKS ) {
			$this->schedule_status_check( $order );
		} else {
			$order->add_order_note( __( 'BlinkPay automatic status checks are exhausted. Check the payment in the BlinkPay merchant portal and update the order manually.', 'blinkpay-nz-for-woocommerce' ) );
		}
	}

	/**
	 * Handles a refund using BlinkPay's account_number refund type, the only
	 * type supported. It does not move money: it retrieves the customer's
	 * bank account number so the merchant can transfer the refund from their
	 * own bank. The account number is recorded as an order note, and the
	 * WooCommerce refund becomes the record of that manual transfer.
	 *
	 * @param int        $order_id The order ID.
	 * @param float|null $amount   The refund amount.
	 * @param string     $reason   The refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'blinkpay_refund_failed', __( 'Order not found.', 'blinkpay-nz-for-woocommerce' ) );
		}

		$payment_id = $order->get_meta( '_blinkpay_payment_id' );
		if ( ! $payment_id ) {
			$payment_id = $order->get_transaction_id();
		}
		if ( ! $payment_id ) {
			return new WP_Error( 'blinkpay_refund_failed', __( 'No BlinkPay payment ID is stored against this order.', 'blinkpay-nz-for-woocommerce' ) );
		}

		$amount = (float) $amount;
		if ( $amount <= 0 ) {
			return new WP_Error( 'blinkpay_refund_failed', __( 'The refund amount must be greater than zero.', 'blinkpay-nz-for-woocommerce' ) );
		}

		$client = $this->get_api_client();

		$response = $client->create_refund(
			array(
				'type'       => 'account_number',
				'payment_id' => $payment_id,
			)
		);
		if ( is_wp_error( $response ) || empty( $response['refund_id'] ) ) {
			$detail = is_wp_error( $response ) ? $response->get_error_message() : __( 'No refund ID was returned.', 'blinkpay-nz-for-woocommerce' );
			return new WP_Error( 'blinkpay_refund_failed', $detail );
		}

		$refund = $client->get_refund( $response['refund_id'] );
		if ( is_wp_error( $refund ) || empty( $refund['account_number'] ) ) {
			return new WP_Error( 'blinkpay_refund_failed', __( 'BlinkPay did not return the customer\'s account number. Try the refund again.', 'blinkpay-nz-for-woocommerce' ) );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: bank account number, 3: refund ID, 4: reason */
				__( 'BlinkPay does not transfer refunds automatically. Pay NZD %1$s to the customer\'s account %2$s from your own bank. BlinkPay refund reference: %3$s. %4$s', 'blinkpay-nz-for-woocommerce' ),
				$this->format_amount( $amount ),
				$refund['account_number'],
				$response['refund_id'],
				$reason ? $reason : ''
			)
		);

		return true;
	}

	/**
	 * The URL Blink redirects the customer back to after the gateway journey.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	public function get_gateway_return_url( $order ) {
		return add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			WC()->api_request_url( 'blinkpay_return' )
		);
	}

	/**
	 * Returns a stable idempotency key for one API operation on one order, so
	 * a checkout retry can never double-create on the BlinkPay side.
	 *
	 * @param WC_Order $order   The order.
	 * @param string   $context The operation, e.g. 'quick_payment'.
	 * @return string
	 */
	public function get_idempotency_key( $order, $context ) {
		$meta_key = '_blinkpay_idempotency_' . $context;
		$key      = $order->get_meta( $meta_key );
		if ( ! $key ) {
			$key = wp_generate_uuid4();
			$order->update_meta_data( $meta_key, $key );
			$order->save();
		}

		return $key;
	}

	/**
	 * Builds a PCR (particulars, code, reference) block within the API's
	 * 12-character, restricted-charset limits.
	 *
	 * @param string $particulars The particulars.
	 * @param string $reference   The reference, typically the order number.
	 * @return array
	 */
	public function build_pcr( $particulars, $reference ) {
		$particulars = $this->sanitise_pcr_field( $particulars );
		if ( '' === $particulars ) {
			$particulars = 'Order';
		}

		$pcr = array( 'particulars' => $particulars );

		$reference = $this->sanitise_pcr_field( $reference );
		if ( '' !== $reference ) {
			$pcr['reference'] = $reference;
		}

		return $pcr;
	}

	/**
	 * @param string $value The raw value.
	 * @return string
	 */
	private function sanitise_pcr_field( $value ) {
		$value = preg_replace( "/[^a-zA-Z0-9\- &#?:_\/,.']/", '', (string) $value );

		return substr( $value, 0, 12 );
	}

	/**
	 * Formats an amount as the API's decimal string, e.g. "100.00".
	 *
	 * @param float|string $amount The amount.
	 * @return string
	 */
	public function format_amount( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Marks the order failed with a note.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $note  The failure note.
	 */
	public function fail_order( $order, $note ) {
		if ( $order->has_status( 'failed' ) ) {
			$order->add_order_note( $note );
		} else {
			$order->update_status( 'failed', $note );
		}
	}
}
