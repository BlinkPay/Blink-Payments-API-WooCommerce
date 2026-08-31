<?php
/**
 * BDL-1615: the retrieval that initiates the debit must not depend on the
 * customer's browser returning from the gateway, so creating a quick payment
 * must schedule the deferred status check immediately.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

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
}

/**
 * An API client returning a canned create_quick_payment response.
 */
class WC_BlinkPay_Canned_API_Client {

	/** @var mixed */
	private $create_response;

	public function __construct( $create_response ) {
		$this->create_response = $create_response;
	}

	public function is_configured() {
		return true;
	}

	public function create_quick_payment( $payload, $idempotency_key ) {
		return $this->create_response;
	}
}

class StatusCheckSchedulingTest extends TestCase {

	protected function setUp(): void {
		wc_blinkpay_tests_reset();
	}

	/**
	 * @param int $order_id The order ID to register.
	 * @return WC_BlinkPay_Test_Order
	 */
	private function register_order( $order_id ) {
		$order = new WC_BlinkPay_Test_Order( $order_id );

		$GLOBALS['wc_blinkpay_test_orders'][ $order_id ] = $order;

		return $order;
	}

	public function test_creating_a_quick_payment_schedules_the_deferred_status_check() {
		$this->register_order( 123 );

		$gateway = new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Canned_API_Client(
				array(
					'quick_payment_id' => 'qp-123',
					'redirect_uri'     => 'https://gateway.test/pay/qp-123',
				)
			)
		);

		$before = time();
		$result = $gateway->process_payment( 123 );

		// The checkout redirect still succeeds.
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://gateway.test/pay/qp-123', $result['redirect'] );

		// The deferred check is scheduled with no return request involved.
		$events = $GLOBALS['wc_blinkpay_scheduled_events'];
		$this->assertCount( 1, $events );
		$this->assertSame( WC_BlinkPay_Gateway::STATUS_CHECK_HOOK, $events[0]['hook'] );
		$this->assertSame( array( 123 ), $events[0]['args'] );
		$this->assertGreaterThanOrEqual( $before + WC_BlinkPay_Gateway::STATUS_CHECK_DELAY, $events[0]['timestamp'] );
	}

	public function test_a_failed_quick_payment_creation_schedules_nothing() {
		$this->register_order( 124 );

		$gateway = new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Canned_API_Client( new WP_Error( 'blinkpay_api_error', 'Service unavailable.' ) )
		);

		$result = $gateway->process_payment( 124 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( 0, $GLOBALS['wc_blinkpay_scheduled_events'] );
	}

	public function test_the_return_path_does_not_double_up_the_scheduled_check() {
		$order = $this->register_order( 125 );

		$gateway = new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Canned_API_Client(
				array(
					'quick_payment_id' => 'qp-125',
					'redirect_uri'     => 'https://gateway.test/pay/qp-125',
				)
			)
		);

		$gateway->process_payment( 125 );

		// The customer returns while the outcome is still unknown and the
		// inline poll parks the order — the creation-time check must not be
		// duplicated.
		$gateway->await_confirmation( $order, 'Awaiting confirmation.' );

		$this->assertCount( 1, $GLOBALS['wc_blinkpay_scheduled_events'] );
	}
}
