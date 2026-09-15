<?php
/**
 * The SDK reaches the network through the WordPress HTTP API, never cURL
 * directly, so the transport must translate faithfully in both directions:
 * header lines to WordPress's associative array, and back to the
 * lower-cased response headers the SDK reads Retry-After from. It must also
 * preserve the SDK's own guarantees for the bearer token — certificate
 * verification on, redirects never followed.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use BlinkPay\BlinkDebit\Exception\TransportException;
use PHPUnit\Framework\TestCase;

class HttpTransportTest extends TestCase {

	protected function setUp(): void {
		wc_blinkpay_tests_reset();
	}

	/**
	 * @param array $response The canned wp_remote_request() response.
	 * @return array The transport's return value.
	 */
	private function send( array $response ) {
		$GLOBALS['wc_blinkpay_http_responses'][] = $response;

		$transport = new WC_BlinkPay_HTTP_Transport();

		return $transport->send(
			'POST',
			'https://sandbox.debit.blinkpay.co.nz/payments/v1/quick-payments',
			array( 'Authorization: Bearer tok-1', 'Content-Type: application/json' ),
			'{"amount":{"total":"49.95"}}',
			30
		);
	}

	public function test_header_lines_become_the_wordpress_header_array() {
		$this->send(
			array(
				'response' => array( 'code' => 201 ),
				'body'     => '{}',
			)
		);

		$args = $GLOBALS['wc_blinkpay_http_requests'][0]['args'];

		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( 30, $args['timeout'] );
		$this->assertSame( '{"amount":{"total":"49.95"}}', $args['body'] );
		$this->assertSame(
			array(
				'Authorization' => 'Bearer tok-1',
				'Content-Type'  => 'application/json',
			),
			$args['headers']
		);
	}

	public function test_a_header_line_without_a_value_is_dropped_rather_than_sent_malformed() {
		$GLOBALS['wc_blinkpay_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
		);

		$transport = new WC_BlinkPay_HTTP_Transport();
		$transport->send( 'GET', 'https://sandbox.debit.blinkpay.co.nz/payments/v1/meta', array( 'Accept: application/json', 'Broken' ), null, 30 );

		$args = $GLOBALS['wc_blinkpay_http_requests'][0]['args'];

		$this->assertSame( array( 'Accept' => 'application/json' ), $args['headers'] );
		$this->assertArrayNotHasKey( 'body', $args, 'A bodyless request must not send an empty body.' );
	}

	public function test_the_bearer_token_is_protected_by_verification_and_no_redirects() {
		$this->send(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{}',
			)
		);

		$args = $GLOBALS['wc_blinkpay_http_requests'][0]['args'];

		$this->assertTrue( $args['sslverify'], 'An unverified certificate would expose the bearer token to interception.' );
		$this->assertSame( 0, $args['redirection'], 'A followed redirect would replay the Authorization header to another host.' );
	}

	public function test_response_headers_come_back_lower_cased_for_retry_after() {
		$result = $this->send(
			array(
				'response' => array( 'code' => 429 ),
				'body'     => '{"code":"BP999"}',
				'headers'  => array( 'Retry-After' => '5' ),
			)
		);

		$this->assertSame( 429, $result['status'] );
		$this->assertSame( '{"code":"BP999"}', $result['body'] );
		$this->assertSame( '5', $result['headers']['retry-after'], 'The SDK reads Retry-After by its lower-cased name.' );
	}

	public function test_a_repeated_response_header_collapses_to_a_single_value() {
		$result = $this->send(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{}',
				'headers'  => array( 'Retry-After' => array( '1', '9' ) ),
			)
		);

		$this->assertSame( '9', $result['headers']['retry-after'] );
	}

	public function test_a_transport_failure_becomes_a_transport_exception() {
		$GLOBALS['wc_blinkpay_http_responses'][] = new WP_Error( 'http_request_failed', 'Operation timed out.' );

		$transport = new WC_BlinkPay_HTTP_Transport();

		// The SDK keys its "may this be replayed?" decision on this exception
		// type; a WP_Error returned instead would be read as an HTTP response.
		$this->expectException( TransportException::class );
		$this->expectExceptionMessage( 'Operation timed out.' );

		$transport->send( 'GET', 'https://sandbox.debit.blinkpay.co.nz/payments/v1/meta', array(), null, 30 );
	}
}
