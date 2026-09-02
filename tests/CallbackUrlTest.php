<?php
/**
 * BDL-1619: redirect URIs must be whitelisted in the BlinkPay client portal
 * before consents can be created, so the plugin must surface the site's
 * callback URL to the merchant and name the likely cause when consent
 * creation fails on an unregistered redirect URI.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class CallbackUrlTest extends TestCase {

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

	public function test_the_callback_url_is_the_sites_wc_api_endpoint() {
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$this->assertSame( 'https://example.test/wc-api/blinkpay_return/', $gateway->get_callback_url() );
	}

	public function test_the_per_order_return_url_extends_the_registered_callback_url() {
		$order   = $this->register_order( 301 );
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		// Whitelist matching is prefix-based, so one registered callback URL
		// must cover every order's return URL.
		$this->assertStringStartsWith( $gateway->get_callback_url(), $gateway->get_gateway_return_url( $order ) );
	}

	public function test_the_settings_screen_shows_the_callback_url_in_a_copyable_field() {
		$gateway = new WC_BlinkPay_Test_Gateway( new WC_BlinkPay_Fake_API_Client() );

		$this->assertArrayHasKey( 'callback_url', $gateway->form_fields );

		$html = $gateway->generate_callback_url_html( 'callback_url', $gateway->form_fields['callback_url'] );

		$this->assertStringContainsString( 'https://example.test/wc-api/blinkpay_return/', $html );
		$this->assertStringContainsString( 'readonly', $html );
		// Both environments are called out in the field description.
		$this->assertStringContainsString( 'sandbox and production', $html );
	}

	public function test_an_unregistered_redirect_uri_rejection_names_the_likely_cause() {
		$order = $this->register_order( 302 );

		$gateway = new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Fake_API_Client(
				array(
					new WP_Error(
						'blinkpay_api_error',
						'The redirect_uri is not registered for this client. (BP390)',
						array(
							'status' => 422,
							'body'   => null,
						)
					),
				)
			)
		);

		$result = $gateway->process_payment( 302 );

		$this->assertSame( 'failure', $result['result'] );

		$whitelist_notes = array_filter(
			$order->notes,
			function ( $note ) {
				return false !== strpos( $note, 'not whitelisted' );
			}
		);
		$this->assertCount( 1, $whitelist_notes );

		$note = reset( $whitelist_notes );
		$this->assertStringContainsString( 'https://example.test/wc-api/blinkpay_return/', $note );
		$this->assertStringContainsString( 'sandbox environment', $note );

		// The customer-facing notice stays generic; the diagnosis is for the merchant.
		$this->assertCount( 1, $GLOBALS['wc_blinkpay_notices'] );
		$this->assertStringContainsString( 'We could not start your BlinkPay payment', $GLOBALS['wc_blinkpay_notices'][0]['message'] );
	}

	public function test_other_creation_failures_do_not_claim_a_whitelisting_problem() {
		$order = $this->register_order( 303 );

		$gateway = new WC_BlinkPay_Test_Gateway(
			new WC_BlinkPay_Fake_API_Client(
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
			)
		);

		$result = $gateway->process_payment( 303 );

		$this->assertSame( 'failure', $result['result'] );

		foreach ( $order->notes as $note ) {
			$this->assertStringNotContainsString( 'whitelist', $note );
		}
	}
}
