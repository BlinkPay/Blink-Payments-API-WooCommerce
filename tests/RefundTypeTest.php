<?php
/**
 * BDL-1620: the refund type must be selected from how the payment settled
 * (accepted_reason). A card payment is refunded through the card network —
 * full_refund for the whole order total, partial_refund carrying the amount
 * otherwise, with the status confirmed afterwards because a 201 does not
 * mean the money moved. A bank payment uses the account_number type, and the
 * outstanding manual obligation is made obvious beyond a private note.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class RefundTypeTest extends TestCase {

	protected function setUp(): void {
		wc_blinkpay_tests_reset();
	}

	/**
	 * @param int    $order_id        The order ID to register.
	 * @param string $accepted_reason The stored accepted_reason, '' for none.
	 * @return WC_BlinkPay_Test_Order
	 */
	private function register_paid_order( $order_id, $accepted_reason = 'card_network_accepted' ) {
		$order = new WC_BlinkPay_Test_Order( $order_id );
		$order->update_meta_data( '_blinkpay_payment_id', 'pay-' . $order_id );
		if ( '' !== $accepted_reason ) {
			$order->update_meta_data( '_blinkpay_accepted_reason', $accepted_reason );
		}
		$order->payment_complete( 'pay-' . $order_id );

		$GLOBALS['wc_blinkpay_test_orders'][ $order_id ] = $order;

		return $order;
	}

	public function test_completing_a_payment_records_the_accepted_reason() {
		$order   = new WC_BlinkPay_Test_Order( 400 );
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$outcome = $gateway->apply_payment_result(
			$order,
			array(
				'payment_id'      => 'pay-400',
				'status'          => 'AcceptedSettlementCompleted',
				'accepted_reason' => 'card_network_accepted',
			)
		);

		$this->assertSame( 'paid', $outcome );
		$this->assertSame( 'card_network_accepted', $order->get_meta( '_blinkpay_accepted_reason' ) );
	}

	public function test_refunding_a_card_payment_in_full_requests_a_full_refund() {
		$order  = $this->register_paid_order( 401 );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-401' ) ),
			array(
				array(
					'refund_id' => 'rf-401',
					'status'    => 'completed',
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		// The order stub's total is 49.95.
		$result = $gateway->process_refund( 401, 49.95, 'Changed their mind.' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $client->refund_calls );

		$payload = $client->refund_calls[0];
		$this->assertSame( 'full_refund', $payload['type'] );
		$this->assertSame( 'pay-401', $payload['payment_id'] );
		$this->assertArrayHasKey( 'pcr', $payload );
		$this->assertArrayNotHasKey( 'amount', $payload );

		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( 'rf-401', $order->notes[0] );
		$this->assertStringContainsString( 'completed', $order->notes[0] );
	}

	public function test_a_partial_card_refund_carries_the_amount_to_the_api() {
		$order  = $this->register_paid_order( 402 );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-402' ) ),
			array(
				array(
					'refund_id' => 'rf-402',
					'status'    => 'processing',
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 402, 10.5, 'Damaged item.' );

		$this->assertTrue( $result );

		$payload = $client->refund_calls[0];
		$this->assertSame( 'partial_refund', $payload['type'] );
		$this->assertArrayHasKey( 'pcr', $payload );
		$this->assertSame(
			array(
				'currency' => 'NZD',
				'total'    => '10.50',
			),
			$payload['amount']
		);

		// A 201 does not mean the money moved: a still-processing refund tells
		// the merchant to verify it completes.
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( 'merchant portal', $order->notes[0] );
	}

	public function test_a_bank_settled_payment_uses_an_account_number_refund() {
		$order  = $this->register_paid_order( 403, 'source_bank_payment_sent' );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-403' ) ),
			array(
				array(
					'refund_id'      => 'rf-403',
					'account_number' => '01-2345-6789012-00',
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 403, 49.95, '' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $client->refund_calls );
		$this->assertSame(
			array(
				'type'       => 'account_number',
				'payment_id' => 'pay-403',
			),
			$client->refund_calls[0]
		);

		// The private note carries the obligation and the account number.
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( 'does not move money', $order->notes[0] );
		$this->assertStringContainsString( '01-2345-6789012-00', $order->notes[0] );

		// The obligation is also obvious beyond the private note: the customer
		// is told to expect a bank transfer, without the account number.
		$this->assertCount( 1, $order->customer_notes );
		$this->assertStringContainsString( 'bank transfer', $order->customer_notes[0] );
		$this->assertStringNotContainsString( '01-2345-6789012-00', $order->customer_notes[0] );
	}

	public function test_a_payment_without_a_recorded_reason_defaults_to_an_account_number_refund() {
		$this->register_paid_order( 404, '' );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-404' ) ),
			array(
				array(
					'refund_id'      => 'rf-404',
					'account_number' => '01-2345-6789012-00',
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 404, 49.95, '' );

		$this->assertTrue( $result );
		$this->assertSame( 'account_number', $client->refund_calls[0]['type'] );
	}

	public function test_a_card_refund_creation_failure_surfaces_the_error() {
		$order  = $this->register_paid_order( 405 );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array(
				new WP_Error(
					'blinkpay_api_error',
					'BlinkPay request failed with HTTP 503.',
					array(
						'status' => 503,
						'body'   => null,
					)
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 405, 49.95, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $client->refund_calls );
		$this->assertSame( array(), $order->notes );
		$this->assertSame( array(), $order->customer_notes );
	}

	public function test_a_refund_reported_failed_rejects_the_woocommerce_refund() {
		$order  = $this->register_paid_order( 406 );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-406' ) ),
			array(
				array(
					'refund_id' => 'rf-406',
					'status'    => 'failed',
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 406, 49.95, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'no money has moved', $result->get_error_message() );
		$this->assertSame( array(), $order->notes );
	}

	public function test_a_refund_needing_merchant_authorisation_surfaces_the_consent_redirect() {
		$order  = $this->register_paid_order( 407 );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-407' ) ),
			array(
				array(
					'refund_id' => 'rf-407',
					'status'    => 'processing',
					'detail'    => array( 'consent_redirect' => 'https://bank.test/authorise/rf-407' ),
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 407, 49.95, '' );

		$this->assertTrue( $result );
		$this->assertCount( 2, $order->notes );
		$this->assertStringContainsString( 'https://bank.test/authorise/rf-407', $order->notes[1] );
	}

	public function test_the_account_number_refund_surfaces_an_error_when_the_account_number_is_missing() {
		$order  = $this->register_paid_order( 408, 'source_bank_payment_sent' );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-408' ) ),
			array( new WP_Error( 'blinkpay_api_error', 'BlinkPay request failed with HTTP 500.' ) )
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 408, 49.95, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $order->notes );
		$this->assertSame( array(), $order->customer_notes );
	}
}
