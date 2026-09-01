<?php
/**
 * A manual (non-gateway) refund on a BlinkPay order records money as returned
 * while none has moved, so wc_blinkpay_block_manual_refund() must veto it —
 * and must let gateway refunds, zero-amount restock corrections and other
 * gateways' orders through untouched.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class ManualRefundBlockTest extends TestCase {

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

	public function test_a_money_carrying_manual_refund_on_a_blinkpay_order_is_vetoed() {
		$this->register_order( 601 );

		$this->expectException( WC_BlinkPay_Refund_Blocked_Exception::class );

		wc_blinkpay_block_manual_refund(
			new WC_BlinkPay_Test_Order_Refund( 601 ),
			array(
				'order_id'       => 601,
				'amount'         => 10.00,
				'refund_payment' => false,
			)
		);
	}

	public function test_a_gateway_refund_is_allowed_through() {
		$this->register_order( 602 );
		$this->expectNotToPerformAssertions();

		wc_blinkpay_block_manual_refund(
			new WC_BlinkPay_Test_Order_Refund( 602 ),
			array(
				'order_id'       => 602,
				'amount'         => 10.00,
				'refund_payment' => true,
			)
		);
	}

	public function test_a_zero_amount_restock_correction_is_allowed_through() {
		$this->register_order( 603 );
		$this->expectNotToPerformAssertions();

		wc_blinkpay_block_manual_refund(
			new WC_BlinkPay_Test_Order_Refund( 603 ),
			array(
				'order_id'       => 603,
				'amount'         => 0,
				'refund_payment' => false,
			)
		);
	}

	public function test_an_order_paid_with_another_gateway_is_allowed_through() {
		$order = $this->register_order( 604 );
		$order->set_payment_method( 'stripe' );
		$this->expectNotToPerformAssertions();

		wc_blinkpay_block_manual_refund(
			new WC_BlinkPay_Test_Order_Refund( 604 ),
			array(
				'order_id'       => 604,
				'amount'         => 10.00,
				'refund_payment' => false,
			)
		);
	}
}
