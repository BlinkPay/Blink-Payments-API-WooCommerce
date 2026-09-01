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
				'detail'          => array(
					'amount' => array(
						'currency' => 'NZD',
						'total'    => '49.95',
					),
				),
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

	public function test_a_surcharged_payment_is_refunded_as_a_partial_for_the_exact_amount() {
		$order = $this->register_paid_order( 410 );
		$order->update_meta_data( '_blinkpay_surcharge', '0.59' );

		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-410' ) ),
			array(
				array(
					'refund_id' => 'rf-410',
					'status'    => 'completed',
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		// The whole order total is refunded, but the payment carried a
		// surcharge: what full_refund would return is not pinned down by the
		// API contract, so the refund must carry the exact amount instead.
		$result = $gateway->process_refund( 410, 49.95, '' );

		$this->assertTrue( $result );

		$payload = $client->refund_calls[0];
		$this->assertSame( 'partial_refund', $payload['type'] );
		$this->assertSame(
			array(
				'currency' => 'NZD',
				'total'    => '49.95',
			),
			$payload['amount']
		);
	}

	public function test_a_refund_on_an_unpaid_order_is_refused_locally() {
		$order = new WC_BlinkPay_Test_Order( 411 );
		$order->update_meta_data( '_blinkpay_payment_id', 'pay-411' );
		$order->update_status( 'failed', 'The bank rejected the payment.' );

		$GLOBALS['wc_blinkpay_test_orders'][411] = $order;

		$client  = new WC_BlinkPay_Fake_API_Client();
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		// A payment ID is recorded for rejected payments too; refunding one
		// must be refused locally, not fired at the API.
		$result = $gateway->process_refund( 411, 49.95, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'no settled BlinkPay payment', $result->get_error_message() );
		$this->assertSame( array(), $client->refund_calls );
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

		// The private note carries the obligation and points at the order
		// screen's panel; the number itself is never persisted, so no bank
		// account PII lands in order notes, exports or backups.
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( 'does not move money', $order->notes[0] );
		$this->assertStringContainsString( 'manual refunds panel', $order->notes[0] );
		$this->assertStringContainsString( 'rf-403', $order->notes[0] );
		$this->assertStringNotContainsString( '01-2345-6789012-00', $order->notes[0] );

		// The panel's source of truth: only the refund ID, amount and date
		// are recorded against the order.
		$this->assertSame(
			array(
				array(
					'refund_id' => 'rf-403',
					'amount'    => '49.95',
					'recorded'  => gmdate( 'Y-m-d' ),
				),
			),
			$order->get_meta( '_blinkpay_manual_refunds' )
		);

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

	public function test_a_still_processing_account_number_refund_defers_instead_of_inviting_a_retry() {
		$order  = $this->register_paid_order( 408, 'source_bank_payment_sent' );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-408' ) ),
			array(
				array(
					'refund_id' => 'rf-408',
					'status'    => 'processing',
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 408, 49.95, '' );

		// The refund exists, so an error here would invite creating a second
		// one; the merchant is deferred to the panel and the portal instead.
		$this->assertTrue( $result );
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( 'rf-408', $order->notes[0] );
		$this->assertStringContainsString( 'manual refunds panel', $order->notes[0] );
		$this->assertCount( 1, $order->customer_notes );
	}

	public function test_the_order_screen_panel_fetches_the_account_number_live() {
		$order  = $this->register_paid_order( 412, 'source_bank_payment_sent' );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-412' ) ),
			array(
				// The refund-time poll, then the panel's render-time fetch.
				array(
					'refund_id'      => 'rf-412',
					'account_number' => '01-2345-6789012-00',
				),
				array(
					'refund_id'      => 'rf-412',
					'status'         => 'completed',
					'account_number' => '01-2345-6789012-00',
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$this->assertTrue( $gateway->process_refund( 412, 49.95, '' ) );

		$rows = $gateway->get_manual_refund_instructions( $order );

		$this->assertSame(
			array(
				array(
					'refund_id'      => 'rf-412',
					'amount'         => '49.95',
					'recorded'       => gmdate( 'Y-m-d' ),
					'paid_on'        => '',
					'account_number' => '01-2345-6789012-00',
					'status'         => 'completed',
				),
			),
			$rows,
			'The panel must show the number the API reports at render time.'
		);

		// The canned responses are exhausted, so the next render's fetch
		// errors: the row survives with no number and defers to the portal.
		$rows = $gateway->get_manual_refund_instructions( $order );

		$this->assertSame( '', $rows[0]['account_number'], 'An unreachable API must degrade to the portal, not break the panel.' );

		// The number was displayed, never stored.
		$this->assertStringNotContainsString( '01-2345-6789012-00', wp_json_encode( $order->get_meta( '_blinkpay_manual_refunds' ) ) );
		$this->assertStringNotContainsString( '01-2345-6789012-00', implode( ' ', $order->notes ) );
	}

	public function test_an_unreachable_api_short_circuits_panel_fetches() {
		$order  = $this->register_paid_order( 414, 'source_bank_payment_sent' );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-414' ) ),
			array(
				array(
					'refund_id'      => 'rf-414',
					'account_number' => '01-2345-6789012-00',
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$this->assertTrue( $gateway->process_refund( 414, 49.95, '' ) );

		// The canned responses are exhausted: the panel's fetch fails, the
		// row degrades, and the unreachable flag is set.
		$rows = $gateway->get_manual_refund_instructions( $order );

		$this->assertSame( '', $rows[0]['account_number'] );
		$this->assertSame( WC_BlinkPay_Gateway::PANEL_REQUEST_TIMEOUT, $client->request_timeout, 'Panel reads must fail fast, not hold the order screen for the checkout timeout.' );
		$this->assertNotFalse( get_transient( WC_BlinkPay_Gateway::PANEL_UNREACHABLE_FLAG ) );

		// While the flag stands, renders skip the API entirely.
		$fetches = count( $client->get_refund_calls );
		$rows    = $gateway->get_manual_refund_instructions( $order );

		$this->assertSame( '', $rows[0]['account_number'] );
		$this->assertCount( $fetches, $client->get_refund_calls, 'An outage must not be re-probed on every order-screen load.' );
	}

	public function test_marking_a_manual_refund_transferred_discharges_the_obligation() {
		$order  = $this->register_paid_order( 413, 'source_bank_payment_sent' );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-413' ) ),
			array(
				array(
					'refund_id'      => 'rf-413',
					'account_number' => '01-2345-6789012-00',
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$this->assertTrue( $gateway->process_refund( 413, 49.95, '' ) );
		$this->assertTrue( $gateway->mark_manual_refund_paid( $order, 'rf-413' ) );

		// The discharge is audited on the order.
		$this->assertStringContainsString( 'rf-413', end( $order->notes ) );
		$this->assertStringContainsString( 'test-admin', end( $order->notes ) );

		// A discharged obligation renders as history and triggers no fetch.
		$fetches_before = count( $client->get_refund_calls );
		$rows           = $gateway->get_manual_refund_instructions( $order );

		$this->assertSame( gmdate( 'Y-m-d' ), $rows[0]['paid_on'] );
		$this->assertSame( '', $rows[0]['account_number'] );
		$this->assertCount( $fetches_before, $client->get_refund_calls, 'History needs no live account number: a discharged row must not call the API.' );

		// Marking is idempotent: a second mark changes nothing.
		$notes_before = count( $order->notes );
		$this->assertFalse( $gateway->mark_manual_refund_paid( $order, 'rf-413' ) );
		$this->assertCount( $notes_before, $order->notes );
	}

	public function test_an_account_number_refund_reported_failed_rejects_the_woocommerce_refund() {
		$order  = $this->register_paid_order( 409, 'source_bank_payment_sent' );
		$client = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'rf-409' ) ),
			array(
				array(
					'refund_id' => 'rf-409',
					'status'    => 'failed',
				),
			)
		);

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 409, 49.95, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $order->notes );
		$this->assertSame( array(), $order->customer_notes );
	}
}
