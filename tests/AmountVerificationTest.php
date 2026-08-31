<?php
/**
 * BDL-1622: the paid amount must be verified against the order total before
 * the order completes — the request body is filterable by third-party code —
 * and what the customer was actually charged (total_charge, including any
 * surcharge Blink applied on the hosted gateway) must be recorded against
 * the order so surcharged payments can be reconciled.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class AmountVerificationTest extends TestCase {

	protected function setUp(): void {
		wc_blinkpay_tests_reset();
	}

	/**
	 * @param array $amount The payment's amount model.
	 * @return array A completed payment carrying that amount.
	 */
	private function completed_payment( array $amount ) {
		return array(
			'payment_id' => 'pay-1',
			'status'     => 'AcceptedSettlementCompleted',
			'detail'     => array(
				'consent_id' => 'consent-1',
				'amount'     => $amount,
			),
		);
	}

	public function test_a_payment_matching_the_order_total_completes_and_records_the_charge() {
		// The order stub's total is 49.95.
		$order   = new WC_BlinkPay_Test_Order( 601 );
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$outcome = $gateway->apply_payment_result(
			$order,
			$this->completed_payment(
				array(
					'currency' => 'NZD',
					'total'    => '49.95',
				)
			)
		);

		$this->assertSame( 'paid', $outcome );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( '49.95', $order->get_meta( '_blinkpay_total_charge' ) );
		$this->assertSame( '', $order->get_meta( '_blinkpay_surcharge' ) );
	}

	public function test_a_surcharged_payment_records_the_surcharge_and_total_charge() {
		$order   = new WC_BlinkPay_Test_Order( 602 );
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$outcome = $gateway->apply_payment_result(
			$order,
			$this->completed_payment(
				array(
					'currency'     => 'NZD',
					'total'        => '49.95',
					'surcharge'    => '0.59',
					'total_charge' => '50.54',
				)
			)
		);

		$this->assertSame( 'paid', $outcome );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( '50.54', $order->get_meta( '_blinkpay_total_charge' ) );
		$this->assertSame( '0.59', $order->get_meta( '_blinkpay_surcharge' ) );

		$surcharge_notes = array_filter(
			$order->notes,
			function ( $note ) {
				return false !== strpos( $note, 'surcharge' );
			}
		);
		$this->assertCount( 1, $surcharge_notes );
		$note = reset( $surcharge_notes );
		$this->assertStringContainsString( '50.54', $note );
		$this->assertStringContainsString( '0.59', $note );
	}

	public function test_an_underpaid_order_is_flagged_for_the_merchant_instead_of_completing() {
		$order   = new WC_BlinkPay_Test_Order( 603 );
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$outcome = $gateway->apply_payment_result(
			$order,
			$this->completed_payment(
				array(
					'currency' => 'NZD',
					'total'    => '10.00',
				)
			)
		);

		$this->assertSame( 'pending', $outcome );
		$this->assertFalse( $order->is_paid() );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( '10.00', $order->notes[0] );
		$this->assertStringContainsString( '49.95', $order->notes[0] );
		$this->assertStringContainsString( 'not been completed automatically', $order->notes[0] );
	}

	public function test_an_underpayment_is_flagged_once_across_repeated_status_checks() {
		$order   = new WC_BlinkPay_Test_Order( 604 );
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$payment = $this->completed_payment(
			array(
				'currency' => 'NZD',
				'total'    => '10.00',
			)
		);

		$gateway->apply_payment_result( $order, $payment );
		$gateway->apply_payment_result( $order, $payment );

		$this->assertCount( 1, $order->notes );
	}

	public function test_an_overpaid_order_is_flagged_for_the_merchant_instead_of_completing() {
		$order   = new WC_BlinkPay_Test_Order( 606 );
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		// The payload is filterable, so an inflated amount is as wrong as a
		// short one — a legitimate surcharge lives in total_charge, not total.
		$outcome = $gateway->apply_payment_result(
			$order,
			$this->completed_payment(
				array(
					'currency' => 'NZD',
					'total'    => '100.00',
				)
			)
		);

		$this->assertSame( 'pending', $outcome );
		$this->assertFalse( $order->is_paid() );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( '100.00', $order->notes[0] );
		$this->assertStringContainsString( '49.95', $order->notes[0] );
	}

	public function test_the_inline_poll_does_not_contradict_a_flagged_mismatch() {
		$order = new WC_BlinkPay_Test_Order( 607 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-607' );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(
				array(
					'consent' => array(
						'status'   => 'Consumed',
						'payments' => array(
							$this->completed_payment(
								array(
									'currency' => 'NZD',
									'total'    => '10.00',
								)
							),
						),
					),
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$outcome = $gateway->confirm_quick_payment( $order );

		$this->assertSame( 'pending', $outcome );
		$this->assertTrue( $order->has_status( 'on-hold' ) );

		// The mismatch note stands alone: re-polling reads the same payment,
		// and the generic "not yet confirmed" note would contradict it.
		$this->assertCount( 1, $client->get_calls );
		$this->assertCount( 1, $order->notes );
		$this->assertStringNotContainsString( 'not yet confirmed', $order->notes[0] );

		// The order stays in the polling pool for the deferred checks.
		$this->assertNotFalse( wp_next_scheduled( WC_BlinkPay_Gateway::STATUS_CHECK_HOOK, array( 607 ) ) );
	}

	public function test_a_payment_without_a_reported_amount_still_completes() {
		$order   = new WC_BlinkPay_Test_Order( 605 );
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$outcome = $gateway->apply_payment_result(
			$order,
			array(
				'payment_id' => 'pay-605',
				'status'     => 'AcceptedSettlementCompleted',
			)
		);

		$this->assertSame( 'paid', $outcome );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( '', $order->get_meta( '_blinkpay_total_charge' ) );
	}
}
