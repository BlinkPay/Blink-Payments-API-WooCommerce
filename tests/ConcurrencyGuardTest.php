<?php
/**
 * BDL-1623: the return page's inline poll and the deferred cron check can run
 * concurrently for one order, so a terminal outcome may only be applied under
 * the per-order lock, on a fresh read. A contended lock means another process
 * owns the outcome: the return page shows the order as it stands, and the
 * cron check reschedules itself without advancing the attempt counter.
 *
 * BDL-1630/BDL-1634: acquisition is atomic — an INSERT IGNORE against the
 * options table's unique key — so exactly one of two concurrent callers
 * acquires, and a double-submitted refund loses the race instead of
 * refunding the customer twice. Release is token-checked, so a holder that
 * overran the timeout can never free its successor's lock.
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

	public function release( $order_id, $token ) {
		$this->release_order_lock( $order_id, $token );
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

/**
 * A client whose quick payment creation fires a rival gateway's checkout for
 * the same order mid-flight, reproducing the double submission the lock must
 * resolve to exactly one winner: a double-click, a browser retry on a slow
 * POST, or two order-pay tabs.
 */
class WC_BlinkPay_Double_Checkout_Client extends WC_BlinkPay_Fake_API_Client {

	/** @var WC_BlinkPay_Test_Gateway|null Fired once, on the first creation. */
	public $rival_gateway;

	/** @var int */
	public $rival_order_id;

	/** @var array|null What the rival's process_payment() returned. */
	public $rival_result;

	public function create_quick_payment( $payload, $idempotency_key ) {
		if ( $this->rival_gateway ) {
			$rival               = $this->rival_gateway;
			$this->rival_gateway = null;
			$this->rival_result  = $rival->process_payment( $this->rival_order_id );
		}

		return parent::create_quick_payment( $payload, $idempotency_key );
	}
}

/**
 * A client that throws mid-refund, standing in for a third-party hook or
 * transport layer exploding while the per-order lock is held.
 */
class WC_BlinkPay_Throwing_Refund_Client extends WC_BlinkPay_Fake_API_Client {

	public function create_refund( array $payload ) {
		throw new RuntimeException( 'A third-party hook exploded mid-refund.' );
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
		update_option( 'wc_blinkpay_order_' . $order_id . '.lock', time() . ':held-by-another-process' );
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
						'detail'     => array(
							'amount' => array(
								'currency' => 'NZD',
								'total'    => '49.95',
							),
						),
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

	public function test_a_checkout_double_submission_creates_exactly_one_quick_payment() {
		$order = $this->register_order( 411 );

		$rival_client = new WC_BlinkPay_Fake_API_Client(
			array(
				array(
					'quick_payment_id' => 'qp-411-b',
					'redirect_uri'     => 'https://gateway.test/pay/qp-411-b',
				),
			)
		);
		$rival        = new WC_BlinkPay_Test_Gateway( $rival_client );

		$client                 = new WC_BlinkPay_Double_Checkout_Client(
			array(
				array(
					'quick_payment_id' => 'qp-411-a',
					'redirect_uri'     => 'https://gateway.test/pay/qp-411-a',
				),
			)
		);
		$client->rival_gateway  = $rival;
		$client->rival_order_id = 411;

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 411 );

		$this->assertSame( 'success', $result['result'], 'The first submission wins the lock and checks out normally.' );
		$this->assertCount( 1, $client->create_calls );
		$this->assertSame( 'failure', $client->rival_result['result'], 'The loser must be refused, not mint a second quick payment.' );
		$this->assertSame( array(), $rival_client->create_calls, 'Exactly one quick payment may be created for one order.' );
		$this->assertSame( 'qp-411-a', $order->get_meta( '_blinkpay_quick_payment_id' ), 'The winner\'s quick payment must not be overwritten.' );
		$this->assertFalse( $this->order_lock_held( 411 ), 'The lock must be released once the checkout finishes.' );
	}

