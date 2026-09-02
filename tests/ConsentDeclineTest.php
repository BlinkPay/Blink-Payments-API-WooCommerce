<?php
/**
 * BDL-1625: a quick payment carries a Rejected payment record when the
 * consent itself was declined, so the order note must consult the consent
 * status to tell a customer decline from a payment the bank rejected after
 * authorisation — "rejected by the bank" sends merchant support after the
 * wrong party.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class ConsentDeclineTest extends TestCase {

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

	public function test_a_declined_consent_is_not_blamed_on_the_bank() {
		$order   = $this->register_order( 601 );
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$outcome = $gateway->evaluate_consent(
			$order,
			array(
				'status'   => 'Rejected',
				'payments' => array(
					array(
						'payment_id' => 'pay-601',
						'status'     => 'Rejected',
					),
				),
			)
		);

		$this->assertSame( 'failed', $outcome );
		$this->assertSame( 'failed', $order->get_status() );

		$note = end( $order->notes );
		$this->assertStringContainsString( 'declined', $note );
		$this->assertStringNotContainsString( 'rejected by the bank', $note, 'A declined consent must not be recorded as a bank rejection.' );
	}

	public function test_a_bank_rejection_after_authorisation_keeps_the_bank_wording() {
		$order   = $this->register_order( 602 );
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$outcome = $gateway->evaluate_consent(
			$order,
			array(
				'status'   => 'Consumed',
				'payments' => array(
					array(
						'payment_id' => 'pay-602',
						'status'     => 'Rejected',
					),
				),
			)
		);

		$this->assertSame( 'failed', $outcome );
		$this->assertStringContainsString( 'rejected by the bank', end( $order->notes ) );
	}

	public function test_a_settled_payment_is_applied_even_when_it_is_not_the_first_in_the_list() {
		$order   = $this->register_order( 603 );
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		// A one-off quick payment carries at most one payment, but the array
		// shape allows more: money moved must outrank whatever sits first.
		$outcome = $gateway->evaluate_consent(
			$order,
			array(
				'status'   => 'Consumed',
				'payments' => array(
					array(
						'payment_id' => 'pay-603-pending',
						'status'     => 'Pending',
					),
					array(
						'payment_id' => 'pay-603-settled',
						'status'     => 'AcceptedSettlementCompleted',
						'detail'     => array(
							'amount' => array(
								'currency' => 'NZD',
								'total'    => '49.95',
							),
						),
					),
				),
			)
		);

		$this->assertSame( 'paid', $outcome );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'pay-603-settled', $order->get_meta( '_blinkpay_payment_id' ), 'The settled payment, not the first listed, must be the one recorded.' );
	}
}
