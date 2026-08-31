<?php
/**
 * BDL-1626: WooCommerce's stock-hold sweep (wc_cancel_unpaid_orders()) can
 * cancel a pending order between two deferred checks — the last tier spaces
 * them 2 hours apart, wider than the default 60-minute hold window — so a
 * payment can settle after its order was cancelled. Such an order keeps
 * polling and a settlement is surfaced loudly on hold rather than discarded
 * silently, while orders still awaiting an outcome are kept out of the
 * sweep's reach: parked on hold by the first unresolved check and exempted
 * from the sweep through has_unresolved_quick_payment().
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class CancelledOrderSettlementTest extends TestCase {

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
	 * @param WC_BlinkPay_Gateway $gateway  The gateway.
	 * @param int                 $order_id The order ID for the request.
	 * @return string
	 */
	private function handle_return( $gateway, $order_id ) {
		$_GET = array(
			'order_id' => (string) $order_id,
			'key'      => 'wc_order_test_key',
		);

		try {
			$gateway->handle_return();
		} catch ( WC_BlinkPay_Test_Redirect $redirect ) {
			return $redirect->getMessage();
		}

		$this->fail( 'handle_return() did not redirect.' );
	}

	/**
	 * A canned retrieval response whose consent carries one settled payment.
	 *
	 * @param string $payment_id The payment ID.
	 * @param string $total      The paid amount.
	 * @return array
	 */
	private function consent_with_settled_payment( $payment_id, $total = '49.95' ) {
		return array(
			'consent' => array(
				'status'   => 'Consumed',
				'payments' => array(
					array(
						'payment_id'      => $payment_id,
						'status'          => 'AcceptedSettlementCompleted',
						'accepted_reason' => 'source_bank_paid',
						'detail'          => array(
							'amount' => array(
								'currency' => 'NZD',
								'total'    => $total,
							),
						),
					),
				),
			),
		);
	}

	public function test_a_settled_payment_on_a_cancelled_order_parks_it_on_hold_with_a_prominent_note() {
		$order = $this->register_order( 601 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-601' );
		// Deep in the last tier, where the sweep window fits between checks.
		$order->update_meta_data( '_blinkpay_status_checks', 35 );
		$order->update_status( 'cancelled' );

		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent_with_settled_payment( 'pay-601' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->check_payment_status( 601 );

		$this->assertSame( 'on-hold', $order->get_status(), 'A settled payment on a cancelled order must be parked for the merchant, not discarded.' );
		$this->assertFalse( $order->is_paid(), 'The order must not be completed automatically: its stock was released at cancellation.' );
		$this->assertSame( 'yes', $order->get_meta( '_blinkpay_settled_after_cancellation' ) );
		$this->assertSame( 'pay-601', $order->get_meta( '_blinkpay_payment_id' ) );
		$this->assertSame( 'source_bank_paid', $order->get_meta( '_blinkpay_accepted_reason' ), 'How the payment settled must be recorded so a later refund picks the right type.' );
		$this->assertSame( '49.95', $order->get_meta( '_blinkpay_total_charge' ) );

		$notes = implode( ' ', $order->notes );
		$this->assertStringContainsString( 'already been cancelled', $notes );
		$this->assertStringContainsString( 'pay-601', $notes );
		$this->assertStringContainsString( '49.95', $notes );

		$this->assertSame( array(), $GLOBALS['wc_blinkpay_scheduled_events'], 'A settled payment is terminal: the merchant owns the order now, so no further check may be scheduled.' );
	}

	public function test_a_flagged_settlement_is_noted_once_and_the_order_stays_parked() {
		$order = $this->register_order( 602 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-602' );
		$order->update_status( 'cancelled' );

		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent_with_settled_payment( 'pay-602' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->check_payment_status( 602 );
		$notes_after_flag = count( $order->notes );

		// A stray already-scheduled check firing again, or a customer
		// revisiting the return URL, re-reads the same settled payment.
		$gateway->check_payment_status( 602 );

		$this->assertSame( 'on-hold', $order->get_status(), 'The parked order must stay parked until the merchant acts.' );
		$this->assertFalse( $order->is_paid(), 'Re-reading the settled payment must not complete the order the merchant was told to resolve.' );
		$this->assertCount( $notes_after_flag, $order->notes, 'The settlement must be noted once, not once per poll.' );
	}

	public function test_the_return_page_surfaces_a_settled_payment_on_a_cancelled_order() {
		$order = $this->register_order( 603 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-603' );
		$order->update_status( 'cancelled' );

		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent_with_settled_payment( 'pay-603' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$location = $this->handle_return( $gateway, 603 );

		$this->assertSame( 'https://example.test/order-received/603/', $location );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'yes', $order->get_meta( '_blinkpay_settled_after_cancellation' ) );
		$this->assertSame( array(), $GLOBALS['wc_blinkpay_notices'], 'The customer has paid, so no "you have not been charged" notice may be shown.' );
	}

	public function test_a_rejected_consent_leaves_a_cancelled_order_cancelled() {
		$order = $this->register_order( 604 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-604' );
		$order->update_status( 'cancelled' );

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
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->check_payment_status( 604 );

		$this->assertSame( 'cancelled', $order->get_status(), 'No money moved either way: failing the order would overwrite what may be a deliberate cancellation.' );
		$this->assertStringContainsString( 'Rejected', implode( ' ', $order->notes ) );
		$this->assertSame( array(), $GLOBALS['wc_blinkpay_scheduled_events'], 'A rejected consent is terminal, so no further check may be scheduled.' );
	}

	public function test_a_cancelled_order_with_no_quick_payment_is_left_alone() {
		$order = $this->register_order( 605 );
		$order->update_status( 'cancelled' );

		$client  = new WC_BlinkPay_Fake_API_Client();
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->check_payment_status( 605 );

		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( array(), $client->get_calls, 'A cancelled order with no quick payment has nothing in flight to poll for.' );
		$this->assertSame( '', $order->get_meta( '_blinkpay_status_checks' ) );
	}

	public function test_the_first_unresolved_check_parks_a_pending_order_on_hold() {
		$order = $this->register_order( 606 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-606' );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(
				array(
					'consent' => array(
						'status'   => 'Authorised',
						'payments' => array(),
					),
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->check_payment_status( 606 );

		$this->assertSame( 'on-hold', $order->get_status(), 'A pending order is eligible for the stock-hold sweep, so an unresolved check must park it out of reach.' );
		$this->assertCount( 1, $GLOBALS['wc_blinkpay_scheduled_events'], 'Polling must continue after parking.' );
	}

	public function test_a_pending_order_that_settles_on_the_first_check_is_completed_without_parking() {
		$order = $this->register_order( 607 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-607' );

		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent_with_settled_payment( 'pay-607' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->check_payment_status( 607 );

		$this->assertTrue( $order->is_paid() );
		$this->assertStringNotContainsString( 'held while the deferred checks continue', implode( ' ', $order->notes ) );
	}

	public function test_orders_awaiting_an_outcome_are_exempt_from_the_unpaid_order_sweep() {
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$awaiting = $this->register_order( 608 );
		$awaiting->update_meta_data( '_blinkpay_quick_payment_id', 'qp-608' );
		$this->assertTrue( $gateway->has_unresolved_quick_payment( $awaiting ), 'An order with a live quick payment and checks remaining must not be swept.' );

		$exhausted = $this->register_order( 609 );
		$exhausted->update_meta_data( '_blinkpay_quick_payment_id', 'qp-609' );
		$exhausted->update_meta_data( '_blinkpay_status_checks', 45 );
		$this->assertFalse( $gateway->has_unresolved_quick_payment( $exhausted ), 'Once the checks are exhausted the sweep may reclaim the order.' );

		$no_payment = $this->register_order( 610 );
		$this->assertFalse( $gateway->has_unresolved_quick_payment( $no_payment ), 'An order that never reached the gateway has nothing in flight to protect.' );

		// The check counter only advances when cron runs, so with cron dead
		// it stays at 0 forever; the exemption is bounded by order age too,
		// or every abandoned checkout would hold stock indefinitely.
		$stalled = $this->register_order( 611 );
		$stalled->update_meta_data( '_blinkpay_quick_payment_id', 'qp-611' );
		$stalled->set_date_created( new DateTimeImmutable( '-37 hours' ) );
		$this->assertFalse( $gateway->has_unresolved_quick_payment( $stalled ), 'An order older than the whole check schedule must not stay exempt on a stalled counter.' );
	}

	public function test_a_wrong_amount_settling_on_a_cancelled_order_still_raises_the_cancellation_warning() {
		$order = $this->register_order( 612 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-612' );
		$order->update_status( 'cancelled' );

		// The order stub's total is 49.95; the payment settled for 10.00.
		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent_with_settled_payment( 'pay-612', '10.00' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->check_payment_status( 612 );

		$this->assertTrue( $order->has_status( 'on-hold' ) );
		$this->assertSame( 'yes', $order->get_meta( '_blinkpay_settled_after_cancellation' ), 'The merchant must be told the money moved after cancellation, whatever the amount.' );
		$this->assertStringContainsString( 'after this order had already been cancelled', implode( ' ', $order->notes ) );
	}
}
