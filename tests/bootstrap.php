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
$GLOBALS['wc_blinkpay_options']          = array();
$GLOBALS['wc_blinkpay_transients']       = array();
$GLOBALS['wc_blinkpay_http_responses']   = array();
$GLOBALS['wc_blinkpay_http_requests']    = array();

/**
 * Resets the recorded state between tests.
 */
function wc_blinkpay_tests_reset() {
	$GLOBALS['wc_blinkpay_scheduled_events'] = array();
	$GLOBALS['wc_blinkpay_test_orders']      = array();
	$GLOBALS['wc_blinkpay_notices']          = array();
	$GLOBALS['wc_blinkpay_options']          = array();
	$GLOBALS['wc_blinkpay_transients']       = array();
	$GLOBALS['wc_blinkpay_http_responses']   = array();
	$GLOBALS['wc_blinkpay_http_requests']    = array();
}

// --- WordPress function stubs -----------------------------------------------

function __( $text, $domain = 'default' ) {
	return $text;
}

function absint( $maybeint ) {
	return abs( (int) $maybeint );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_text_field( $str ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function wp_kses_post( $content ) {
	return $content;
}

function get_option( $option, $default_value = false ) {
	return isset( $GLOBALS['wc_blinkpay_options'][ $option ] ) ? $GLOBALS['wc_blinkpay_options'][ $option ] : $default_value;
}

function update_option( $option, $value, $autoload = null ) {
	$GLOBALS['wc_blinkpay_options'][ $option ] = $value;
	return true;
}

function delete_option( $option ) {
	unset( $GLOBALS['wc_blinkpay_options'][ $option ] );
	return true;
}

function get_transient( $transient ) {
	return isset( $GLOBALS['wc_blinkpay_transients'][ $transient ] ) ? $GLOBALS['wc_blinkpay_transients'][ $transient ] : false;
}

function set_transient( $transient, $value, $expiration = 0 ) {
	$GLOBALS['wc_blinkpay_transients'][ $transient ] = $value;
	return true;
}

function delete_transient( $transient ) {
	unset( $GLOBALS['wc_blinkpay_transients'][ $transient ] );
	return true;
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['wc_blinkpay_http_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	return array_shift( $GLOBALS['wc_blinkpay_http_responses'] );
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : '';
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

/**
 * Thrown by the wp_safe_redirect() stub so tests can observe the redirect
 * target instead of exiting the process; the message carries the location.
 */
class WC_BlinkPay_Test_Redirect extends RuntimeException {}

function wp_safe_redirect( $location, $status = 302 ) {
	throw new WC_BlinkPay_Test_Redirect( $location );
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
	// Mirrors WP core: an identical event within ten minutes of the requested
	// time is refused, so tests exercise the duplicate protection the gateway
	// relies on rather than a stub that always accepts.
	foreach ( $GLOBALS['wc_blinkpay_scheduled_events'] as $event ) {
		if ( $event['hook'] === $hook && $event['args'] === $args && abs( $event['timestamp'] - $timestamp ) < 600 ) {
			return false;
		}
	}

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

/**
 * The slice of wpdb the gateway's per-order lock uses. The queries are the
 * lock's three fixed shapes; the stub parses the option name and value out of
 * them and models INSERT IGNORE as one indivisible insert-if-absent over the
 * recorded options, mirroring the unique key on option_name, and DELETE as an
 * atomic compare-and-delete.
 */
class WC_BlinkPay_Test_WPDB {

	/** @var string */
	public $options = 'wp_options';

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . $arg . "'", $query, 1 );
		}
		return $query;
	}

	public function query( $query ) {
		if ( preg_match( "/^INSERT IGNORE INTO \S+ \( option_name, option_value, autoload \) VALUES \( '([^']+)', '([^']+)', 'no' \)$/", $query, $matches ) ) {
			if ( isset( $GLOBALS['wc_blinkpay_options'][ $matches[1] ] ) ) {
				return 0;
			}
			$GLOBALS['wc_blinkpay_options'][ $matches[1] ] = $matches[2];
			return 1;
		}

		if ( preg_match( "/^DELETE FROM \S+ WHERE option_name = '([^']+)' AND option_value = '([^']+)'$/", $query, $matches ) ) {
			if ( isset( $GLOBALS['wc_blinkpay_options'][ $matches[1] ] )
				&& (string) $GLOBALS['wc_blinkpay_options'][ $matches[1] ] === $matches[2] ) {
				unset( $GLOBALS['wc_blinkpay_options'][ $matches[1] ] );
				return 1;
			}
			return 0;
		}

		throw new RuntimeException( 'Unexpected test wpdb query: ' . $query );
	}

	public function get_var( $query ) {
		if ( preg_match( "/^SELECT option_value FROM \S+ WHERE option_name = '([^']+)'$/", $query, $matches ) ) {
			return isset( $GLOBALS['wc_blinkpay_options'][ $matches[1] ] )
				? (string) $GLOBALS['wc_blinkpay_options'][ $matches[1] ]
				: null;
		}

		throw new RuntimeException( 'Unexpected test wpdb query: ' . $query );
	}
}

$GLOBALS['wpdb'] = new WC_BlinkPay_Test_WPDB();

class WP_Error {

	/** @var string */
	private $code;

	/** @var string */
	private $message;

	/** @var mixed */
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
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

function wc_get_checkout_url() {
	return 'https://example.test/checkout/';
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

	public function supports( $feature ) {
		return in_array( $feature, $this->supports, true );
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

	/** @var string[] */
	public $customer_notes = array();

	/** @var string */
	private $status = 'pending';

	/** @var string */
	private $order_number = '';

	/** @var int */
	private $customer_id = 0;

	/** @var string */
	private $billing_email = 'customer@example.test';

	/** @var float */
	private $total = 49.95;

	/** @var DateTimeImmutable|null */
	private $date_created;

	public function __construct( $id ) {
		$this->id           = $id;
		$this->date_created = new DateTimeImmutable();
	}

	public function get_date_created() {
		return $this->date_created;
	}

	public function set_date_created( $date_created ) {
		$this->date_created = $date_created;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_order_key() {
		return 'wc_order_test_key';
	}

	public function key_is_valid( $key ) {
		return $this->get_order_key() === $key;
	}

	public function get_payment_method() {
		return 'blinkpay';
	}

	public function get_checkout_order_received_url() {
		return 'https://example.test/order-received/' . $this->id . '/';
	}

	public function get_checkout_payment_url() {
		return 'https://example.test/order-pay/' . $this->id . '/';
	}

	public function get_order_number() {
		return '' !== $this->order_number ? $this->order_number : (string) $this->id;
	}

	public function set_order_number( $order_number ) {
		$this->order_number = $order_number;
	}

	public function get_customer_id() {
		return $this->customer_id;
	}

	public function set_customer_id( $customer_id ) {
		$this->customer_id = $customer_id;
	}

	public function get_currency() {
		return 'NZD';
	}

	public function get_total() {
		return $this->total;
	}

	public function set_total( $total ) {
		$this->total = $total;
	}

	public function get_billing_email() {
		return $this->billing_email;
	}

	public function set_billing_email( $billing_email ) {
		$this->billing_email = $billing_email;
	}

	public function get_meta( $key = '' ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function save() {
		return $this->id;
	}

	public function add_order_note( $note, $is_customer_note = 0 ) {
		if ( $is_customer_note ) {
			$this->customer_notes[] = $note;
		} else {
			$this->notes[] = $note;
		}
	}

	public function get_transaction_id() {
		return $this->get_meta( '_transaction_id' );
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

	public function payment_complete( $transaction_id = '' ) {
		$this->status                  = 'processing';
		$this->meta['_transaction_id'] = $transaction_id;
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-wc-blinkpay-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-wc-blinkpay-gateway.php';

// --- Test doubles -------------------------------------------------------------

/**
 * A gateway wired to a canned API client, so no HTTP is involved.
 */
class WC_BlinkPay_Test_Gateway extends WC_BlinkPay_Gateway {

	/** @var object */
	private $test_api_client;

	public function __construct( $test_api_client ) {
		parent::__construct();
		$this->test_api_client = $test_api_client;
	}

	public function get_api_client() {
		return $this->test_api_client;
	}

	protected function pause( $seconds ) {
		// Real sleeps would slow the suite without changing behaviour.
	}
}

/**
 * An API client that records every call and answers from queues of canned
 * responses. The retrieval queue repeats its final response, so one canned
 * response can serve a whole polling loop.
 */
class WC_BlinkPay_Fake_API_Client {

	/** @var array[] Each entry: array( 'payload' => …, 'idempotency_key' => … ). */
	public $create_calls = array();

	/** @var string[] Quick payment IDs retrieved, in order. */
	public $get_calls = array();

	/** @var array[] Refund payloads sent, in order. */
	public $refund_calls = array();

	/** @var string[]|null The canned granted scopes; null means unknown. */
	public $granted_scopes = null;

	/** @var array */
	private $create_responses;

	/** @var array */
	private $get_responses;

	/** @var array */
	private $refund_responses;

	/** @var array */
	private $get_refund_responses;

	/**
	 * @param array $create_responses     Responses returned in order, one per create call.
	 * @param array $get_responses        Responses returned in order per retrieval; the last repeats.
	 * @param array $refund_responses     Responses returned in order, one per refund creation.
	 * @param array $get_refund_responses Responses returned in order, one per refund retrieval.
	 */
	public function __construct( array $create_responses = array(), array $get_responses = array(), array $refund_responses = array(), array $get_refund_responses = array() ) {
		$this->create_responses     = $create_responses;
		$this->get_responses        = $get_responses;
		$this->refund_responses     = $refund_responses;
		$this->get_refund_responses = $get_refund_responses;
	}

	public function is_configured() {
		return true;
	}

	public function get_granted_scopes() {
		return $this->granted_scopes;
	}

	public function create_quick_payment( $payload, $idempotency_key ) {
		$this->create_calls[] = array(
			'payload'         => $payload,
			'idempotency_key' => $idempotency_key,
		);

		return array_shift( $this->create_responses );
	}

	public function get_quick_payment( $quick_payment_id ) {
		$this->get_calls[] = $quick_payment_id;

		if ( ! $this->get_responses ) {
			return new WP_Error( 'blinkpay_test', 'No canned retrieval response.' );
		}

		return count( $this->get_responses ) > 1 ? array_shift( $this->get_responses ) : $this->get_responses[0];
	}

	public function create_refund( array $payload ) {
		$this->refund_calls[] = $payload;

		if ( ! $this->refund_responses ) {
			return new WP_Error( 'blinkpay_test', 'No canned refund response.' );
		}

		return array_shift( $this->refund_responses );
	}

	public function get_refund( $refund_id ) {
		if ( ! $this->get_refund_responses ) {
			return new WP_Error( 'blinkpay_test', 'No canned refund retrieval response.' );
		}

		return array_shift( $this->get_refund_responses );
	}
}
