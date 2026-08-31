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

	// How long the per-order lock may be held, in seconds, before another
	// process may break it. Sized above the worst-case holder — the
	// account-number refund path's create plus INLINE_POLL_ATTEMPTS
	// retrievals at the API client's 30-second timeout, plus the pauses
	// between them — so a slow but live operation is never barged in on,
	// while a crashed holder's lock is broken by the next acquirer within
	// minutes. A holder that does overrun (payment_complete() sends order
	// emails synchronously, so a slow mail relay stretches the budget) loses
	// only its own lock: release is token-checked, so it can never free a
	// successor's.
	const ORDER_LOCK_TIMEOUT = 300;

	// How soon a deferred check that lost the lock retries. No check ran, so
	// it must not wait out a whole tier delay — 2 hours in the last tier.
	const ORDER_LOCK_RETRY_DELAY = 60;

	// How many consecutive lock-contended retries a deferred check may make
	// before giving up — about half an hour at ORDER_LOCK_RETRY_DELAY. The
	// lock breaks after ORDER_LOCK_TIMEOUT, so exhausting this bound means
	// something is systematically wrong, and the merchant is pointed at
	// manual verification rather than rescheduling forever.
	const ORDER_LOCK_MAX_RETRIES = 30;

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
		$this->method_description = __( 'Accept New Zealand bank payments through BlinkPay open banking. Orders are paid with Blink PayNow quick payments. If card payments are enabled for your BlinkPay merchant account, the hosted gateway also offers card, and any BlinkPay surcharge is shown to and authorised by the customer there.', 'blinkpay-nz-for-woocommerce' );
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
				'default'     => __( 'Pay securely from your New Zealand bank account, or by card where available.', 'blinkpay-nz-for-woocommerce' ),
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
	 * hosted gateway URL. The whole decide-and-create sequence runs under the
	 * per-order lock: two concurrent submissions — a double-click, a browser
	 * retry on a slow POST, two order-pay tabs — would otherwise both read
	 * the order before either persists an idempotency key, mint two keys and
	 * create two quick payments, one of them orphaned with nothing tracking
	 * its debit.
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

		$lock = $this->acquire_order_lock( $order_id );
		if ( ! $lock ) {
			wc_add_notice( __( 'Another BlinkPay operation on this order is already in progress. Please wait a moment, then check the order status before paying again.', 'blinkpay-nz-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Released in a finally: order notes and payment_complete() run
		// third-party hooks, and an exception in one must not leak the lock
		// for the full timeout.
		try {
			// Re-read now the lock is held, as on the return page: the
			// snapshot above may predate a completion by the previous holder.
			$order = wc_get_order( $order_id );

			if ( ! $order || $order->is_paid() ) {
				return array(
					'result'   => 'success',
					'redirect' => $order ? $order->get_checkout_order_received_url() : wc_get_checkout_url(),
				);
			}

			$result = $this->resume_existing_quick_payment( $order );
			if ( null === $result ) {
				$result = $this->start_quick_payment( $order );
			}

			return $result;
		} finally {
			$this->release_order_lock( $order_id, $lock );
		}
	}

	/**
	 * Resolves a retried checkout against the order's existing quick payment
	 * before any new one may be created. The order-pay link stays live while
	 * the order is pending, so a customer who authorised at their bank but
	 * never returned to the site can pay the same order again; a stored quick
	 * payment ID means a debit may already be in flight, and it is only safe
	 * to create a fresh payment once the previous attempt is confirmed
	 * terminal with no money moved. The caller holds the per-order lock and
	 * has read the order under it.
	 *
	 * @param WC_Order $order The order, read under the lock.
	 * @return array|null A process_payment() result, or null when no quick
	 *                    payment exists or the previous one ended with no
	 *                    money moved, so a fresh payment may be created.
	 */
	private function resume_existing_quick_payment( $order ) {
		if ( ! $order->get_meta( '_blinkpay_quick_payment_id' ) ) {
			return null;
		}

		$outcome = $this->confirm_quick_payment( $order );

		if ( 'paid' === $outcome ) {
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		if ( 'pending' === $outcome ) {
			// The debit may still settle — confirm_quick_payment() has parked
			// the order and scheduled the deferred checks — so no second
			// payment may be created for it.
			wc_add_notice( __( 'A payment for this order is already awaiting confirmation from your bank, so a new payment has not been started. The order will update automatically once the outcome is known.', 'blinkpay-nz-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// The previous attempt is confirmed terminal with no money moved — a
		// rejected, revoked or timed-out consent, or a bank-rejected payment
		// — so a fresh quick payment is safe to create over it.
		$this->reset_payment_attempt_state( $order );

		return null;
	}

	/**
	 * Discards the previous attempt's per-attempt state so a fresh quick
	 * payment starts clean. The payment ID is recorded even for a rejected
	 * payment, the check counter may already be exhausted and the mismatch
	 * flag is per payment — left behind, they would misdirect a later refund
	 * at the dead payment, deny the new debit its deferred checks and
	 * short-circuit its first poll. The quick payment ID itself is discarded
	 * too: it belongs to the dead attempt, and were it left behind, a fresh
	 * creation that fails would leave the order looking like it still had a
	 * live attempt — exempt from the unpaid-order sweep with nothing polling
	 * it.
	 *
	 * @param WC_Order $order The order.
	 */
	private function reset_payment_attempt_state( $order ) {
		$order->delete_meta_data( '_blinkpay_quick_payment_id' );
		$order->delete_meta_data( '_blinkpay_payment_id' );
		$order->delete_meta_data( '_blinkpay_accepted_reason' );
		$order->delete_meta_data( '_blinkpay_status_checks' );
		$order->delete_meta_data( '_blinkpay_amount_mismatch_flagged' );
		$order->delete_meta_data( '_blinkpay_lock_retries' );
		$order->save();
	}

	/**
	 * Creates a gateway-flow quick payment and redirects the customer to it.
	 *
	 * @param WC_Order $order The order.
	 * @return array
	 */
	private function start_quick_payment( $order ) {
		$payload = array(
			'flow'   => array(
				'detail' => array(
					'type'         => 'gateway',
					'redirect_uri' => $this->get_gateway_return_url( $order ),
				),
			),
			'amount' => array(
				'currency' => 'NZD',
				'total'    => $this->format_amount( $order->get_total() ),
			),
			'pcr'    => $this->build_pcr( $this->pcr_particulars, $order->get_order_number(), (string) $order->get_id() ),
		);

		// Sent only when a genuinely per-customer value exists: hashing a
		// blank one would send the same constant for every such order, making
		// them all look like one customer to Blink's risk and velocity checks.
		$identifier = $this->get_customer_identifier( $order );
		if ( '' !== $identifier ) {
			$payload['hashed_customer_identifier'] = hash( 'sha256', $identifier );
		}

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

		// A retried order was left failed by its confirmed previous outcome;
		// the fresh quick payment now exists, so it returns to pending — the
		// state of a checkout in progress. Only after creation succeeds: a
		// failed creation must leave the order failed, not advertising an
		// attempt that does not exist.
		if ( $order->has_status( 'failed' ) ) {
			$order->update_status( 'pending', __( 'The customer is retrying the payment; a fresh BlinkPay quick payment has been created over the failed attempt.', 'blinkpay-nz-for-woocommerce' ) );
		}
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
	 * Takes the per-order lock that serialises everything able to complete,
	 * fail or refund an order concurrently. WooCommerce has no order-level
	 * locking primitive — payment_complete() validates only against the order
	 * object already in memory — and transients cannot back one: on the
	 * options-table backend set_transient() is a non-atomic existence check
	 * before an insert-or-update, and under a persistent object cache it is an
	 * unconditional overwrite, so two racers would both acquire. The lock is
	 * therefore an INSERT IGNORE straight into the options table, where the
	 * unique key on option_name makes the loser's insert affect no rows,
	 * atomically, regardless of any object cache — the technique of
	 * WP_Upgrader::create_lock(), inlined so the front end and cron need not
	 * load wp-admin's upgrader. A lock older than ORDER_LOCK_TIMEOUT is
	 * broken, so a crashed holder cannot jam the order for good, and the
	 * stored value is an ownership token whose leading digits are the
	 * acquisition time.
	 *
	 * @param int $order_id The order ID.
	 * @return string|false The token to pass to release_order_lock(), or
	 *                      false when another process holds the lock.
	 */
	protected function acquire_order_lock( $order_id ) {
		$token = time() . ':' . wp_generate_uuid4();

		if ( $this->insert_order_lock( $order_id, $token ) ) {
			return $token;
		}

		// The holder may have crashed: break the lock if it has expired — the
		// token-checked delete cannot break a fresh lock that has replaced it
		// — then contend once more for the freed slot.
		$held = $this->read_order_lock( $order_id );
		if ( false !== $held && (int) $held <= time() - self::ORDER_LOCK_TIMEOUT ) {
			$this->delete_order_lock( $order_id, $held );

			if ( $this->insert_order_lock( $order_id, $token ) ) {
				return $token;
			}
		}

		return false;
	}

	/**
	 * Releases the per-order lock — but only the caller's own: the
	 * token-checked delete means a holder whose expired lock was broken and
	 * re-acquired by another process cannot free that successor's lock.
	 *
	 * @param int    $order_id The order ID.
	 * @param string $token    The token acquire_order_lock() returned.
	 */
	protected function release_order_lock( $order_id, $token ) {
		$this->delete_order_lock( $order_id, $token );
	}

	/**
	 * @param int $order_id The order ID.
	 * @return string The lock's option name.
	 */
	private function order_lock_option( $order_id ) {
		return 'wc_blinkpay_order_' . $order_id . '.lock';
	}

	/**
	 * Inserts the lock row. Exactly one of any number of concurrent inserts
	 * affects a row; the rest are ignored by the unique key on option_name.
	 *
	 * @param int    $order_id The order ID.
	 * @param string $token    The ownership token to store.
	 * @return bool Whether this caller's insert won.
	 */
	private function insert_order_lock( $order_id, $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the point of the lock: get_option()/add_option() cannot contend atomically.
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				$this->order_lock_option( $order_id ),
				$token
			)
		);
	}

	/**
	 * @param int $order_id The order ID.
	 * @return string|false The held token, or false when the lock is free.
	 */
	private function read_order_lock( $order_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read straight from the table, past any stale object cache.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$this->order_lock_option( $order_id )
			)
		);

		return null === $value ? false : $value;
	}

	/**
	 * Deletes the lock row only while it still holds the given token, as one
	 * atomic compare-and-delete.
	 *
	 * @param int    $order_id The order ID.
	 * @param string $token    The token the row must still hold.
	 */
	private function delete_order_lock( $order_id, $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- delete_option() cannot compare-and-delete.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$this->order_lock_option( $order_id ),
				$token
			)
		);
	}

	/**
	 * Waits between polling attempts. A seam so tests can skip real sleeps.
	 *
	 * @param int $seconds How long to pause.
	 */
	protected function pause( $seconds ) {
		sleep( $seconds );
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
		$lock = $this->acquire_order_lock( $order_id );
		if ( ! $lock ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		// The lock is released in a finally — a hook exception must not leak
		// it — and before the redirect: exit does not unwind the stack, so a
		// release placed after wp_safe_redirect() would never run.
		try {
			// Re-read now the lock is held: the snapshot above may predate a
			// completion by the process that has just released the lock, and
			// a stale failure applied over it would show a paid order as
			// failed.
			$order = wc_get_order( $order_id );

			if ( ! $order || $order->is_paid() ) {
				$destination = $order ? $order->get_checkout_order_received_url() : wc_get_checkout_url();
			} else {
				// Any status other than blank or "pending" reports a
				// non-success outcome, including values this version does not
				// recognise. It is a hint, never terminal proof: a stale
				// "cancelled" replayed from browser history must not fail an
				// order whose debit is in flight.
				if ( '' !== $status && 'pending' !== $status ) {
					/* translators: %s: gateway status parameter */
					$order->add_order_note( sprintf( __( 'The customer returned from the BlinkPay gateway with status %s; confirming the outcome through the API.', 'blinkpay-nz-for-woocommerce' ), $status ) );
				}

				if ( 'failed' === $this->confirm_quick_payment( $order ) ) {
					// Keyed off the API-confirmed outcome, not the replayable
					// URL parameter: every confirmed failure path means no
					// money moved, so the message stays accurate by
					// construction.
					wc_add_notice( __( 'Your payment was not completed and you have not been charged. Please try again.', 'blinkpay-nz-for-woocommerce' ), 'error' );
					$destination = $order->get_checkout_payment_url();
				} else {
					$destination = $order->get_checkout_order_received_url();
				}
			}
		} finally {
			$this->release_order_lock( $order_id, $lock );
		}

		wp_safe_redirect( $destination );
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
				$this->pause( self::INLINE_POLL_DELAY );
			}

			$response = $client->get_quick_payment( $quick_payment_id );
			if ( is_wp_error( $response ) ) {
				continue;
			}

			$outcome = $this->evaluate_consent( $order, isset( $response['consent'] ) ? $response['consent'] : array() );
			if ( 'pending' !== $outcome ) {
				return $outcome;
			}

			// A flagged amount mismatch has already parked the order with its
			// own note; re-polling reads the same payment, and the generic
			// "not yet confirmed" note below would contradict that flag.
			if ( $order->get_meta( '_blinkpay_amount_mismatch_flagged' ) ) {
				$this->schedule_status_check( $order );
				return 'pending';
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
		$status = isset( $consent['status'] ) ? $consent['status'] : '';

		$payment = $this->select_reportable_payment( isset( $consent['payments'] ) && is_array( $consent['payments'] ) ? $consent['payments'] : array() );
		if ( null !== $payment ) {
			$outcome = $this->apply_payment_result( $order, $payment, $status );
			if ( 'pending' !== $outcome ) {
				return $outcome;
			}
		}

		// A terminal consent status fails the order — but only when its
		// payment records confirm no money moved. Every caller treats
		// 'failed' as exactly that: the retry path creates a fresh quick
		// payment over it, replacing the stored ID so nothing would ever
		// poll the old debit again. A terminal consent over a payment that
		// is neither settled nor rejected is anomalous (the spec promises
		// the combination does not occur), and money possibly in motion must
		// keep the order pending for the deferred checks to resolve, even at
		// the cost of polling out the full 36-hour schedule.
		if ( in_array( $status, array( 'Rejected', 'Revoked', 'GatewayTimeout' ), true ) ) {
			if ( ! $this->consent_moved_no_money( $consent ) ) {
				return 'pending';
			}

			/* translators: %s: consent status */
			$this->fail_order( $order, sprintf( __( 'The BlinkPay consent ended with status %s.', 'blinkpay-nz-for-woocommerce' ), $status ) );
			return 'failed';
		}

		return 'pending';
	}

	/**
	 * Whether the consent's payment records confirm that no money moved:
	 * there are no records at all — a terminal consent that was never
	 * debited — or every record is Rejected. A payment in any other status
	 * may still settle, or already has, so failure may not be declared over
	 * it.
	 *
	 * @param array $consent The consent model from the API.
	 * @return bool
	 */
	private function consent_moved_no_money( array $consent ) {
		$payments = isset( $consent['payments'] ) && is_array( $consent['payments'] ) ? $consent['payments'] : array();

		foreach ( $payments as $payment ) {
			if ( ! is_array( $payment ) || ! isset( $payment['status'] ) || 'Rejected' !== $payment['status'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Picks which of a consent's payments to apply to the order. A one-off
	 * quick payment carries at most one, but the array shape allows more, so
	 * defensively prefer the first settled payment — money moved outranks
	 * everything — then the first in-flight or unrecognised one, and a
	 * rejected payment only when nothing else exists: applying a rejection
	 * while a sibling payment is live would declare "no money moved" over a
	 * debit that may still settle.
	 *
	 * @param array $payments The consent's payment models.
	 * @return array|null The payment to apply, or null when there are none.
	 */
	private function select_reportable_payment( array $payments ) {
		$payments = array_values( array_filter( $payments, 'is_array' ) );
		if ( ! $payments ) {
			return null;
		}

		foreach ( $payments as $payment ) {
			if ( isset( $payment['status'] ) && 'AcceptedSettlementCompleted' === $payment['status'] ) {
				return $payment;
			}
		}

		foreach ( $payments as $payment ) {
			if ( ! isset( $payment['status'] ) || 'Rejected' !== $payment['status'] ) {
				return $payment;
			}
		}

		return $payments[0];
	}

	/**
	 * Applies a payment's state to the order. Only AcceptedSettlementCompleted
	 * counts as paid; AcceptedSettlementInProcess and Pending stay pending and
	 * are resolved by the deferred status checks. A completed payment is only
	 * applied after the paid amount is verified against the order total, and
	 * what the customer was actually charged — including any surcharge Blink
	 * added on the hosted gateway — is recorded against the order.
	 *
	 * @param WC_Order $order          The order.
	 * @param array    $payment        The payment model from the API.
	 * @param string   $consent_status The parent consent's status, used to
	 *                                 attribute a rejected payment correctly.
	 * @return string One of 'paid', 'failed' or 'pending'.
	 */
	public function apply_payment_result( $order, array $payment, $consent_status = '' ) {
		$status     = isset( $payment['status'] ) ? $payment['status'] : '';
		$payment_id = isset( $payment['payment_id'] ) ? $payment['payment_id'] : '';

		if ( $payment_id && ! $order->get_meta( '_blinkpay_payment_id' ) ) {
			$order->update_meta_data( '_blinkpay_payment_id', $payment_id );
			$order->save();
		}

		if ( 'AcceptedSettlementCompleted' === $status ) {
			$amount = isset( $payment['detail']['amount'] ) && is_array( $payment['detail']['amount'] ) ? $payment['detail']['amount'] : array();

			// A payment settling after its order was cancelled — or an order
			// already flagged as such — is surfaced for the merchant, never
			// completed automatically: WooCommerce released any held stock at
			// cancellation, so completing would claim goods the shop may no
			// longer have. Checked before the amount: whatever was paid, the
			// money moved after cancellation, and that warning must not be
			// hidden behind a mismatch flag that would also re-fail on every
			// later poll.
			if ( $order->has_status( 'cancelled' ) || $order->get_meta( '_blinkpay_settled_after_cancellation' ) ) {
				$this->flag_settled_payment_on_cancelled_order( $order, $payment, $amount );
				return 'paid';
			}

			if ( ! $this->is_amount_verified( $order, $amount ) ) {
				$this->flag_amount_mismatch( $order, $payment_id, $amount );
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
			// A quick payment also carries a Rejected payment record when the
			// consent itself was declined, so the consent status decides which
			// party the note blames — a decline sent to bank support helps
			// no one.
			if ( 'Rejected' === $consent_status ) {
				/* translators: %s: payment ID */
				$this->fail_order( $order, sprintf( __( 'The BlinkPay consent was declined before the payment was authorised — typically the customer cancelling at the gateway or their bank — so payment %s was never made and no money moved.', 'blinkpay-nz-for-woocommerce' ), $payment_id ) );
			} else {
				/* translators: %s: payment ID */
				$this->fail_order( $order, sprintf( __( 'BlinkPay payment %s was rejected by the bank.', 'blinkpay-nz-for-woocommerce' ), $payment_id ) );
			}
			return 'failed';
		}

		return 'pending';
	}

	/**
	 * Whether the payment's reported amount matches the order total exactly.
	 * The request body is filterable by third-party code, so the gateway
	 * verifies what it was actually paid rather than trusting the status
	 * alone — and an inflated amount is as wrong as a short one: a legitimate
	 * surcharge lives in total_charge, never in total. The comparison is on
	 * the pre-surcharge total — the price of the goods — and in whole cents.
	 * The API reports an amount for every completed payment, so a missing one
	 * is anomalous and fails the verification: an unverifiable payment is
	 * parked for the merchant, never trusted.
	 *
	 * @param WC_Order $order  The order.
	 * @param array    $amount The payment's amount model.
	 * @return bool
	 */
	private function is_amount_verified( $order, array $amount ) {
		if ( ! isset( $amount['total'] ) ) {
			return false;
		}

		return (int) round( (float) $amount['total'] * 100 ) === (int) round( (float) $order->get_total() * 100 );
	}

	/**
	 * Parks an order paid for the wrong — or an unreported — amount on hold
	 * for the merchant instead of silently completing it. Noted once: the
	 * deferred status checks keep re-reading the same payment, and the
	 * merchant needs one flag, not one per poll.
	 *
	 * @param WC_Order $order      The order.
	 * @param string   $payment_id The payment ID.
	 * @param array    $amount     The payment's amount model.
	 */
	private function flag_amount_mismatch( $order, $payment_id, array $amount ) {
		if ( $order->get_meta( '_blinkpay_amount_mismatch_flagged' ) ) {
			return;
		}

		$order->update_meta_data( '_blinkpay_amount_mismatch_flagged', 'yes' );
		$order->save();

		if ( isset( $amount['total'] ) ) {
			$note = sprintf(
				/* translators: 1: payment ID, 2: paid amount, 3: order total */
				__( 'BlinkPay reports payment %1$s completed for NZD %2$s, but the order total is NZD %3$s. The order has not been completed automatically; verify the payment in the BlinkPay merchant portal and update the order manually.', 'blinkpay-nz-for-woocommerce' ),
				$payment_id,
				$this->format_amount( $amount['total'] ),
				$this->format_amount( $order->get_total() )
			);
		} else {
			$note = sprintf(
				/* translators: 1: payment ID, 2: order total */
				__( 'BlinkPay reports payment %1$s completed but did not report the amount paid, so it cannot be verified against the order total of NZD %2$s. The order has not been completed automatically; verify the payment in the BlinkPay merchant portal and update the order manually.', 'blinkpay-nz-for-woocommerce' ),
				$payment_id,
				$this->format_amount( $order->get_total() )
			);
		}

		if ( $order->has_status( 'on-hold' ) ) {
			$order->add_order_note( $note );
		} else {
			$order->update_status( 'on-hold', $note );
		}
	}

	/**
	 * Surfaces a payment that settled after its order was cancelled —
	 * typically by WooCommerce's unpaid-order sweep between two deferred
	 * checks. The money has moved but the order no longer accounts for it,
	 * so the order is parked on hold with a prominent note for the merchant
	 * instead of being completed automatically or discarded silently. Noted
	 * once: a customer revisiting the return URL re-reads the same payment,
	 * and the parked order must stay parked until the merchant acts.
	 *
	 * @param WC_Order $order   The order.
	 * @param array    $payment The payment model from the API.
	 * @param array    $amount  The payment's amount model.
	 */
	private function flag_settled_payment_on_cancelled_order( $order, array $payment, array $amount ) {
		if ( $order->get_meta( '_blinkpay_settled_after_cancellation' ) ) {
			return;
		}

		if ( ! empty( $payment['accepted_reason'] ) ) {
			// Recorded as on the completion path: the merchant's likely next
			// step is a refund, which needs how the payment settled.
			$order->update_meta_data( '_blinkpay_accepted_reason', $payment['accepted_reason'] );
		}
		$this->record_charged_amount( $order, $amount );
		$order->update_meta_data( '_blinkpay_settled_after_cancellation', 'yes' );
		$order->save();

		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: 1: payment ID, 2: paid amount */
				__( 'BlinkPay payment %1$s settled for NZD %2$s after this order had already been cancelled — most likely by WooCommerce cancelling unpaid orders after the stock hold window — and any held stock was released. The customer has been charged. The order has been placed on hold: check stock and complete the order manually, or set it to processing and refund the payment via BlinkPay.', 'blinkpay-nz-for-woocommerce' ),
				isset( $payment['payment_id'] ) ? $payment['payment_id'] : '',
				$this->format_amount( isset( $amount['total'] ) ? $amount['total'] : $order->get_total() )
			)
		);
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
	 * Whether the order carries a quick payment whose outcome is still being
	 * resolved by the deferred status checks. WooCommerce's stock-hold sweep
	 * (wc_cancel_unpaid_orders()) uses this to leave such orders alone: the
	 * sweep's default 60-minute window is narrower than the check schedule,
	 * and a payment settling after the sweep would land on a cancelled order.
	 * The exemption is bounded by order age as well as the check counter,
	 * because the counter only advances when cron actually runs: on a site
	 * whose cron is dead it would otherwise exempt every order forever,
	 * holding stock on abandoned checkouts indefinitely. An order without a
	 * creation date cannot be age-bounded, so it is not exempted.
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public function has_unresolved_quick_payment( $order ) {
		$created = $order->get_date_created();

		return $this->id === $order->get_payment_method()
			&& '' !== (string) $order->get_meta( '_blinkpay_quick_payment_id' )
			&& false !== $this->get_status_check_delay( (int) $order->get_meta( '_blinkpay_status_checks' ) )
			&& null !== $created
			&& time() - $created->getTimestamp() < $this->status_check_schedule_span();
	}

	/**
	 * The total span of the deferred-check schedule in seconds — 36 hours —
	 * after which no check can still be outstanding for an order created at
	 * its start.
	 *
	 * @return int
	 */
	private function status_check_schedule_span() {
		$span = 0;
		foreach ( self::STATUS_CHECK_SCHEDULE as $tier ) {
			list( $checks, $delay ) = $tier;
			$span += $checks * $delay;
		}

		return $span;
	}

	/**
	 * WP-Cron callback: re-checks a not-yet-terminal payment. Runs under the
	 * per-order lock so it cannot complete or fail an order a return request
	 * is confirming at the same moment. A contended lock defers to the
	 * holder: the check retries shortly, without advancing the attempt
	 * counter, because no check ran — waiting out a whole tier delay would
	 * stall the chain for up to 2 hours in the last tier.
	 *
	 * @param int $order_id The order ID.
	 */
	public function check_payment_status( $order_id ) {
		$lock = $this->acquire_order_lock( $order_id );
		if ( ! $lock ) {
			$this->defer_contended_status_check( $order_id );
			return;
		}

		try {
			$this->run_status_check( $order_id );
		} finally {
			$this->release_order_lock( $order_id, $lock );
		}
	}

	/**
	 * Reschedules a deferred check that lost the per-order lock. No check
	 * ran, so the retry comes shortly rather than a whole tier delay later —
	 * but boundedly: an unbounded chain would reschedule forever on an order
	 * something else touches constantly. Any check that does run clears the
	 * counter, so the bound only trips on consecutive contention.
	 *
	 * @param int $order_id The order ID.
	 */
	private function defer_contended_status_check( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$retries = (int) $order->get_meta( '_blinkpay_lock_retries' ) + 1;
		$order->update_meta_data( '_blinkpay_lock_retries', $retries );
		$order->save();

		if ( $retries > self::ORDER_LOCK_MAX_RETRIES ) {
			$order->add_order_note( __( 'BlinkPay deferred status checks stopped: another operation held this order through every retry. Check the payment in the BlinkPay merchant portal and update the order manually.', 'blinkpay-nz-for-woocommerce' ) );
			return;
		}

		if ( ! wp_next_scheduled( self::STATUS_CHECK_HOOK, array( $order_id ) ) ) {
			wp_schedule_single_event( time() + self::ORDER_LOCK_RETRY_DELAY, self::STATUS_CHECK_HOOK, array( $order_id ) );
		}
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
			|| $order->has_status( 'refunded' ) ) {
			return;
		}

		// A failed or cancelled order whose quick payment may still be in
		// flight is not abandoned: a debit initiated before the failure — or
		// before WooCommerce's unpaid-order sweep cancelled the order — can
		// still settle. A settlement recovers a failed order to paid, and is
		// surfaced loudly on a cancelled one.
		if ( $order->has_status( array( 'failed', 'cancelled' ) ) && ! $order->get_meta( '_blinkpay_quick_payment_id' ) ) {
			return;
		}

		$attempts = (int) $order->get_meta( '_blinkpay_status_checks' ) + 1;
		$order->update_meta_data( '_blinkpay_status_checks', $attempts );
		// A check is running, so any run of lock-contended retries has ended.
		$order->delete_meta_data( '_blinkpay_lock_retries' );
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

		// Only pending orders are eligible for WooCommerce's stock-hold sweep
		// (wc_cancel_unpaid_orders()), whose default 60-minute window is
		// narrower than the check schedule — the last tier spaces checks
		// 2 hours apart — so an order the check leaves unresolved is parked
		// on hold, out of the sweep's reach.
		if ( $order->has_status( 'pending' ) ) {
			$order->update_status( 'on-hold', __( 'BlinkPay has not yet confirmed the payment. The order is held while the deferred checks continue and will update automatically once the outcome is known.', 'blinkpay-nz-for-woocommerce' ) );
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

		// A payment ID alone is not proof of a settled payment: it is also
		// recorded for rejected payments, and a refund fired at one of those
		// deserves a local explanation, not a raw API rejection.
		if ( ! $order->is_paid() && ! $order->has_status( 'refunded' ) ) {
			return new WP_Error( 'blinkpay_refund_failed', __( 'This order has no settled BlinkPay payment to refund.', 'blinkpay-nz-for-woocommerce' ) );
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

		// The refunds API accepts no idempotency key and allows multiple
		// money-transfer refunds against one payment, so a double submission
		// — an admin retrying after a proxy timeout, or two admins acting at
		// once — must be blocked here, by the same per-order lock that
		// serialises order completion.
		$lock = $this->acquire_order_lock( $order->get_id() );
		if ( ! $lock ) {
			return new WP_Error( 'blinkpay_refund_failed', __( 'Another BlinkPay operation on this order is still in progress. Check the order notes and the BlinkPay merchant portal for the outcome of the previous request before retrying the refund.', 'blinkpay-nz-for-woocommerce' ) );
		}

		try {
			return $this->execute_refund( $order, $payment_id, $amount, $reason );
		} finally {
			$this->release_order_lock( $order->get_id(), $lock );
		}
	}

	/**
	 * The body of a refund. The caller holds the per-order lock, so no second
	 * submission can create another refund for this order concurrently.
	 *
	 * @param WC_Order $order      The order.
	 * @param string   $payment_id The BlinkPay payment ID.
	 * @param float    $amount     The refund amount.
	 * @param string   $reason     The refund reason.
	 * @return bool|WP_Error
	 */
	private function execute_refund( $order, $payment_id, $amount, $reason ) {
		$client = $this->get_api_client();

		if ( 'card_network_accepted' !== $order->get_meta( '_blinkpay_accepted_reason' ) ) {
			return $this->process_account_number_refund( $order, $client, $payment_id, $amount, $reason );
		}

		// Refunding the whole order total refunds the whole payment — but only
		// when no surcharge was recorded: what full_refund returns for a
		// surcharged payment (the order total, or the higher instructed
		// total_charge) is not pinned down by the API contract, so a
		// surcharged payment is always refunded as a partial carrying the
		// exact amount requested.
		$is_full = '' === (string) $order->get_meta( '_blinkpay_surcharge' )
			&& $this->format_amount( $amount ) === $this->format_amount( $order->get_total() );

		$payload = array(
			'type'       => $is_full ? 'full_refund' : 'partial_refund',
			'payment_id' => $payment_id,
			'pcr'        => $this->build_pcr( $this->pcr_particulars, $order->get_order_number(), (string) $order->get_id() ),
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
	 * type, which does not move money: it makes the customer's bank account
	 * number available so the merchant can transfer the refund from their own
	 * bank. The number itself is deliberately left in the BlinkPay merchant
	 * portal rather than copied into WordPress — an order note is readable by
	 * every shop manager and lands in exports and database backups — so the
	 * private note carries the obligation and where to find the number, and a
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
		// error that invites creating a second one. Poll briefly so a refund
		// the API fails immediately is rejected now rather than after the
		// merchant pays; an account number in the response means the refund
		// has resolved, but the number itself is never persisted.
		$refund = array();
		for ( $attempt = 0; $attempt < self::INLINE_POLL_ATTEMPTS; $attempt++ ) {
			if ( $attempt > 0 ) {
				$this->pause( self::INLINE_POLL_DELAY );
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

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: refund ID, 3: reason */
				__( 'This payment was settled by bank transfer, so BlinkPay\'s refund does not move money. Retrieve the customer\'s account number for refund %2$s from the BlinkPay merchant portal, then pay NZD %1$s from your own bank. %3$s', 'blinkpay-nz-for-woocommerce' ),
				$this->format_amount( $amount ),
				$refund_id,
				$reason ? $reason : ''
			)
		);

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

		return is_array( $data ) && isset( $data['status'] ) && 409 === (int) $data['status'];
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
	 * The per-customer value behind hashed_customer_identifier. A registered
	 * customer is identified by their WooCommerce customer ID — the "customer
	 * internal ID" the API describes — which is stable across billing-email
	 * changes. A guest has no such ID, so their lowercased billing email
	 * stands in, as it did for every order before customer IDs were used.
	 * When neither exists there is no identifier: the caller must omit the
	 * field rather than hash the empty string, which would send the same
	 * constant for every such order.
	 *
	 * @param WC_Order $order The order.
	 * @return string The identifier to hash, or '' when there is none.
	 */
	private function get_customer_identifier( $order ) {
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			return 'customer-' . $customer_id;
		}

		return strtolower( (string) $order->get_billing_email() );
	}

	/**
	 * Builds a PCR (particulars, code, reference) block within the API's
	 * 12-character, restricted-charset limits. The reference carries the
	 * customer-facing order number, which sequential-order-number plugins can
	 * prefix beyond 12 characters, so it may truncate; the code carries the
	 * numeric order ID, which always fits, giving reconciliation an exact key
	 * that does not depend on the truncated reference.
	 *
	 * @param string $particulars The particulars.
	 * @param string $reference   The reference, typically the order number.
	 * @param string $code        The code, typically the order ID.
	 * @return array
	 */
	public function build_pcr( $particulars, $reference, $code = '' ) {
		$particulars = $this->sanitise_pcr_field( $particulars );
		if ( '' === $particulars ) {
			$particulars = 'Order';
		}

		$pcr = array( 'particulars' => $particulars );

		$code = $this->sanitise_pcr_field( $code );
		if ( '' !== $code ) {
			$pcr['code'] = $code;
		}

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
		// A cancelled order stays cancelled: no money moved either way, and
		// failing it would overwrite what may be a deliberate cancellation.
		if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			$order->add_order_note( $note );
		} else {
			$order->update_status( 'failed', $note );
		}
	}
}
