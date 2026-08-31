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
						'detail'     => array(
							'amount' => array(
								'currency' => 'NZD',
								'total'    => '49.95',
							),
						),
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
		$this->assertSame( 'pending', $order->get_status(), 'The customer is paying the order again: it must not sit failed under them.' );
	}

	public function test_a_retry_after_a_rejected_consent_discards_the_previous_attempts_state() {
		$order = $this->register_order( 708 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-708' );
		// Everything the rejected first attempt left behind: its payment ID,
		// how it settled, an exhausted check counter, a mismatch flag and an
		// exhausted contended-lock retry counter.
		$order->update_meta_data( '_blinkpay_payment_id', 'pay-old-rejected' );
		$order->update_meta_data( '_blinkpay_accepted_reason', 'card_network_accepted' );
		$order->update_meta_data( '_blinkpay_status_checks', 45 );
		$order->update_meta_data( '_blinkpay_amount_mismatch_flagged', 'yes' );
		$order->update_meta_data( '_blinkpay_lock_retries', WC_BlinkPay_Gateway::ORDER_LOCK_MAX_RETRIES + 1 );

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

		$result = $gateway->process_payment( 708 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( '', $order->get_meta( '_blinkpay_payment_id' ), 'The dead payment ID must not survive to misdirect a later refund.' );
		$this->assertSame( '', $order->get_meta( '_blinkpay_accepted_reason' ) );
		$this->assertSame( '', $order->get_meta( '_blinkpay_status_checks' ), 'An exhausted counter would deny the new debit its deferred checks.' );
		$this->assertSame( '', $order->get_meta( '_blinkpay_amount_mismatch_flagged' ) );
		$this->assertSame( '', $order->get_meta( '_blinkpay_lock_retries' ), 'An exhausted retry counter would kill the fresh attempt\'s polling on its first contended check.' );
		$this->assertCount( 1, $GLOBALS['wc_blinkpay_scheduled_events'], 'The fresh attempt must get its own deferred checks.' );
	}

	public function test_a_refund_after_a_retried_checkout_targets_the_new_payment() {
		$order = $this->register_order( 709 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-709' );
		$order->update_meta_data( '_blinkpay_payment_id', 'pay-old-rejected' );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(
				array(
					'quick_payment_id' => 'qp-fresh',
					'redirect_uri'     => 'https://gateway.test/pay/qp-fresh',
				),
			),
			array(
				// The retry's confirmation sees the rejected first attempt;
				// the deferred check then sees the fresh payment settled.
				$this->consent( 'Rejected' ),
				$this->consent(
					'Consumed',
					array(
						'payment_id' => 'pay-new-settled',
						'status'     => 'AcceptedSettlementCompleted',
						'detail'     => array(
							'amount' => array(
								'currency' => 'NZD',
								'total'    => '49.95',
							),
						),
					)
				),
			),
			array( array( 'refund_id' => 'rf-709' ) ),
			array(
				array(
					'refund_id'      => 'rf-709',
					'status'         => 'completed',
					'account_number' => '12-3456-7890123-00',
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->process_payment( 709 );
		$gateway->check_payment_status( 709 );

		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'pay-new-settled', $order->get_meta( '_blinkpay_payment_id' ) );

		$result = $gateway->process_refund( 709, 49.95, '' );

		$this->assertTrue( $result );
		$this->assertSame( 'pay-new-settled', $client->refund_calls[0]['payment_id'], 'The refund must be sent against the payment that settled, never the rejected first attempt.' );
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

	public function test_a_retry_against_a_revoked_consent_with_a_settling_debit_creates_no_second_payment() {
		$order = $this->register_order( 711 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-711' );

		// Anomalous but money-critical: the consent reads terminal while its
		// payment is still settling. 'failed' means "no money moved" to the
		// retry path, so this must stay pending.
		$client  = new WC_BlinkPay_Fake_API_Client(
			array(
				array(
					'quick_payment_id' => 'qp-second',
					'redirect_uri'     => 'https://gateway.test/pay/qp-second',
				),
			),
			array(
				$this->consent(
					'Revoked',
					array(
						'payment_id' => 'pay-711',
						'status'     => 'AcceptedSettlementInProcess',
					)
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 711 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), $client->create_calls, 'A debit may still be settling: a second quick payment must not be created.' );
		$this->assertSame( 'qp-711', $order->get_meta( '_blinkpay_quick_payment_id' ), 'The stored ID must keep pointing at the debit that may still settle.' );
		$this->assertCount( 1, $GLOBALS['wc_blinkpay_scheduled_events'], 'The deferred checks must keep polling the in-flight debit.' );
	}

	public function test_a_retry_prefers_a_settling_sibling_payment_over_a_rejected_one() {
		$order = $this->register_order( 712 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-712' );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(
				array(
					'consent' => array(
						'status'   => 'Revoked',
						'payments' => array(
							array(
								'payment_id' => 'pay-712-rejected',
								'status'     => 'Rejected',
							),
							array(
								'payment_id' => 'pay-712-settling',
								'status'     => 'AcceptedSettlementInProcess',
							),
						),
					),
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 712 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), $client->create_calls, 'A rejected sibling must not outrank money possibly in motion.' );
		$this->assertSame( 'qp-712', $order->get_meta( '_blinkpay_quick_payment_id' ) );
	}

	public function test_a_retry_after_a_rejected_consent_with_a_rejected_payment_creates_a_fresh_payment() {
		$order = $this->register_order( 713 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-713' );

		// Every payment record is rejected: no money moved is confirmed, so
		// the fresh creation stays possible.
		$client  = new WC_BlinkPay_Fake_API_Client(
			array(
				array(
					'quick_payment_id' => 'qp-fresh',
					'redirect_uri'     => 'https://gateway.test/pay/qp-fresh',
				),
			),
			array(
				$this->consent(
					'Rejected',
					array(
						'payment_id' => 'pay-713',
						'status'     => 'Rejected',
					)
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 713 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertCount( 1, $client->create_calls );
		$this->assertSame( 'qp-fresh', $order->get_meta( '_blinkpay_quick_payment_id' ) );
	}

	public function test_a_retry_whose_fresh_creation_fails_leaves_no_stale_attempt_behind() {
		$order = $this->register_order( 710 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-dead' );
		$order->update_meta_data( '_blinkpay_status_checks', 45 );

		// The previous consent is confirmed rejected, but creating the fresh
		// quick payment fails: no canned create response models an API error.
		$client  = new WC_BlinkPay_Fake_API_Client( array(), array( $this->consent( 'Rejected' ) ) );
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 710 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 'failed', $order->get_status(), 'No fresh attempt exists: the order must not sit pending.' );
		$this->assertSame( '', $order->get_meta( '_blinkpay_quick_payment_id' ), 'The dead quick payment ID must not survive as if an attempt were live.' );
		$this->assertFalse( $gateway->has_unresolved_quick_payment( $order ), 'A stranded order must not be exempt from the unpaid-order sweep.' );
	}

	public function test_a_retry_is_refused_while_another_process_holds_the_lock() {
		$order = $this->register_order( 706 );
		$order->update_meta_data( '_blinkpay_quick_payment_id', 'qp-706' );

		update_option( 'wc_blinkpay_order_706.lock', time() . ':held-by-another-process' );

		$client  = new WC_BlinkPay_Fake_API_Client();
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_payment( 706 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), $client->get_calls, 'A contended lock means another process owns the outcome; the retry must not confirm concurrently.' );
		$this->assertSame( array(), $client->create_calls );

		// The lock belongs to the other process and must survive this request.
		$this->assertNotFalse( get_option( 'wc_blinkpay_order_706.lock' ) );
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
		$this->assertFalse( get_option( 'wc_blinkpay_order_707.lock' ), 'The lock must be released on the already-paid path.' );
	}
}
