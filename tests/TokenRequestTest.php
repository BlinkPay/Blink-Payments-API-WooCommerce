<?php
/**
 * BDL-1625: the documented token contract is OAuth 2.0 form encoding. The
 * server happens to accept a JSON body too, but that is undocumented
 * behaviour, so the token request must send the documented encoding.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class TokenRequestTest extends TestCase {

	protected function setUp(): void {
		wc_blinkpay_tests_reset();
	}

	public function test_the_token_request_uses_the_documented_form_encoding() {
		$GLOBALS['wc_blinkpay_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode(
				array(
					'access_token' => 'tok-1',
					'expires_in'   => 3600,
				)
			),
		);

		$client = new WC_BlinkPay_API_Client( 'client-id', 'client-secret', true, false );

		$this->assertSame( 'tok-1', $client->get_access_token() );

		$request = $GLOBALS['wc_blinkpay_http_requests'][0];
		$this->assertSame( 'https://sandbox.debit.blinkpay.co.nz/oauth2/token', $request['url'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $request['args']['headers']['Content-Type'] );

		parse_str( $request['args']['body'], $fields );
		$this->assertSame(
			array(
				'grant_type'    => 'client_credentials',
				'client_id'     => 'client-id',
				'client_secret' => 'client-secret',
			),
			$fields
		);
	}
}