	public function test_exactly_one_of_two_concurrent_acquirers_wins_the_order_lock() {
		$first  = new WC_BlinkPay_Lock_Probe_Gateway( new WC_BlinkPay_Fake_API_Client() );
		$second = new WC_BlinkPay_Lock_Probe_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$token = $first->acquire( 408 );
		$this->assertNotFalse( $token );
		$this->assertFalse( $second->acquire( 408 ), 'The lock is held: the loser must be told, not silently overwrite the winner.' );

		$first->release( 408, $token );

		$this->assertNotFalse( $second->acquire( 408 ), 'A released lock must be acquirable again.' );
	}

	public function test_a_crashed_holders_expired_lock_is_broken_and_reacquired() {
		$gateway = new WC_BlinkPay_Lock_Probe_Gateway( new WC_BlinkPay_Fake_API_Client() );

		// A holder that crashed before releasing, longer ago than the timeout.
		update_option( 'wc_blinkpay_order_409.lock', ( time() - WC_BlinkPay_Gateway::ORDER_LOCK_TIMEOUT - 1 ) . ':crashed-holder' );

		$this->assertNotFalse( $gateway->acquire( 409 ), 'An expired lock means a crashed holder: it must be broken, not respected for good.' );
	}

	public function test_an_overrunning_holders_release_cannot_free_the_successors_lock() {
		$overrunner = new WC_BlinkPay_Lock_Probe_Gateway( new WC_BlinkPay_Fake_API_Client() );
		$successor  = new WC_BlinkPay_Lock_Probe_Gateway( new WC_BlinkPay_Fake_API_Client() );

		// The overrunner acquired longer ago than the timeout and is still
		// mid-operation when the successor breaks its expired lock.
		$stale_token = ( time() - WC_BlinkPay_Gateway::ORDER_LOCK_TIMEOUT - 1 ) . ':overrunner';
		update_option( 'wc_blinkpay_order_412.lock', $stale_token );

		$successor_token = $successor->acquire( 412 );
		$this->assertNotFalse( $successor_token, 'The expired lock must be broken and re-acquired.' );

		$overrunner->release( 412, $stale_token );

		$this->assertSame( $successor_token, get_option( 'wc_blinkpay_order_412.lock' ), 'The overrunner may only release its own lock, never the successor\'s.' );
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
		// Contended retries from an earlier run: a check that runs ends them.
		$order->update_meta_data( '_blinkpay_lock_retries', 3 );
		$order->update_status( 'on-hold' );

		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent_with_payment( 'pay-405', 'AcceptedSettlementCompleted' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->check_payment_status( 405 );

		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 1, $order->get_meta( '_blinkpay_status_checks' ) );
		$this->assertSame( '', $order->get_meta( '_blinkpay_lock_retries' ), 'A check that runs must clear the contended-retry counter.' );
		$this->assertFalse( $this->order_lock_held( 405 ), 'The lock must be released once the check finishes.' );
	}

	public function test_the_lock_is_released_when_the_guarded_body_throws() {
		$order = $this->register_order( 414 );
		$order->update_meta_data( '_blinkpay_payment_id', 'pay-414' );
		$order->update_meta_data( '_blinkpay_accepted_reason', 'card_network_accepted' );
		$order->payment_complete( 'pay-414' );

		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Throwing_Refund_Client() );

		try {
			$gateway->process_refund( 414, 49.95, '' );
			$this->fail( 'The throwing client should have propagated.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'A third-party hook exploded mid-refund.', $exception->getMessage() );
		}

		$this->assertFalse( $this->order_lock_held( 414 ), 'An exception inside the guarded body must not leak the lock for the full timeout.' );
	}

	public function test_contended_cron_retries_are_bounded() {
		$order = $this->register_order( 413 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-413' );
		$order->update_meta_data( '_blinkpay_lock_retries', WC_BlinkPay_Gateway::ORDER_LOCK_MAX_RETRIES );
		$order->update_status( 'on-hold' );

		$this->hold_order_lock( 413 );

		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$gateway->check_payment_status( 413 );

		$this->assertSame( array(), $GLOBALS['wc_blinkpay_scheduled_events'], 'The bounded retries are exhausted: no further retry may be scheduled.' );
		$this->assertStringContainsString( 'merchant portal', end( $order->notes ), 'The merchant must be pointed at manual verification when the checks stop.' );
	}
}
