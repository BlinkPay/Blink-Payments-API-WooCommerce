<?php
/**
 * PHPUnit bootstrap: minimal WordPress and WooCommerce stubs so the gateway
 * can be unit tested without a WordPress installation.
 *
 * Only the functions and classes the tested code paths touch are stubbed.
 * Scheduled cron events are recorded in $GLOBALS['wc_blinkpay_scheduled_events']
 * so tests can assert against them.
 *
 * @package blinkpay-nz-for-woocommerce
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WC_BLINKPAY_PLUGIN_URL', 'https://example.test/wp-content/plugins/blinkpay-nz-for-woocommerce/' );

$GLOBALS['wc_blinkpay_scheduled_events'] = array();
$GLOBALS['wc_blinkpay_test_orders']      = array();
$GLOBALS['wc_blinkpay_notices']          = array();

/**
 * Resets the recorded state between tests.
 */
function wc_blinkpay_tests_reset() {
	$GLOBALS['wc_blinkpay_scheduled_events'] = array();
	$GLOBALS['wc_blinkpay_test_orders']      = array();
	$GLOBALS['wc_blinkpay_notices']          = array();
}

// --- WordPress function stubs -----------------------------------------------

function __( $text, $domain = 'default' ) {
	return $text;
}

function apply_filters( $hook_name, $value, ...$args ) {
	return $value;
}

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	return true;
}

function add_query_arg( $args, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

function wp_generate_uuid4() {
	return sprintf( 'test-uuid-%06d', wp_rand( 0, 999999 ) );
}

function wp_rand( $min, $max ) {
	return rand( $min, $max );
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['wc_blinkpay_scheduled_events'][] = array(
		'timestamp' => $timestamp,
		'hook'      => $hook,
		'args'      => $args,
	);
	return true;
}

function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['wc_blinkpay_scheduled_events'] as $event ) {
		if ( $event['hook'] === $hook && $event['args'] === $args ) {
			return $event['timestamp'];
		}
	}
	return false;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {

	/** @var string */
	private $code;

	/** @var string */
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

// --- WooCommerce function stubs ----------------------------------------------

function wc_get_order( $order_id ) {
	return isset( $GLOBALS['wc_blinkpay_test_orders'][ $order_id ] )
		? $GLOBALS['wc_blinkpay_test_orders'][ $order_id ]
		: false;
}

function wc_add_notice( $message, $notice_type = 'success' ) {
	$GLOBALS['wc_blinkpay_notices'][] = array(
		'message' => $message,
		'type'    => $notice_type,
	);
}

function get_woocommerce_currency() {
	return 'NZD';
}

function WC() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new WC_BlinkPay_Test_WooCommerce();
	}
	return $instance;
}

class WC_BlinkPay_Test_WooCommerce {

	public function api_request_url( $request ) {
		return 'https://example.test/wc-api/' . $request . '/';
	}
}

/**
 * The slice of WC_Payment_Gateway the gateway under test uses.
 */
class WC_Payment_Gateway {

	/** @var string */
	public $id;

	/** @var string */
	public $icon;

	/** @var bool */
	public $has_fields;

	/** @var string */
	public $method_title;

	/** @var string */
	public $method_description;

	/** @var array */
	public $supports = array();

	/** @var string */
	public $title;

	/** @var string */
	public $description;

	/** @var array */
	public $form_fields = array();

	/** @var array */
	public $settings = array();

	public function init_settings() {
		$this->settings = array();
	}

	public function get_option( $key, $empty_value = '' ) {
		return isset( $this->settings[ $key ] ) && '' !== $this->settings[ $key ]
			? $this->settings[ $key ]
			: $empty_value;
	}

	public function get_title() {
		return $this->title;
	}

	public function process_admin_options() {
		return true;
	}
}

/**
 * An in-memory WC_Order the gateway can drive.
 */
class WC_BlinkPay_Test_Order {

	/** @var int */
	private $id;

	/** @var array */
	private $meta = array();

	/** @var string[] */
	public $notes = array();

	/** @var string */
	private $status = 'pending';

	public function __construct( $id ) {
		$this->id = $id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_order_key() {
		return 'wc_order_test_key';
	}

	public function get_order_number() {
		return (string) $this->id;
	}

	public function get_currency() {
		return 'NZD';
	}

	public function get_total() {
		return 49.95;
	}

	public function get_billing_email() {
		return 'customer@example.test';
	}

	public function get_meta( $key = '' ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function save() {
		return $this->id;
	}

	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}

	public function get_status() {
		return $this->status;
	}

	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}

	public function update_status( $new_status, $note = '' ) {
		$this->status = $new_status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
		return true;
	}

	public function is_paid() {
		return in_array( $this->status, array( 'processing', 'completed' ), true );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-wc-blinkpay-gateway.php';
