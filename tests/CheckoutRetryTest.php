<?php
/**
 * BDL-1627: the order-pay link stays live while an order is pending, so a
 * customer who authorised at their bank but never returned to the site can
 * pay the same order again. A retried checkout must confirm the order's
 * existing quick payment through the API before any new payment may be
 * created: a live or settling debit blocks a second creation, a settled one
 * completes the order, and only a confirmed terminal outcome with no money
 * moved lets a fresh quick payment be created over the stored ID.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class CheckoutRetryTest extends TestCase {

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
	 * A canned retrieval response carrying one consent, optionally with one
	 * payment.
	 *
	 * @param string     $consent_status The consent status.
	 * @param array|null $payment        The payment model, if any.
	 * @return array
	 */
	private function consent( $consent_status, $payment = null ) {
		return array(
			'consent' => array(
				'status'   => $consent_status,
				'payments' => null === $payment ? array() : array( $payment ),
			),
		);
	}

	public function test_a_retry_against_a_live_quick_payment_creates_no_second_payment() {
		$order = $this->register_order( 701 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-701' );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(
				array(
					'quick_payment_id' => 'qp-second',
					'redirect_uri'     => 'https://gateway.test/pay/qp-second',
				),
			),
			array( $this->consent( 'AwaitingAuthorisation' ) )
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 701 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), $client->create_calls, 'The previous consent is still live: a second quick payment must not be created.' );
		$this->assertSame( 'qp-701', $order->get_meta( '_blinkpay_quick_payment_id' ), 'The stored quick payment ID must never be overwritten while its debit may still settle.' );
		$this->assertSame( 'on-hold', $order->get_status(), 'The order is parked, which also takes the order-pay link out of service.' );
		$this->assertCount( 1, $GLOBALS['wc_blinkpay_scheduled_events'], 'The deferred checks must resolve the outstanding attempt.' );
		$this->assertStringContainsString( 'a new payment has not been started', $GLOBALS['wc_blinkpay_notices'][0]['message'] );
	}

	public function test_a_retry_against_a_settling_debit_creates_no_second_payment() {
		$order = $this->register_order( 702 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-702' );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(
				$this->consent(
					'Consumed',
					array(
						'payment_id' => 'pay-702',
						'status'     => 'AcceptedSettlementInProcess',
					)
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 702 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), $client->create_calls, 'A debit in settlement is money in motion: a second quick payment must not be created.' );
		$this->assertSame( 'qp-702', $order->get_meta( '_blinkpay_quick_payment_id' ) );
	}

	public function test_a_retry_completes_the_order_when_the_previous_payment_settled() {
		$order = $this->register_order( 703 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-703' );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(
				$this->consent(
					'Consumed',
					array(
						'payment_id' => 'pay-703',
						'status'     => 'AcceptedSettlementCompleted',
					)
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 703 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://example.test/order-received/703/', $result['redirect'] );
		$this->assertTrue( $order->is_paid(), 'The customer already paid: the retry must apply the settlement, not charge them again.' );
		$this->assertSame( array(), $client->create_calls );
	}

	public function test_a_retry_after_a_rejected_consent_creates_a_fresh_payment() {
		$order = $this->register_order( 704 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-704' );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(
				array(
					'quick_payment_id' => 'qp-fresh',
					'redirect_uri'     => 'https://gateway.test/pay/qp-fresh',
				),
			),
			array( $this->consent( 'Rejected' ) )
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 704 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://gateway.test/pay/qp-fresh', $result['redirect'] );
		$this->assertCount( 1, $client->create_calls, 'A confirmed rejection means no money moved, so a fresh payment is safe.' );
		$this->assertSame( 'qp-fresh', $order->get_meta( '_blinkpay_quick_payment_id' ) );
	}

	public function test_a_retry_with_an_unconfirmable_previous_attempt_creates_no_second_payment() {
		$order = $this->register_order( 705 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-705' );

		// No canned retrieval responses: every confirmation attempt errors,
		// so the outcome of the previous debit is unknown.
		$client  = new WC_BlinkPay_Fake_API_Client();
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 705 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), $client->create_calls, 'An unknown outcome must fail towards no second debit, not towards a fresh payment.' );
		$this->assertSame( 'on-hold', $order->get_status(), 'The unresolved attempt is parked for the deferred checks to settle.' );
	}

	public function test_a_retry_is_refused_while_another_process_holds_the_lock() {
		$order = $this->register_order( 706 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-706' );

		set_transient( 'wc_blinkpay_order_lock_706', time(), WC_BlinkPay_Gateway::ORDER_LOCK_TIMEOUT );

		$client  = new WC_BlinkPay_Fake_API_Client();
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 706 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), $client->get_calls, 'A contended lock means another process owns the outcome; the retry must not confirm concurrently.' );
		$this->assertSame( array(), $client->create_calls );

		// The lock belongs to the other process and must survive this request.
		$this->assertNotFalse( get_transient( 'wc_blinkpay_order_lock_706' ) );
	}

	public function test_a_retry_redirects_to_the_received_page_when_the_order_is_already_paid() {
		$order = $this->register_order( 707 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-707' );
		$order->payment_complete( 'pay-707' );

		$client  = new WC_BlinkPay_Fake_API_Client();
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 707 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://example.test/order-received/707/', $result['redirect'] );
		$this->assertSame( array(), $client->get_calls, 'A paid order needs no confirmation and no payment.' );
		$this->assertFalse( get_transient( 'wc_blinkpay_order_lock_707' ), 'The lock must be released on the already-paid path.' );
	}
}
