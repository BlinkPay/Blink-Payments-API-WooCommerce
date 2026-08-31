<?php
/**
 * BDL-1621: refunds require the create:refund and view:refund scopes, so the
 * granted scope from the token response must be retained and used to decide
 * whether the gateway advertises refund support, and a 403 on the refund
 * path must name the missing permission instead of surfacing a raw API error.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class ScopeSupportTest extends TestCase {

	protected function setUp(): void {
		wc_blinkpay_tests_reset();
	}

	/**
	 * @param string|null $scope The scope string in the token response, null to omit it.
	 * @return WC_BlinkPay_API_Client
	 */
	private function client_with_token_response( $scope ) {
		$body = array(
			'access_token' => 'tok-1',
			'expires_in'   => 3600,
		);
		if ( null !== $scope ) {
			$body['scope'] = $scope;
		}

		$GLOBALS['wc_blinkpay_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( $body ),
		);

		return new WC_BlinkPay_API_Client( 'client-id', 'client-secret', true, false );
	}

	public function test_the_granted_scope_is_retained_from_the_token_response() {
		$client = $this->client_with_token_response( 'create:quick_payment view:quick_payment create:refund view:refund' );

		$this->assertSame( 'tok-1', $client->get_access_token() );
		$this->assertSame(
			array( 'create:quick_payment', 'view:quick_payment', 'create:refund', 'view:refund' ),
			$client->get_granted_scopes()
		);
	}

	public function test_the_granted_scope_outlives_the_token() {
		$client = $this->client_with_token_response( 'create:quick_payment view:quick_payment' );
		$client->get_access_token();

		// The token transient expires; the grant is still known.
		$GLOBALS['wc_blinkpay_transients'] = array();

		$this->assertSame( array( 'create:quick_payment', 'view:quick_payment' ), $client->get_granted_scopes() );
	}

	public function test_the_scopes_are_unknown_before_any_token_is_fetched() {
		$client = new WC_BlinkPay_API_Client( 'client-id', 'client-secret', true, false );

		$this->assertNull( $client->get_granted_scopes() );
	}

	public function test_a_token_response_without_scope_clears_the_retained_grant() {
		$client = $this->client_with_token_response( 'create:refund view:refund' );
		$client->get_access_token();

		$GLOBALS['wc_blinkpay_transients'] = array();
		$client = $this->client_with_token_response( null );
		$client->get_access_token();

		$this->assertNull( $client->get_granted_scopes() );
	}

	public function test_refunds_are_advertised_while_the_grant_is_unknown() {
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$this->assertTrue( $gateway->supports( 'refunds' ) );
		$this->assertTrue( $gateway->supports( 'products' ) );
	}

	public function test_refunds_are_advertised_when_the_grant_includes_both_refund_scopes() {
		$client                 = new WC_BlinkPay_Fake_API_Client();
		$client->granted_scopes = array( 'create:quick_payment', 'view:quick_payment', 'create:refund', 'view:refund' );

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$this->assertTrue( $gateway->supports( 'refunds' ) );
	}

	public function test_refunds_are_not_advertised_when_the_grant_lacks_the_refund_scopes() {
		$client                 = new WC_BlinkPay_Fake_API_Client();
		$client->granted_scopes = array( 'create:quick_payment', 'view:quick_payment' );

		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$this->assertFalse( $gateway->supports( 'refunds' ) );
		$this->assertTrue( $gateway->supports( 'products' ) );
	}

	public function test_a_403_on_the_refund_path_names_the_missing_permissions() {
		$order = new WC_BlinkPay_Test_Order( 501 );
		$order->update_meta_data( '_blinkpay_payment_id', 'pay-501' );
		$order->update_meta_data( '_blinkpay_accepted_reason', 'card_network_accepted' );
		$order->payment_complete( 'pay-501' );
		$GLOBALS['wc_blinkpay_test_orders'][501] = $order;

		$gateway = new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Fake_API_Client(
				array(),
				array(),
				array(
					new WP_Error(
						'blinkpay_api_error',
						'Forbidden',
						array(
							'status' => 403,
							'body'   => null,
						)
					),
				)
			)
		);

		$result = $gateway->process_refund( 501, 49.95, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'create:refund', $result->get_error_message() );
		$this->assertStringContainsString( 'view:refund', $result->get_error_message() );
	}
}
