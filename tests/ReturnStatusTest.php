<?php
/**
 * BDL-1617: a non-success status on the gateway return is replayable from
 * browser history, so it must be treated as a hint and confirmed through the
 * API — never as terminal proof that fails an order whose debit is in flight.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class ReturnStatusTest extends TestCase {

	protected function setUp(): void {
		wc_blinkpay_tests_reset();
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
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

	/**
	 * Runs handle_return() and returns the captured redirect location.
	 *
	 * @param WC_BlinkPay_Gateway $gateway The gateway.
	 * @param int                 $order_id The order ID for the request.
	 * @param string              $status   The gateway status parameter.
	 * @return string
	 */
	private function handle_return( $gateway, $order_id, $status ) {
		$_GET = array(
			'order_id' => (string) $order_id,
			'key'      => 'wc_order_test_key',
			'status'   => $status,
		);

		try {
			$gateway->handle_return();
		} catch ( WC_BlinkPay_Test_Redirect $redirect ) {
			return $redirect->getMessage();
		}

		$this->fail( 'handle_return() did not redirect.' );
	}

	public function test_a_replayed_failure_status_does_not_fail_an_in_flight_payment() {
		$order = $this->register_order( 301 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-301' );
		$order->update_status( 'on-hold', 'Awaiting settlement.' );

		// The API says the debit is in flight, whatever the stale URL claims.
		$gateway = new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Fake_API_Client(
				array(),
				array(
					array(
						'consent' => array(
							'status'   => 'Consumed',
							'payments' => array(
								array(
									'payment_id' => 'pay-301',
									'status'     => 'AcceptedSettlementInProcess',
								),
							),
						),
					),
				)
			)
		);

		$location = $this->handle_return( $gateway, 301, 'cancelled' );

		$this->assertSame( 'on-hold', $order->get_status(), 'A replayed status must not fail an order whose debit is in flight.' );
		$this->assertSame( 'https://example.test/order-received/301/', $location );

		// The false "you have not been charged" claim is never shown while
		// the outcome is unconfirmed.
		foreach ( $GLOBALS['wc_blinkpay_notices'] as $notice ) {
			$this->assertStringNotContainsString( 'not been charged', $notice['message'] );
		}

		// The order stays in the polling pool for cron to decide.
		$this->assertNotFalse( wp_next_scheduled( WC_BlinkPay_Gateway::STATUS_CHECK_HOOK, array( 301 ) ) );
	}

	public function test_an_api_confirmed_cancellation_fails_the_order_and_tells_the_customer() {
		$order = $this->register_order( 302 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-302' );

		$gateway = new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Fake_API_Client(
				array(),
				array(
					array(
						'consent' => array(
							'status'   => 'Rejected',
							'payments' => array(),
						),
					),
				)
			)
		);

		$location = $this->handle_return( $gateway, 302, 'cancelled' );

		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( 'https://example.test/order-pay/302/', $location );

		$charged_notices = array_filter(
			$GLOBALS['wc_blinkpay_notices'],
			function ( $notice ) {
				return false !== strpos( $notice['message'], 'not been charged' );
			}
		);
		$this->assertNotEmpty( $charged_notices, 'An API-confirmed terminal non-paid state must tell the customer they were not charged.' );
	}

	public function test_the_deferred_check_recovers_a_failed_order_whose_payment_settled() {
		$order = $this->register_order( 303 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-303' );
		$order->update_status( 'failed', 'Failed by a replayed status before the fix.' );

		$gateway = new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Fake_API_Client(
				array(),
				array(
					array(
						'consent' => array(
							'status'   => 'Consumed',
							'payments' => array(
								array(
									'payment_id' => 'pay-303',
									'status'     => 'AcceptedSettlementCompleted',
									'detail'     => array(
										'amount' => array(
											'currency' => 'NZD',
											'total'    => '49.95',
										),
									),
								),
							),
						),
					),
				)
			)
		);

		$gateway->check_payment_status( 303 );

		$this->assertTrue( $order->is_paid(), 'A settled debit must recover an order failed while the payment was in flight.' );
		$this->assertSame( 'pay-303', $order->get_meta( '_transaction_id' ) );
	}

	public function test_the_status_parameter_is_truncated_before_reaching_the_order_note() {
		$order = $this->register_order( 304 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-304' );

		$gateway = new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Fake_API_Client(
				array(),
				array(
					array(
						'consent' => array(
							'status'   => 'Rejected',
							'payments' => array(),
						),
					),
				)
			)
		);

		$oversized = str_repeat( 'x', 200 );
		$this->handle_return( $gateway, 304, $oversized );

		foreach ( $order->notes as $note ) {
			$this->assertStringNotContainsString( $oversized, $note );
		}
		$this->assertNotEmpty( $order->notes );
	}
}
