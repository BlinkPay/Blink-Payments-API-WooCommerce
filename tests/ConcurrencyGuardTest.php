<?php
/**
 * BDL-1623: the return page's inline poll and the deferred cron check can run
 * concurrently for one order, so a terminal outcome may only be applied under
 * the per-order lock, on a fresh read. A contended lock means another process
 * owns the outcome: the return page shows the order as it stands, and the
 * cron check reschedules itself without advancing the attempt counter.
 *
 * BDL-1630: acquisition is atomic — backed by WP_Upgrader::create_lock() —
 * so exactly one of two concurrent callers acquires, and a double-submitted
 * refund loses the race instead of refunding the customer twice.
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

/**
 * Exposes the protected lock methods so tests can drive the acquisition
 * contract directly, one instance per simulated process.
 */
class WC_BlinkPay_Lock_Probe_Gateway extends WC_BlinkPay_Test_Gateway {

	public function acquire( $order_id ) {
		return $this->acquire_order_lock( $order_id );
	}

	public function release( $order_id ) {
		$this->release_order_lock( $order_id );
	}
}

/**
 * A client whose refund creation fires a rival gateway's refund for the same
 * order mid-flight, reproducing the double submission the lock must resolve
 * to exactly one winner: two shop managers clicking Refund at once, or one
 * admin retrying while the first request is still slow.
 */
class WC_BlinkPay_Double_Submission_Client extends WC_BlinkPay_Fake_API_Client {

	/** @var WC_BlinkPay_Test_Gateway|null Fired once, on the first refund creation. */
	public $rival_gateway;

	/** @var int */
	public $rival_order_id;

	/** @var bool|WP_Error|null What the rival's process_refund() returned. */
	public $rival_result;

	public function create_refund( array $payload ) {
		if ( $this->rival_gateway ) {
			$rival               = $this->rival_gateway;
			$this->rival_gateway = null;
			$this->rival_result  = $rival->process_refund( $this->rival_order_id, 49.95, '' );
		}

		return parent::create_refund( $payload );
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
		WP_Upgrader::create_lock( 'wc_blinkpay_order_' . $order_id, WC_BlinkPay_Gateway::ORDER_LOCK_TIMEOUT );
	}

	/**
	 * Whether the order's lock is currently held.
	 *
	 * @param int $order_id The order ID.
	 * @return bool
	 */
	private function order_lock_held( $order_id ) {
		return false !== get_option( 'wc_blinkpay_order_' . $order_id . '.lock' );
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
		$this->assertTrue( $this->order_lock_held( 401 ) );
	}

