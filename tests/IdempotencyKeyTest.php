<?php
/**
 * BDL-1616: the idempotency key must be scoped to the payment attempt, not
 * the order. The API binds a key permanently to the payment it creates, so a
 * retried order needs a fresh key, while a lost-response retry of the same
 * attempt must still reuse its key.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use PHPUnit\Framework\TestCase;

class IdempotencyKeyTest extends TestCase {

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

	public function test_a_retry_after_a_failed_attempt_mints_a_fresh_idempotency_key() {
		$order = $this->register_order( 201 );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(
				array(
					'quick_payment_id' => 'qp-first',
					'redirect_uri'     => 'https://gateway.test/pay/qp-first',
				),
				array(
					'quick_payment_id' => 'qp-second',
					'redirect_uri'     => 'https://gateway.test/pay/qp-second',
				),
			),
			array(
				// The retry first confirms the previous attempt through the
				// API; only its rejection makes a second creation safe.
				array(
					'consent' => array(
						'status'   => 'Rejected',
						'payments' => array(),
					),
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->process_payment( 201 );

		// The customer cancels at the gateway — the API reports the consent
		// Rejected — then they retry checkout on the same order.
		$result = $gateway->process_payment( 201 );

		$this->assertCount( 2, $client->create_calls );
		$this->assertNotEmpty( $client->create_calls[0]['idempotency_key'] );
		$this->assertNotEmpty( $client->create_calls[1]['idempotency_key'] );
		$this->assertNotSame(
			$client->create_calls[0]['idempotency_key'],
			$client->create_calls[1]['idempotency_key'],
			'A retried order must not reuse a key already bound to the first quick payment.'
		);

		// The retry produced a genuinely new payment journey.
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://gateway.test/pay/qp-second', $result['redirect'] );
		$this->assertSame( 'qp-second', $order->get_meta( '_blinkpay_quick_payment_id' ) );
	}

	public function test_a_lost_response_retry_reuses_the_same_idempotency_key() {
		$this->register_order( 202 );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(
				new WP_Error( 'http_request_failed', 'Operation timed out.' ),
				array(
					'quick_payment_id' => 'qp-replayed',
					'redirect_uri'     => 'https://gateway.test/pay/qp-replayed',
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		// The create response is lost in transit, so no quick payment was
		// stored; the retry must replay the same key so BlinkPay cannot
		// double-create.
		$first  = $gateway->process_payment( 202 );
		$second = $gateway->process_payment( 202 );

		$this->assertSame( 'failure', $first['result'] );
		$this->assertSame( 'success', $second['result'] );
		$this->assertCount( 2, $client->create_calls );
		$this->assertSame(
			$client->create_calls[0]['idempotency_key'],
			$client->create_calls[1]['idempotency_key'],
			'An unresolved attempt must reuse its key so the replay protection is preserved.'
		);
	}

	public function test_an_idempotency_conflict_with_a_string_status_is_still_recognised() {
		$order = $this->register_order( 204 );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(
				new WP_Error(
					'blinkpay_api_error',
					'Idempotency key has already been used. (BP710)',
					array(
						'status' => '409',
						'body'   => array( 'code' => 'BP710' ),
					)
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$gateway->process_payment( 204 );

		// An HTTP layer reporting the status as a string must not defeat the
		// conflict detection — an undiscarded spent key dead-ends the order.
		$this->assertSame( '', $order->get_meta( '_blinkpay_idempotency_quick_payment' ), 'A 409 arriving as a string is still a spent key and must be discarded.' );
	}

	public function test_an_idempotency_conflict_discards_the_key_and_leaves_an_actionable_note() {
		$order = $this->register_order( 203 );

		$client  = new WC_BlinkPay_Fake_API_Client(
			array(
				new WP_Error(
					'blinkpay_api_error',
					'Idempotency key has already been used. (BP710)',
					array(
						'status' => 409,
						'body'   => array( 'code' => 'BP710' ),
					)
				),
				array(
					'quick_payment_id' => 'qp-recovered',
					'redirect_uri'     => 'https://gateway.test/pay/qp-recovered',
				),
			)
		);
		$gateway = new WC_BlinkPay_Test_Gateway( $client );

		$first = $gateway->process_payment( 203 );

		$this->assertSame( 'failure', $first['result'] );
		$this->assertSame( '', $order->get_meta( '_blinkpay_idempotency_quick_payment' ), 'A key rejected with 409 is spent and must be discarded.' );

		$conflict_notes = array_filter(
			$order->notes,
			function ( $note ) {
				return false !== strpos( $note, 'idempotency key' );
			}
		);
		$this->assertNotEmpty( $conflict_notes, 'The 409 must leave an actionable order note, not only the generic checkout notice.' );

		// The next attempt converges: a fresh key creates a new payment.
		$second = $gateway->process_payment( 203 );

		$this->assertSame( 'success', $second['result'] );
		$this->assertNotSame(
			$client->create_calls[0]['idempotency_key'],
			$client->create_calls[1]['idempotency_key']
		);
	}
}
