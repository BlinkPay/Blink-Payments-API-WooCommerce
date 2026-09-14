<?php
/**
 * WordPress HTTP transport for the Blink Debit SDK.
 *
 * @package blinkpay-nz-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

use BlinkPay\BlinkDebit\Exception\TransportException;
use BlinkPay\BlinkDebit\HttpTransportInterface;

/**
 * Sends the SDK's requests through the WordPress HTTP API instead of the
 * SDK's bundled cURL transport. WordPress.org does not accept plugins that
 * call cURL directly, and the HTTP API is what honours a site's proxy
 * constants, its CA bundle and the http_request_* filters administrators and
 * security plugins rely on.
 *
 * The SDK's own transport guarantees are reproduced here, because they are
 * what keeps the bearer token safe: certificate verification stays on, and
 * redirects are never followed, so a 3xx to another host can never receive
 * the Authorization header.
 */
class WC_BlinkPay_HTTP_Transport implements HttpTransportInterface {

	/**
	 * Sends one request and returns the raw response.
	 *
	 * @param string      $method          HTTP method.
	 * @param string      $url             Absolute URL.
	 * @param string[]    $headers         Header lines, e.g. "Accept: application/json".
	 * @param string|null $body            Raw request body, if any.
	 * @param int         $timeout_seconds Total request timeout.
	 * @return array{status: int, body: string, headers: array<string, string>}
	 * @throws TransportException When no HTTP response was received at all.
	 */
	public function send( string $method, string $url, array $headers, ?string $body, int $timeout_seconds ): array {
		$args = array(
			'method'      => $method,
			'timeout'     => $timeout_seconds,
			'headers'     => $this->parse_header_lines( $headers ),
			// Verify the certificate chain, and never follow a redirect: the
			// Authorization header must not be replayed to another host.
			'sslverify'   => true,
			'redirection' => 0,
		);

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		// A WP_Error here means no HTTP response was received — DNS, TCP, TLS
		// or timeout. The SDK's retry logic keys on this exception type to
		// decide whether a request may be safely replayed.
		if ( is_wp_error( $response ) ) {
			// Escaped because the message travels out as a WP_Error and is
			// rendered into order notes and the admin's refund errors.
			throw new TransportException(
				sprintf( 'The Blink Debit API could not be reached: %s', esc_html( $response->get_error_message() ) )
			);
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => $this->collect_response_headers( $response ),
		);
	}

	/**
	 * Turns the SDK's "Name: value" header lines into the associative array
	 * the WordPress HTTP API expects. A line without a colon carries no value
	 * and is dropped rather than sent as a malformed header.
	 *
	 * @param string[] $headers The header lines.
	 * @return array<string, string>
	 */
	private function parse_header_lines( array $headers ) {
		$parsed = array();

		foreach ( $headers as $line ) {
			$pair = explode( ':', (string) $line, 2 );
			if ( 2 === count( $pair ) ) {
				$parsed[ trim( $pair[0] ) ] = trim( $pair[1] );
			}
		}

		return $parsed;
	}

	/**
	 * The response headers keyed by lower-case name, the shape the SDK reads
	 * Retry-After from. WordPress returns a case-insensitive dictionary whose
	 * repeated headers are arrays; only the last value of such a header is
	 * kept, since the SDK reads single-valued headers only.
	 *
	 * @param array $response The wp_remote_request() response.
	 * @return array<string, string>
	 */
	private function collect_response_headers( $response ) {
		$headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		if ( ! is_array( $headers ) ) {
			return array();
		}

		$collected = array();
		foreach ( $headers as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = end( $value );
			}
			$collected[ strtolower( (string) $name ) ] = (string) $value;
		}

		return $collected;
	}
}