	public function test_the_cron_check_defers_and_retries_shortly_while_another_process_holds_the_lock() {
		$order = $this->register_order( 402 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-402' );
		// Deep in the last tier, where the tier delay is 2 hours.
		$order->update_meta_data( '_blinkpay_status_checks', 35 );
		$order->update_status( 'on-hold' );

		$this->hold_order_lock( 402 );

		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent_with_payment( 'pay-402', 'AcceptedSettlementCompleted' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$before = time();
		$gateway->check_payment_status( 402 );

		$this->assertSame( array(), $client->get_calls, 'A deferred check must not poll the API while another process is confirming the order.' );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 35, $order->get_meta( '_blinkpay_status_checks' ), 'A deferred check ran no check, so the attempt counter must not advance.' );

		// The polling chain survives with exactly one retry — and shortly, not
		// a whole tier delay later, because no check actually ran.
		$events = $GLOBALS['wc_blinkpay_scheduled_events'];
		$this->assertCount( 1, $events );
		$this->assertSame( array( 402 ), $events[0]['args'] );
		$this->assertLessThanOrEqual( time() + WC_BlinkPay_Gateway::ORDER_LOCK_RETRY_DELAY, $events[0]['timestamp'] );
		$this->assertGreaterThanOrEqual( $before + WC_BlinkPay_Gateway::ORDER_LOCK_RETRY_DELAY, $events[0]['timestamp'] );
	}

	public function test_a_refund_is_refused_while_another_process_holds_the_lock() {
		$order = $this->register_order( 406 );
		$order->update_meta_data( '_blinkpay_payment_id', 'pay-406' );
		$order->update_meta_data( '_blinkpay_accepted_reason', 'card_network_accepted' );
		$order->payment_complete( 'pay-406' );

		$this->hold_order_lock( 406 );

		$client  = new WC_BlinkPay_Fake_API_Client( array(), array(), array( array( 'refund_id' => 'rf-406' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		// The refunds API accepts no idempotency key, so a second submission
		// while one is in flight must be refused before any money can move.
		$result = $gateway->process_refund( 406, 49.95, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'still in progress', $result->get_error_message() );
		$this->assertSame( array(), $client->refund_calls, 'No refund may be created while another operation holds the order.' );

		// The lock belongs to the other process and must survive this request.
		$this->assertTrue( $this->order_lock_held( 406 ) );
	}

	public function test_a_refund_releases_the_lock_when_finished() {
		$order = $this->register_order( 407 );
		$order->update_meta_data( '_blinkpay_payment_id', 'pay-407' );
		$order->update_meta_data( '_blinkpay_accepted_reason', 'card_network_accepted' );
		$order->payment_complete( 'pay-407' );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-407' ) ),
			array(
				array(
					'refund_id' => 'rf-407',
					'status'    => 'completed',
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 407, 49.95, '' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $client->refund_calls );
		$this->assertFalse( $this->order_lock_held( 407 ), 'The lock must be released once the refund finishes.' );
	}

	public function test_exactly_one_of_two_concurrent_acquirers_wins_the_order_lock() {
		$first  = new WC_BlinkPay_Lock_Probe_Gateway( new WC_BlinkPay_Fake_API_Client() );
		$second = new WC_BlinkPay_Lock_Probe_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$this->assertTrue( $first->acquire( 408 ) );
		$this->assertFalse( $second->acquire( 408 ), 'The lock is held: the loser must be told, not silently overwrite the winner.' );

		$first->release( 408 );

		$this->assertTrue( $second->acquire( 408 ), 'A released lock must be acquirable again.' );
	}

	public function test_a_crashed_holders_expired_lock_is_broken_and_reacquired() {
		$gateway = new WC_BlinkPay_Lock_Probe_Gateway( new WC_BlinkPay_Fake_API_Client() );

		// A holder that crashed before releasing, longer ago than the timeout.
		update_option( 'wc_blinkpay_order_409.lock', time() - WC_BlinkPay_Gateway::ORDER_LOCK_TIMEOUT - 1 );

		$this->assertTrue( $gateway->acquire( 409 ), 'An expired lock means a crashed holder: it must be broken, not respected for good.' );
	}

	public function test_a_refund_double_submission_loses_the_race_and_creates_no_second_refund() {
		$order = $this->register_order( 410 );
		$order->update_meta_data( '_blinkpay_payment_id', 'pay-410' );
		$order->update_meta_data( '_blinkpay_accepted_reason', 'card_network_accepted' );
		$order->payment_complete( 'pay-410' );

		$rival_client = new WC_BlinkPay_Fake_API_Client( array(), array(), array( array( 'refund_id' => 'rf-410-b' ) ) );
		$rival        = new WC_BlinkPay_Test_Gateway( $rival_client );

		$client                 = new WC_BlinkPay_Double_Submission_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-410-a' ) ),
			array(
				array(
					'refund_id' => 'rf-410-a',
					'status'    => 'completed',
				),
			)
		);
		$client->rival_gateway  = $rival;
		$client->rival_order_id = 410;

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 410, 49.95, '' );

		$this->assertTrue( $result, 'The first submission wins the lock and refunds normally.' );
		$this->assertCount( 1, $client->refund_calls );
		$this->assertInstanceOf( WP_Error::class, $client->rival_result, 'The loser\'s acquisition must report failure to the admin who double-submitted.' );
		$this->assertSame( array(), $rival_client->refund_calls, 'Exactly one money-moving refund may be created; the customer must not be refunded twice.' );
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
		$this->assertFalse( $this->order_lock_held( 403 ) );
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
		$this->assertFalse( $this->order_lock_held( 404 ), 'The lock must be released once confirmation finishes.' );
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
		$this->assertFalse( $this->order_lock_held( 405 ), 'The lock must be released once the check finishes.' );
	}
}
