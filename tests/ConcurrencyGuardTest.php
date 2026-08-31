<?php
/**
 * BDL-1623: the return page's inline poll and the deferred cron check can run
 * concurrently for one order, so a terminal outcome may only be applied under
 * the per-order lock, on a fresh read. A contended lock means another process
 * owns the outcome: the return page shows the order as it stands, and the
 * cron check reschedules itself without advancing the attempt counter.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * A gateway whose lock acquisition loses the race it is designed to detect: a
 * concurrent process completes the payment in the gap between this request's
 * order read and its lock acquisition, so the pre-lock snapshot is stale.
 */
class WC_BlinkPay_Lock_Race_Gateway extends WC_BlinkPay_Test_Gateway {

	protected function acquire_order_lock( $order_id ) {
		$acquired = parent::acquire_order_lock( $order_id );

		$paid = new WC_BlinkPay_Test_Order( $order_id );
		$paid->update_meta_data( '_blinkpay_quick_payment_id', 'qp-' . $order_id );
		$paid->payment_complete( 'pay-concurrent' );

		$GLOBALS['wc_blinkpay_test_orders'][ $order_id ] = $paid;

		return $acquired;
	}
}

class ConcurrencyGuardTest extends TestCase {

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
	 * Marks the order's confirmation lock as held by another process.
	 *
	 * @param int $order_id The order ID.
	 */
	private function hold_order_lock( $order_id ) {
		set_transient( 'wc_blinkpay_order_lock_' . $order_id, time(), WC_BlinkPay_Gateway::ORDER_LOCK_TIMEOUT );
	}

	/**
	 * Runs handle_return() and returns the captured redirect location.
	 *
	 * @param WC_BlinkPay_Gateway $gateway  The gateway.
	 * @param int                 $order_id The order ID for the request.
	 * @param string              $status   The gateway status parameter.
	 * @return string
	 */
	private function handle_return( $gateway, $order_id, $status = '' ) {
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

	/**
	 * A canned retrieval response whose consent carries one payment.
	 *
	 * @param string $payment_id The payment ID.
	 * @param string $status     The payment status.
	 * @return array
	 */
	private function consent_with_payment( $payment_id, $status ) {
		return array(
			'consent' => array(
				'status'   => 'Consumed',
				'payments' => array(
					array(
						'payment_id' => $payment_id,
						'status'     => $status,
					),
				),
			),
		);
	}

	public function test_the_return_page_leaves_the_order_untouched_while_another_process_holds_the_lock() {
		$order = $this->register_order( 401 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-401' );
		$order->update_status( 'on-hold' );

		$this->hold_order_lock( 401 );

		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent_with_payment( 'pay-401', 'AcceptedSettlementCompleted' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$location = $this->handle_return( $gateway, 401, 'cancelled' );

		$this->assertSame( 'https://example.test/order-received/401/', $location );
		$this->assertSame( 'on-hold', $order->get_status(), 'A contended lock means another process owns the outcome; the order must not be touched.' );
		$this->assertSame( array(), $client->get_calls, 'The return page must not poll the API while another process is confirming the order.' );
		$this->assertSame( array(), $order->notes );

		// The lock belongs to the other process and must survive this request.
		$this->assertNotFalse( get_transient( 'wc_blinkpay_order_lock_401' ) );
	}

	public function test_the_cron_check_defers_and_reschedules_while_another_process_holds_the_lock() {
		$order = $this->register_order( 402 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-402' );
		$order->update_meta_data( '_blinkpay_status_checks', 3 );
		$order->update_status( 'on-hold' );

		$this->hold_order_lock( 402 );

		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent_with_payment( 'pay-402', 'AcceptedSettlementCompleted' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->check_payment_status( 402 );

		$this->assertSame( array(), $client->get_calls, 'A deferred check must not poll the API while another process is confirming the order.' );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 3, $order->get_meta( '_blinkpay_status_checks' ), 'A deferred check ran no check, so the attempt counter must not advance.' );

		// The polling chain survives: exactly one check is rescheduled.
		$events = $GLOBALS['wc_blinkpay_scheduled_events'];
		$this->assertCount( 1, $events );
		$this->assertSame( array( 402 ), $events[0]['args'] );
	}

	public function test_the_return_page_abandons_a_stale_failure_when_the_order_was_completed_concurrently() {
		$stale = $this->register_order( 403 );
		$stale->update_meta_data( '_blinkpay_quick_payment_id', 'qp-403' );
		$stale->update_status( 'on-hold' );

		// The API would report the consent Rejected — the outcome that failed
		// paid orders before the fix — but the fresh read under the lock shows
		// the order already completed, so it must never be consulted.
		$client  = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(
				array(
					'consent' => array(
						'status'   => 'Rejected',
						'payments' => array(),
					),
				),
			)
		);
		$gateway = new WC_BlinkPay_Lock_Race_Gateway( $client );

		$location = $this->handle_return( $gateway, 403, 'cancelled' );

		$this->assertSame( 'https://example.test/order-received/403/', $location );
		$this->assertSame( array(), $client->get_calls, 'An order completed concurrently must not be re-confirmed through the API.' );
		$this->assertSame( 'on-hold', $stale->get_status(), 'The stale snapshot must not be written over the completed order.' );
		$this->assertTrue( $GLOBALS['wc_blinkpay_test_orders'][403]->is_paid(), 'The concurrent completion must stand.' );

		// The lock was this request's own and is released for the next caller.
		$this->assertFalse( get_transient( 'wc_blinkpay_order_lock_403' ) );
	}

	public function test_the_return_page_confirms_normally_and_releases_the_lock_when_uncontended() {
		$order = $this->register_order( 404 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-404' );
		$order->update_status( 'on-hold' );

		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent_with_payment( 'pay-404', 'AcceptedSettlementCompleted' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$location = $this->handle_return( $gateway, 404 );

		$this->assertSame( 'https://example.test/order-received/404/', $location );
		$this->assertTrue( $order->is_paid() );
		$this->assertFalse( get_transient( 'wc_blinkpay_order_lock_404' ), 'The lock must be released once confirmation finishes.' );
	}

	public function test_the_cron_check_confirms_normally_and_releases_the_lock_when_uncontended() {
		$order = $this->register_order( 405 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-405' );
		$order->update_status( 'on-hold' );

		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent_with_payment( 'pay-405', 'AcceptedSettlementCompleted' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->check_payment_status( 405 );

		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 1, $order->get_meta( '_blinkpay_status_checks' ) );
		$this->assertFalse( get_transient( 'wc_blinkpay_order_lock_405' ), 'The lock must be released once the check finishes.' );
	}
}
