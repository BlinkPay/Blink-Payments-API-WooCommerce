<?php
/**
 * BDL-1618: the polling schedule must back off progressively and span the
 * overnight bank settlement window (~36 hours), so an order placed in the
 * evening settles automatically the following morning instead of exhausting
 * a 30-minute budget and parking for manual reconciliation.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class StatusCheckBackoffTest extends TestCase {

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

	/**
	 * @return WC_BlinkPay_Test_Gateway A gateway whose retrievals always report a still-pending consent.
	 */
	private function gateway_with_pending_consent() {
		return new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Fake_API_Client(
				array(),
				array( array( 'consent' => array( 'status' => 'AwaitingAuthorisation' ) ) )
			)
		);
	}

	public function test_the_schedule_spans_the_overnight_settlement_window() {
		$total_seconds = 0;
		$total_checks  = 0;
		foreach ( WC_BlinkPay_Gateway::STATUS_CHECK_SCHEDULE as $tier ) {
			list( $checks, $delay ) = $tier;
			$total_seconds         += $checks * $delay;
			$total_checks          += $checks;
		}

		$this->assertSame( 36 * 3600, $total_seconds );
		$this->assertSame( 45, $total_checks );
	}

	public function test_the_delay_backs_off_progressively() {
		$gateway = $this->gateway_with_pending_consent();

		// Every minute for the first 10 minutes.
		$this->assertSame( 60, $gateway->get_status_check_delay( 0 ) );
		$this->assertSame( 60, $gateway->get_status_check_delay( 9 ) );

		// Every 5 minutes until roughly 1 hour.
		$this->assertSame( 300, $gateway->get_status_check_delay( 10 ) );
		$this->assertSame( 300, $gateway->get_status_check_delay( 19 ) );

		// Every 30 minutes until roughly 6 hours.
		$this->assertSame( 1800, $gateway->get_status_check_delay( 20 ) );
		$this->assertSame( 1800, $gateway->get_status_check_delay( 29 ) );

		// Every 2 hours until 36 hours.
		$this->assertSame( 7200, $gateway->get_status_check_delay( 30 ) );
		$this->assertSame( 7200, $gateway->get_status_check_delay( 44 ) );

		// Exhausted.
		$this->assertFalse( $gateway->get_status_check_delay( 45 ) );
		$this->assertFalse( $gateway->get_status_check_delay( 100 ) );
	}

	public function test_a_still_pending_payment_reschedules_with_the_backed_off_delay() {
		$order = $this->register_order( 201 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-201' );
		$order->update_meta_data( '_blinkpay_status_checks', 9 );
		$order->update_status( 'on-hold' );

		$gateway = $this->gateway_with_pending_consent();

		$before = time();
		$gateway->check_payment_status( 201 );

		// The tenth check has run, so the eleventh is spaced 5 minutes out.
		$this->assertSame( 10, $order->get_meta( '_blinkpay_status_checks' ) );
		$events = $GLOBALS['wc_blinkpay_scheduled_events'];
		$this->assertCount( 1, $events );
		$this->assertGreaterThanOrEqual( $before + 300, $events[0]['timestamp'] );
	}

	public function test_an_evening_order_survives_the_overnight_window_and_completes() {
		$order = $this->register_order( 202 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-202' );
		// 12 hours in: an evening order deep into the overnight window under
		// the old 30-check budget, which would already have given up.
		$order->update_meta_data( '_blinkpay_status_checks', 27 );
		$order->update_status( 'on-hold' );

		$gateway = new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Fake_API_Client(
				array(),
				array(
					array(
						'consent' => array(
							'status'   => 'Authorised',
							'payments' => array(
								array(
									'payment_id' => 'pay-202',
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

		$gateway->check_payment_status( 202 );

		$this->assertTrue( $order->is_paid() );
		$this->assertCount( 0, $GLOBALS['wc_blinkpay_scheduled_events'] );
	}

	public function test_the_exhaustion_note_is_only_written_once_the_window_is_spent() {
		$order = $this->register_order( 203 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-203' );
		$order->update_meta_data( '_blinkpay_status_checks', 43 );
		$order->update_status( 'on-hold' );

		$gateway = $this->gateway_with_pending_consent();

		// The penultimate check reschedules without complaint.
		$gateway->check_payment_status( 203 );
		$this->assertCount( 1, $GLOBALS['wc_blinkpay_scheduled_events'] );
		$this->assertSame( array(), $order->notes );

		// The final check exhausts the schedule and hands over to the merchant.
		$GLOBALS['wc_blinkpay_scheduled_events'] = array();
		$gateway->check_payment_status( 203 );

		$this->assertSame( 45, $order->get_meta( '_blinkpay_status_checks' ) );
		$this->assertCount( 0, $GLOBALS['wc_blinkpay_scheduled_events'] );
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( '36 hours', $order->notes[0] );
	}
}
