<?php
/**
 * The gateway reads API failures as WP_Errors carrying the HTTP status and
 * decoded body, and decides from them whether an idempotency key is spent,
 * whether the refund scopes are missing and whether a redirect URI is
 * unregistered. That contract has to survive the SDK sitting underneath, so
 * these tests drive the adapter over the whole stack — SDK, transport and
 * canned HTTP — rather than mocking it out.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase {

	const IDEMPOTENCY_KEY = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';
	const QUICK_PAYMENT_ID = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

	protected function setUp(): void {
		wc_blinkpay_tests_reset();
	}

	/**
	 * Queues the token response every authenticated call needs first.
	 */
	private function queue_token() {
		$GLOBALS['wc_blinkpay_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode(
				array(
					'access_token' => 'tok-1',
					'expires_in'   => 3600,
				)
			),
		);
	}

	/**
	 * @param int   $code The HTTP status to answer with.
	 * @param array $body The JSON body to answer with.
	 */
	private function queue_response( $code, array $body ) {
		$GLOBALS['wc_blinkpay_http_responses'][] = array(
			'response' => array( 'code' => $code ),
			'body'     => json_encode( $body ),
		);
	}

	/**
	 * @return WC_BlinkPay_API_Client
	 */
	private function client() {
		return new WC_BlinkPay_Test_API_Client( 'client-id', 'client-secret', true, false );
	}

	public function test_a_quick_payment_is_created_against_the_sandbox() {
		$this->queue_token();
		$this->queue_response(
			201,
			array(
				'quick_payment_id' => self::QUICK_PAYMENT_ID,
				'redirect_uri'     => 'https://sandbox.blinkpay.co.nz/gateway/pay',
			)
		);

		$response = $this->client()->create_quick_payment(
			array( 'amount' => array( 'currency' => 'NZD', 'total' => '49.95' ) ),
			self::IDEMPOTENCY_KEY
		);

		$this->assertSame( self::QUICK_PAYMENT_ID, $response['quick_payment_id'] );

		$request = $GLOBALS['wc_blinkpay_http_requests'][1];
		$this->assertSame( 'https://sandbox.debit.blinkpay.co.nz/payments/v1/quick-payments', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 'Bearer tok-1', $request['args']['headers']['Authorization'] );
		$this->assertSame( self::IDEMPOTENCY_KEY, $request['args']['headers']['idempotency-key'] );
		$this->assertArrayHasKey( 'x-correlation-id', $request['args']['headers'], 'Every request must be traceable in BlinkPay support.' );
	}

	public function test_an_api_error_becomes_a_wp_error_carrying_the_status_and_body() {
		$this->queue_token();
		$this->queue_response(
			409,
			array(
				'code'    => 'BP710',
				'message' => 'Idempotency key has already been used.',
			)
		);

		$response = $this->client()->create_quick_payment(
			array( 'amount' => array( 'currency' => 'NZD', 'total' => '49.95' ) ),
			self::IDEMPOTENCY_KEY
		);

		$this->assertInstanceOf( WP_Error::class, $response );

		// The gateway keys its spent-key handling off exactly this shape.
		$data = $response->get_error_data();
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( 'BP710', $data['body']['code'] );
		$this->assertStringContainsString( 'Idempotency key has already been used.', $response->get_error_message() );
	}

	public function test_a_rejected_redirect_uri_still_names_the_redirect_in_the_message() {
		$this->queue_token();
		$this->queue_response(
			400,
			array( 'message' => 'The redirect_uri is not registered for this client.' )
		);

		$response = $this->client()->create_quick_payment(
			array( 'amount' => array( 'currency' => 'NZD', 'total' => '49.95' ) ),
			self::IDEMPOTENCY_KEY
		);

		// The gateway detects this case heuristically, by looking for
		// "redirect" in a 4xx message, to point the merchant at whitelisting.
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertNotFalse( stripos( $response->get_error_message(), 'redirect' ) );
	}

	public function test_a_transport_failure_is_reported_without_a_status() {
		$this->queue_token();
		$GLOBALS['wc_blinkpay_http_responses'][] = new WP_Error( 'http_request_failed', 'Operation timed out.' );

		$response = $this->client()->get_quick_payment( self::QUICK_PAYMENT_ID );

		// Status 0 says the request never reached the API, so the gateway
		// must read it as "outcome not yet known", never as a failed payment.
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 0, $response->get_error_data()['status'] );
	}

	public function test_a_malformed_id_is_rejected_before_any_request_is_sent() {
		$response = $this->client()->get_quick_payment( 'not-a-uuid' );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 0, $response->get_error_data()['status'] );
		$this->assertSame( array(), $GLOBALS['wc_blinkpay_http_requests'], 'Local validation must not even fetch a token.' );
	}

	public function test_missing_credentials_are_reported_rather_than_sent() {
		$client = new WC_BlinkPay_Test_API_Client( '', '', true, false );

		$this->assertFalse( $client->is_configured() );
		$this->assertInstanceOf( WP_Error::class, $client->get_access_token() );
		$this->assertSame( array(), $GLOBALS['wc_blinkpay_http_requests'] );
	}

	public function test_a_stale_token_is_refreshed_once_and_the_call_retried() {
		$this->queue_token();
		$this->queue_response( 401, array( 'message' => 'Unauthorised.' ) );
		// The refresh, then the replayed call.
		$GLOBALS['wc_blinkpay_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'access_token' => 'tok-2', 'expires_in' => 3600 ) ),
		);
		$this->queue_response( 200, array( 'quick_payment_id' => self::QUICK_PAYMENT_ID ) );

		$response = $this->client()->get_quick_payment( self::QUICK_PAYMENT_ID );

		$this->assertSame( self::QUICK_PAYMENT_ID, $response['quick_payment_id'] );
		$this->assertSame( 'Bearer tok-2', $GLOBALS['wc_blinkpay_http_requests'][3]['args']['headers']['Authorization'], 'A rotated credential must not strand the cached token.' );
	}

	public function test_a_refund_sends_its_idempotency_key() {
		$this->queue_token();
		$this->queue_response( 201, array( 'refund_id' => self::QUICK_PAYMENT_ID ) );

		$response = $this->client()->create_refund(
			array( 'type' => 'account_number', 'payment_id' => self::QUICK_PAYMENT_ID ),
			self::IDEMPOTENCY_KEY
		);

		$this->assertSame( self::QUICK_PAYMENT_ID, $response['refund_id'] );
		$this->assertSame( self::IDEMPOTENCY_KEY, $GLOBALS['wc_blinkpay_http_requests'][1]['args']['headers']['idempotency-key'] );
	}
}
