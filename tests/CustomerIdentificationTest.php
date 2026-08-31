<?php
/**
 * BDL-1624: order and customer identification. The PCR reference truncates at
 * 12 characters and can collide once order numbers are prefixed, so the PCR
 * code carries the numeric order ID as an exact reconciliation key. The
 * hashed customer identifier is derived from the WooCommerce customer ID —
 * falling back to the billing email for guests — and is omitted entirely when
 * neither exists, rather than sent as the hash of the empty string, which
 * would make every such order look like one customer.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class CustomerIdentificationTest extends TestCase {

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
	 * A gateway whose quick payment creation succeeds, so the payload sent to
	 * the API can be captured from the fake client.
	 *
	 * @param int $order_id The order ID the canned response references.
	 * @return array The gateway and its fake API client.
	 */
	private function gateway_with_client( $order_id ) {
		$client  = new WC_BlinkPay_Fake_API_Client(
			array(
				array(
					'quick_payment_id' => 'qp-' . $order_id,
					'redirect_uri'     => 'https://gateway.test/pay/qp-' . $order_id,
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		return array( $gateway, $client );
	}

	public function test_a_registered_customer_is_identified_by_their_customer_id() {
		$order = $this->register_order( 501 );
		$order->set_customer_id( 7 );

		list( $gateway, $client ) = $this->gateway_with_client( 501 );

		$gateway->process_payment( 501 );

		$payload = $client->create_calls[0]['payload'];
		$this->assertSame( hash( 'sha256', 'customer-7' ), $payload['hashed_customer_identifier'], 'A registered customer must be identified by their stable customer ID, not their changeable email.' );
	}

	public function test_a_guest_with_an_email_keeps_the_lowercased_email_hash() {
		$order = $this->register_order( 502 );
		$order->set_billing_email( 'Guest@Example.Test' );

		list( $gateway, $client ) = $this->gateway_with_client( 502 );

		$gateway->process_payment( 502 );

		$payload = $client->create_calls[0]['payload'];
		$this->assertSame( hash( 'sha256', 'guest@example.test' ), $payload['hashed_customer_identifier'], 'A guest keeps the email-derived hash, so existing guests stay continuous with their earlier orders.' );
	}

	public function test_the_identifier_is_omitted_when_no_per_customer_value_exists() {
		$order = $this->register_order( 503 );
		$order->set_billing_email( '' );

		list( $gateway, $client ) = $this->gateway_with_client( 503 );

		$gateway->process_payment( 503 );

		$payload = $client->create_calls[0]['payload'];
		$this->assertArrayNotHasKey( 'hashed_customer_identifier', $payload, 'With no customer ID and no email the field must be omitted — the hash of the empty string is a constant shared by every such order.' );
	}

	public function test_the_pcr_code_carries_the_order_id_alongside_the_reference() {
		$this->register_order( 504 );

		list( $gateway, $client ) = $this->gateway_with_client( 504 );

		$gateway->process_payment( 504 );

		$pcr = $client->create_calls[0]['payload']['pcr'];
		$this->assertSame( '504', $pcr['code'] );
		$this->assertSame( '504', $pcr['reference'] );
	}

	public function test_a_prefixed_order_number_truncates_the_reference_but_not_the_code() {
		$order = $this->register_order( 505 );
		$order->set_order_number( 'WEB-2026-0000505' );

		list( $gateway, $client ) = $this->gateway_with_client( 505 );

		$gateway->process_payment( 505 );

		$pcr = $client->create_calls[0]['payload']['pcr'];
		$this->assertSame( 'WEB-2026-000', $pcr['reference'], 'The reference is capped at the API\'s 12 characters.' );
		$this->assertSame( '505', $pcr['code'], 'The order ID survives intact as the exact reconciliation key.' );
	}

	public function test_a_blank_code_is_omitted_from_the_pcr() {
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$pcr = $gateway->build_pcr( 'Order', '123' );

		$this->assertArrayNotHasKey( 'code', $pcr );
		$this->assertSame( '123', $pcr['reference'] );
	}

	public function test_a_refund_pcr_carries_the_order_id_as_the_code() {
		$order = $this->register_order( 506 );
		$order->update_meta_data( '_blinkpay_payment_id', 'pay-506' );
		$order->update_meta_data( '_blinkpay_accepted_reason', 'card_network_accepted' );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(),
			array(),
			array( array( 'refund_id' => 'refund-506' ) ),
			array( array( 'status' => 'completed' ) )
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$result = $gateway->process_refund( 506, 49.95, 'Requested by customer.' );

		$this->assertTrue( $result );
		$this->assertSame( '506', $client->refund_calls[0]['pcr']['code'] );
	}
}
