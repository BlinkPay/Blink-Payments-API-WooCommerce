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

	// How long the per-order confirmation lock may be held, in seconds. Sized
	// above the return page's worst case — INLINE_POLL_ATTEMPTS requests at
	// the API client's 30-second timeout plus the sleeps between them — so a
	// slow but live confirmation is never barged in on, while a crashed
	// holder's lock still expires within minutes.
	const ORDER_LOCK_TIMEOUT = 180;

	// Deferred WP-Cron checks: tiers of (number of checks, delay in seconds
	// before each). Bank settlement is asynchronous — a payment initiated in
	// the evening may not settle until the SBI operating window reopens the
	// following morning — so polling backs off progressively and the schedule
	// spans 36 hours rather than giving up after half an hour.
	const STATUS_CHECK_SCHEDULE = array(
		array( 10, 60 ),   // Every minute for the first 10 minutes.
		array( 10, 300 ),  // Every 5 minutes until roughly 1 hour.
		array( 10, 1800 ), // Every 30 minutes until roughly 6 hours.
		array( 15, 7200 ), // Every 2 hours until 36 hours.
	);

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
			'callback_url'    => array(
				'title'       => __( 'Callback URL', 'blinkpay-nz-for-woocommerce' ),
				'type'        => 'callback_url',
				'description' => __( 'Register this URL in the BlinkPay client portal under Settings > API before taking payments. Redirect URIs must be whitelisted separately for the sandbox and production environments, so register it for both.', 'blinkpay-nz-for-woocommerce' ),
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
	 * Renders the read-only, copyable callback URL row on the settings screen.
	 * WC_Settings_API dispatches here for the callback_url field type. The
	 * input is unnamed, so saving the settings never persists the value: it is
	 * always derived from the current site URL.
	 *
	 * @param string $key  The field key.
	 * @param array  $data The field definition.
	 * @return string
	 */
	public function generate_callback_url_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<input class="input-text regular-input" type="text" value="<?php echo esc_attr( $this->get_callback_url() ); ?>" readonly="readonly" onfocus="this.select();" />
				<p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * The callback URL field is display-only: never persist a value for it,
	 * so it can never go stale if the site URL changes.
	 *
	 * @param string $key   The field key.
	 * @param mixed  $value The posted value.
	 * @return string
	 */
	public function validate_callback_url_field( $key, $value ) {
		return '';
	}

	/**
	 * Whether the gateway supports a feature. Refunds require the
	 * create:refund and view:refund scopes, so they are only advertised when
	 * the credentials' last token grant included both — otherwise WooCommerce
	 * would offer a Refund button the merchant cannot use. An unknown grant
	 * (no token fetched yet, or no scope in the response) keeps refunds
	 * advertised, and the first attempt reports the missing permission.
	 *
	 * @param string $feature The feature name.
	 * @return bool
	 */
	public function supports( $feature ) {
		if ( 'refunds' === $feature ) {
			$scopes = $this->get_api_client()->get_granted_scopes();

			return null === $scopes
				|| ( in_array( 'create:refund', $scopes, true ) && in_array( 'view:refund', $scopes, true ) );
		}

		return parent::supports( $feature );
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
			if ( is_wp_error( $response ) && $this->is_idempotency_conflict( $response ) ) {
				$this->reset_idempotency_key( $order, 'quick_payment' );
				$order->save();
				$order->add_order_note( __( 'BlinkPay rejected the stored idempotency key because it is already bound to a finished payment attempt. The key has been discarded; the customer can retry checkout and a fresh payment will be created.', 'blinkpay-nz-for-woocommerce' ) );
			}
			if ( is_wp_error( $response ) && $this->is_unregistered_redirect_uri_error( $response ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: callback URL, 2: environment name (sandbox or production) */
						__( 'BlinkPay rejected the redirect URI, most likely because this site\'s callback URL is not whitelisted for your merchant account. Register %1$s in the BlinkPay client portal under Settings > API for the %2$s environment (each environment keeps its own whitelist), then try again.', 'blinkpay-nz-for-woocommerce' ),
						$this->get_callback_url(),
						$this->sandbox ? 'sandbox' : 'production'
					)
				);
			}
			$detail = is_wp_error( $response ) ? $response->get_error_message() : __( 'No redirect URI was returned.', 'blinkpay-nz-for-woocommerce' );
			/* translators: %s: API error detail */
			$order->add_order_note( sprintf( __( 'BlinkPay quick payment creation failed: %s', 'blinkpay-nz-for-woocommerce' ), $detail ) );
			wc_add_notice( __( 'We could not start your BlinkPay payment. Please try again or choose another payment method.', 'blinkpay-nz-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_blinkpay_quick_payment_id', $response['quick_payment_id'] );
		// The key is now permanently bound to this quick payment, so a later
		// attempt (after a cancelled or rejected consent) must mint a fresh one.
		$this->reset_idempotency_key( $order, 'quick_payment' );
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
	 * Takes the per-order confirmation lock. WooCommerce has no order-level
	 * locking primitive — payment_complete() validates only against the order
	 * object already in memory — so a transient mutex serialises the two paths
	 * that can complete or fail an order concurrently: the return page's
	 * inline poll and the deferred cron check. When the transient store is the
	 * options table, set_transient() on an absent key is an insert against a
	 * unique index, so a lost race reports false here rather than both callers
	 * proceeding.
	 *
	 * @param int $order_id The order ID.
	 * @return bool Whether the lock was acquired.
	 */
	protected function acquire_order_lock( $order_id ) {
		$key = 'wc_blinkpay_order_lock_' . $order_id;

		if ( get_transient( $key ) ) {
			return false;
		}

		return (bool) set_transient( $key, time(), self::ORDER_LOCK_TIMEOUT );
	}

	/**
	 * Releases the per-order confirmation lock.
	 *
	 * @param int $order_id The order ID.
	 */
	protected function release_order_lock( $order_id ) {
		delete_transient( 'wc_blinkpay_order_lock_' . $order_id );
	}

	/**
	 * Handles the customer returning from the Blink gateway
	 * (/wc-api/blinkpay_return/). Blink appends `cid` plus, on non-success,
	 * a `status` of cancelled/rejected/timeout/error, or `pending` while the
	 * outcome is unknown. Neither direction is trusted on its own: a return
	 * with no `status` is not confirmation, and a non-success `status` is
	 * replayable from browser history, so the outcome is always confirmed
	 * through the API before the order changes state.
	 */
	public function handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key authenticates this bank redirect.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$status   = isset( $_GET['status'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['status'] ) ), 0, 32 ) : '';
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

		// The inline poll below holds the order for several seconds — ample
		// time for a customer refresh or a due cron check to load the same
		// order and complete it a second time — so confirmation runs under
		// the per-order lock. A contended lock means another process owns
		// the outcome: leave the order untouched and show its current state.
		if ( ! $this->acquire_order_lock( $order_id ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		// Re-read now the lock is held: the snapshot above may predate a
		// completion by the process that has just released the lock, and a
		// stale failure applied over it would show a paid order as failed.
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->is_paid() ) {
			$this->release_order_lock( $order_id );
			wp_safe_redirect( $order ? $order->get_checkout_order_received_url() : wc_get_checkout_url() );
			exit;
		}

		// Any status other than blank or "pending" reports a non-success
		// outcome, including values this version does not recognise. It is a
		// hint, never terminal proof: a stale "cancelled" replayed from
		// browser history must not fail an order whose debit is in flight.
		$reported_failure = '' !== $status && 'pending' !== $status;
		if ( $reported_failure ) {
			/* translators: %s: gateway status parameter */
			$order->add_order_note( sprintf( __( 'The customer returned from the BlinkPay gateway with status %s; confirming the outcome through the API.', 'blinkpay-nz-for-woocommerce' ), $status ) );
		}

		$outcome = $this->confirm_quick_payment( $order );

		// Released before the redirect: exit does not unwind the stack, so
		// anything after wp_safe_redirect() never runs.
		$this->release_order_lock( $order_id );

		if ( 'failed' === $outcome ) {
			wc_add_notice(
				$reported_failure
					? __( 'Your payment was not completed and you have not been charged. Please try again.', 'blinkpay-nz-for-woocommerce' )
					: __( 'Your payment was not completed. Please try again.', 'blinkpay-nz-for-woocommerce' ),
				'error'
			);
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
	 * are resolved by the deferred status checks. A completed payment is only
	 * applied after the paid amount is verified against the order total, and
	 * what the customer was actually charged — including any surcharge Blink
	 * added on the hosted gateway — is recorded against the order.
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
			$amount = isset( $payment['detail']['amount'] ) && is_array( $payment['detail']['amount'] ) ? $payment['detail']['amount'] : array();

			if ( $this->is_underpaid( $order, $amount ) ) {
				$this->flag_underpayment( $order, $payment_id, $amount['total'] );
				return 'pending';
			}

			if ( ! empty( $payment['accepted_reason'] ) ) {
				// Card and bank payments refund differently, so how the
				// payment settled is needed again at refund time.
				$order->update_meta_data( '_blinkpay_accepted_reason', $payment['accepted_reason'] );
			}
			$this->record_charged_amount( $order, $amount );
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
	 * Whether the payment's reported amount is less than the order total. The
	 * request body is filterable by third-party code, so the gateway verifies
	 * what it was actually paid rather than trusting the status alone. The
	 * comparison is on the pre-surcharge total — the price of the goods — and
	 * in whole cents. An amount the API did not report cannot be verified and
	 * does not block completion.
	 *
	 * @param WC_Order $order  The order.
	 * @param array    $amount The payment's amount model.
	 * @return bool
	 */
	private function is_underpaid( $order, array $amount ) {
		if ( ! isset( $amount['total'] ) ) {
			return false;
		}

		return (int) round( (float) $amount['total'] * 100 ) < (int) round( (float) $order->get_total() * 100 );
	}

	/**
	 * Parks an underpaid order on hold for the merchant instead of silently
	 * completing it. Noted once: the deferred status checks keep re-reading
	 * the same payment, and the merchant needs one flag, not one per poll.
	 *
	 * @param WC_Order $order      The order.
	 * @param string   $payment_id The payment ID.
	 * @param string   $paid_total The paid amount reported by the API.
	 */
	private function flag_underpayment( $order, $payment_id, $paid_total ) {
		if ( $order->get_meta( '_blinkpay_underpayment_flagged' ) ) {
			return;
		}

		$order->update_meta_data( '_blinkpay_underpayment_flagged', 'yes' );
		$order->save();

		$note = sprintf(
			/* translators: 1: payment ID, 2: paid amount, 3: order total */
			__( 'BlinkPay reports payment %1$s completed for NZD %2$s, but the order total is NZD %3$s. The order has not been completed automatically; verify the payment in the BlinkPay merchant portal and update the order manually.', 'blinkpay-nz-for-woocommerce' ),
			$payment_id,
			$this->format_amount( $paid_total ),
			$this->format_amount( $order->get_total() )
		);

		if ( $order->has_status( 'on-hold' ) ) {
			$order->add_order_note( $note );
		} else {
			$order->update_status( 'on-hold', $note );
		}
	}

	/**
	 * Records what the customer was actually charged. total_charge is the
	 * instructed amount sent to the bank — the order total plus any surcharge
	 * Blink applied once the customer selected a payment method on the hosted
	 * gateway — so the surcharge is kept as meta and noted for reconciliation.
	 * The caller's payment_complete() saves the order.
	 *
	 * @param WC_Order $order  The order.
	 * @param array    $amount The payment's amount model.
	 */
	private function record_charged_amount( $order, array $amount ) {
		$charged = '';
		if ( isset( $amount['total_charge'] ) ) {
			$charged = $amount['total_charge'];
		} elseif ( isset( $amount['total'] ) ) {
			$charged = $amount['total'];
		}

		if ( '' === $charged ) {
			return;
		}

		$order->update_meta_data( '_blinkpay_total_charge', $charged );

		if ( isset( $amount['surcharge'] ) && (float) $amount['surcharge'] > 0 ) {
			$order->update_meta_data( '_blinkpay_surcharge', $amount['surcharge'] );
			$order->add_order_note(
				sprintf(
					/* translators: 1: total charged, 2: surcharge */
					__( 'The customer was charged NZD %1$s in total, including a NZD %2$s BlinkPay surcharge on top of the order total.', 'blinkpay-nz-for-woocommerce' ),
					$this->format_amount( $charged ),
					$this->format_amount( $amount['surcharge'] )
				)
			);
		}
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
	 * Schedules the order's next deferred status check, spaced according to
	 * how many checks have already run. The check-then-schedule here is not
	 * atomic, but the callers that could race — the return page's poll and
	 * the cron check — are serialised by the per-order lock, and WP core
	 * itself refuses a duplicate single event within ten minutes of an
	 * identical one, so one order cannot grow two parallel polling chains.
	 *
	 * @param WC_Order $order The order.
	 * @return bool Whether a check is scheduled; false once the schedule is exhausted.
	 */
	public function schedule_status_check( $order ) {
		$delay = $this->get_status_check_delay( (int) $order->get_meta( '_blinkpay_status_checks' ) );
		if ( false === $delay ) {
			return false;
		}

		if ( ! wp_next_scheduled( self::STATUS_CHECK_HOOK, array( $order->get_id() ) ) ) {
			wp_schedule_single_event( time() + $delay, self::STATUS_CHECK_HOOK, array( $order->get_id() ) );
		}

		return true;
	}

	/**
	 * The delay before an order's next status check.
	 *
	 * @param int $completed_checks How many checks have already run.
	 * @return int|false Seconds until the next check, or false when the schedule is exhausted.
	 */
	public function get_status_check_delay( $completed_checks ) {
		foreach ( self::STATUS_CHECK_SCHEDULE as $tier ) {
			list( $checks, $delay ) = $tier;
			if ( $completed_checks < $checks ) {
				return $delay;
			}
			$completed_checks -= $checks;
		}

		return false;
	}

	/**
	 * WP-Cron callback: re-checks a not-yet-terminal payment. Runs under the
	 * per-order lock so it cannot complete or fail an order a return request
	 * is confirming at the same moment. A contended lock defers to the
	 * holder: the check is rescheduled without advancing the attempt counter,
	 * because no check ran.
	 *
	 * @param int $order_id The order ID.
	 */
	public function check_payment_status( $order_id ) {
		if ( ! $this->acquire_order_lock( $order_id ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$this->schedule_status_check( $order );
			}
			return;
		}

		$this->run_status_check( $order_id );

		$this->release_order_lock( $order_id );
	}

	/**
	 * The body of a deferred status check. The caller holds the per-order
	 * lock, so the order loaded here is a fresh read no concurrent
	 * confirmation can move under us.
	 *
	 * @param int $order_id The order ID.
	 */
	private function run_status_check( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order
			|| $this->id !== $order->get_payment_method()
			|| $order->is_paid()
			|| $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			return;
		}

		// A failed order whose quick payment may still be in flight is not
		// abandoned: a debit initiated before the failure can still settle,
		// and payment_complete() recovers the order to paid if it does.
		if ( $order->has_status( 'failed' ) && ! $order->get_meta( '_blinkpay_quick_payment_id' ) ) {
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

		if ( ! $this->schedule_status_check( $order ) ) {
			$order->add_order_note( __( 'BlinkPay automatic status checks are exhausted: the payment has not reached a terminal status after 36 hours. Check the payment in the BlinkPay merchant portal and update the order manually.', 'blinkpay-nz-for-woocommerce' ) );
		}
	}

	/**
	 * Handles a refund through the BlinkPay refunds API. How the payment
	 * settled (its accepted_reason) decides the path: a card payment is
	 * refunded through the card network with a money-moving type —
	 * full_refund when the whole order total is being refunded,
	 * partial_refund (carrying the amount) otherwise — while a bank payment,
	 * or a payment from before the reason was recorded, uses the
	 * account_number type; see process_account_number_refund().
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

		if ( 'card_network_accepted' !== $order->get_meta( '_blinkpay_accepted_reason' ) ) {
			return $this->process_account_number_refund( $order, $client, $payment_id, $amount, $reason );
		}

		// Refunding the whole order total refunds the whole payment; anything
		// less is a partial refund and must carry the amount to the API.
		$is_full = $this->format_amount( $amount ) === $this->format_amount( $order->get_total() );

		$payload = array(
			'type'       => $is_full ? 'full_refund' : 'partial_refund',
			'payment_id' => $payment_id,
			'pcr'        => $this->build_pcr( $this->pcr_particulars, $order->get_order_number() ),
		);
		if ( ! $is_full ) {
			$payload['amount'] = array(
				'currency' => 'NZD',
				'total'    => $this->format_amount( $amount ),
			);
		}

		$response = $client->create_refund( $payload );

		if ( is_wp_error( $response ) || empty( $response['refund_id'] ) ) {
			return $this->refund_creation_error( $response );
		}

		return $this->finalise_money_transfer_refund( $order, $client, $amount, $reason, $response['refund_id'] );
	}

	/**
	 * Builds the WP_Error for a refund creation that returned no refund ID.
	 * A 403 names the missing permissions instead of surfacing the raw API
	 * message, and an empty 2xx response warns the merchant to check the
	 * portal before retrying, in case the refund was created.
	 *
	 * @param array|WP_Error $response The create_refund() response.
	 * @return WP_Error
	 */
	private function refund_creation_error( $response ) {
		if ( ! is_wp_error( $response ) ) {
			return new WP_Error( 'blinkpay_refund_failed', __( 'BlinkPay did not return a refund ID. Check the BlinkPay merchant portal for the refund before retrying.', 'blinkpay-nz-for-woocommerce' ) );
		}

		$data = $response->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) && 403 === (int) $data['status'] ) {
			return new WP_Error( 'blinkpay_refund_failed', __( 'BlinkPay declined the refund because this client is missing the refund permissions (create:refund and view:refund). Contact BlinkPay to enable refunds for your merchant account.', 'blinkpay-nz-for-woocommerce' ) );
		}

		return new WP_Error( 'blinkpay_refund_failed', $response->get_error_message() );
	}

	/**
	 * Confirms a money-transfer refund after creation. A 201 does not mean
	 * the refund has been processed — the status must be checked through the
	 * GET endpoint — so a refund already reported failed rejects the
	 * WooCommerce refund rather than recording money that never moved, and
	 * anything else is noted with what the merchant must still do: authorise
	 * it at the consent redirect if the bank requires that, or verify it
	 * completes in the merchant portal.
	 *
	 * @param WC_Order               $order     The order.
	 * @param WC_BlinkPay_API_Client $client    The API client.
	 * @param float                  $amount    The refund amount.
	 * @param string                 $reason    The refund reason.
	 * @param string                 $refund_id The created refund ID.
	 * @return bool|WP_Error
	 */
	private function finalise_money_transfer_refund( $order, $client, $amount, $reason, $refund_id ) {
		$refund = $client->get_refund( $refund_id );
		$status = ! is_wp_error( $refund ) && isset( $refund['status'] ) ? $refund['status'] : '';

		if ( 'failed' === $status ) {
			/* translators: %s: refund ID */
			return new WP_Error( 'blinkpay_refund_failed', sprintf( __( 'BlinkPay reported refund %s as failed; no money has moved.', 'blinkpay-nz-for-woocommerce' ), $refund_id ) );
		}

		if ( 'completed' === $status ) {
			/* translators: 1: amount, 2: refund ID, 3: reason */
			$note = __( 'BlinkPay refund of NZD %1$s completed; the money has been transferred back to the account the customer paid from. BlinkPay refund reference: %2$s. %3$s', 'blinkpay-nz-for-woocommerce' );
		} else {
			/* translators: 1: amount, 2: refund ID, 3: reason */
			$note = __( 'BlinkPay accepted the refund request of NZD %1$s and is transferring the money back to the account the customer paid from. Verify it reaches completed in the BlinkPay merchant portal. BlinkPay refund reference: %2$s. %3$s', 'blinkpay-nz-for-woocommerce' );
		}

		$order->add_order_note( sprintf( $note, $this->format_amount( $amount ), $refund_id, $reason ? $reason : '' ) );

		$consent_redirect = ! is_wp_error( $refund ) && ! empty( $refund['detail']['consent_redirect'] ) ? $refund['detail']['consent_redirect'] : '';
		if ( $consent_redirect ) {
			/* translators: %s: consent redirect URI */
			$order->add_order_note( sprintf( __( 'This refund needs your authorisation: visit %s to authorise the refund payment from your bank.', 'blinkpay-nz-for-woocommerce' ), $consent_redirect ) );
		}

		return true;
	}

	/**
	 * Refunds a bank-settled payment with BlinkPay's account_number refund
	 * type, which does not move money: it retrieves the customer's bank
	 * account number so the merchant can transfer the refund from their own
	 * bank. The account number is recorded as a private order note, and a
	 * customer-visible note makes the outstanding manual transfer obvious on
	 * the order rather than leaving the obligation buried in the private note.
	 *
	 * @param WC_Order               $order      The order.
	 * @param WC_BlinkPay_API_Client $client     The API client.
	 * @param string                 $payment_id The BlinkPay payment ID.
	 * @param float                  $amount     The refund amount.
	 * @param string                 $reason     The refund reason.
	 * @return bool|WP_Error
	 */
	private function process_account_number_refund( $order, $client, $payment_id, $amount, $reason ) {
		$response = $client->create_refund(
			array(
				'type'       => 'account_number',
				'payment_id' => $payment_id,
			)
		);
		if ( is_wp_error( $response ) || empty( $response['refund_id'] ) ) {
			return $this->refund_creation_error( $response );
		}

		$refund_id = $response['refund_id'];

		// The refund exists from here on, so nothing below may return an
		// error that invites creating a second one. A refund can legitimately
		// still be processing on the first read — with no account number yet —
		// so poll briefly and defer to the merchant portal if it stays that way.
		$refund = array();
		for ( $attempt = 0; $attempt < self::INLINE_POLL_ATTEMPTS; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( self::INLINE_POLL_DELAY );
			}

			$result = $client->get_refund( $refund_id );
			if ( is_wp_error( $result ) ) {
				continue;
			}

			$refund = $result;
			if ( ! empty( $refund['account_number'] ) || ( isset( $refund['status'] ) && 'failed' === $refund['status'] ) ) {
				break;
			}
		}

		if ( isset( $refund['status'] ) && 'failed' === $refund['status'] ) {
			/* translators: %s: refund ID */
			return new WP_Error( 'blinkpay_refund_failed', sprintf( __( 'BlinkPay reported refund %s as failed; no account number was retrieved.', 'blinkpay-nz-for-woocommerce' ), $refund_id ) );
		}

		if ( empty( $refund['account_number'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: refund ID, 3: reason */
					__( 'This payment was settled by bank transfer, so BlinkPay\'s refund does not move money, and the refund is still processing. Retrieve the customer\'s account number for refund %2$s from the BlinkPay merchant portal, then pay NZD %1$s from your own bank. %3$s', 'blinkpay-nz-for-woocommerce' ),
					$this->format_amount( $amount ),
					$refund_id,
					$reason ? $reason : ''
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: bank account number, 3: refund ID, 4: reason */
					__( 'This payment was settled by bank transfer, so BlinkPay\'s refund does not move money. Pay NZD %1$s to the customer\'s account %2$s from your own bank. BlinkPay refund reference: %3$s. %4$s', 'blinkpay-nz-for-woocommerce' ),
					$this->format_amount( $amount ),
					$refund['account_number'],
					$refund_id,
					$reason ? $reason : ''
				)
			);
		}

		// The customer sees (and is emailed) that the refund arrives by
		// manual bank transfer; the account number stays in the private note.
		$order->add_order_note(
			sprintf(
				/* translators: %s: amount */
				__( 'Your refund of NZD %s will be paid to your bank account by bank transfer.', 'blinkpay-nz-for-woocommerce' ),
				$this->format_amount( $amount )
			),
			1
		);

		return true;
	}

	/**
	 * The base callback URL for this site, which the merchant must whitelist
	 * in the BlinkPay client portal for each environment. Redirect URI
	 * matching is prefix-based, so this one URL covers every order.
	 *
	 * @return string
	 */
	public function get_callback_url() {
		return WC()->api_request_url( 'blinkpay_return' );
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
			$this->get_callback_url()
		);
	}

	/**
	 * Returns the idempotency key for the current payment attempt of one API
	 * operation. The key is reused while the attempt is unresolved, so a lost
	 * response can be retried without double-creating, and is discarded with
	 * reset_idempotency_key() once it is bound to a payment.
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
	 * Discards the stored idempotency key for one API operation so the next
	 * get_idempotency_key() call mints a fresh one. The API binds a key
	 * permanently to the payment it creates, so a spent key could never
	 * produce a new payment for a retried order. The caller is responsible
	 * for saving the order.
	 *
	 * @param WC_Order $order   The order.
	 * @param string   $context The operation, e.g. 'quick_payment'.
	 */
	public function reset_idempotency_key( $order, $context ) {
		$order->delete_meta_data( '_blinkpay_idempotency_' . $context );
	}

	/**
	 * Whether an API error is the 409 conflict returned when an idempotency
	 * key is reused after being bound to a payment in a terminal state
	 * (error code BP710).
	 *
	 * @param WP_Error $error The API error.
	 * @return bool
	 */
	private function is_idempotency_conflict( $error ) {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['status'] ) && 409 === $data['status'];
	}

	/**
	 * Whether an API error looks like a consent-creation rejection for an
	 * unregistered redirect URI. The API reports it as a client-error
	 * validation failure whose detail names the redirect URI, so the match is
	 * heuristic — a 4xx mentioning "redirect" — and the note it produces only
	 * names the likely cause.
	 *
	 * @param WP_Error $error The API error.
	 * @return bool
	 */
	private function is_unregistered_redirect_uri_error( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		return $status >= 400 && $status < 500
			&& false !== stripos( $error->get_error_message(), 'redirect' );
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
