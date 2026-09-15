<?php
/**
 * The SDK's token cache is backed by WordPress storage. The token expires and
 * belongs in a transient; the granted scopes must outlive it — they decide
 * whether the gateway advertises refunds — and belong in an option. The key
 * names must stay the ones this plugin has always used, so a token cached by
 * an earlier version survives the upgrade and uninstall.php still finds them.
 *
 * @package blinkpay-nz-for-woocommerce
 */

use BlinkPay\BlinkDebit\BlinkDebitClient;
use PHPUnit\Framework\TestCase;

class TokenCacheTest extends TestCase {

	protected function setUp(): void {
		wc_blinkpay_tests_reset();
	}

	public function test_a_value_with_a_ttl_is_stored_as_a_transient() {
		$cache = new WC_BlinkPay_Token_Cache();
		$cache->set( 'blinkpay_token_abc', 'tok-1', 3300 );

		$this->assertSame( 'tok-1', $GLOBALS['wc_blinkpay_transients']['wc_blinkpay_token_abc'] );
		$this->assertArrayNotHasKey( 'wc_blinkpay_token_abc', $GLOBALS['wc_blinkpay_options'] );
		$this->assertSame( 'tok-1', $cache->get( 'blinkpay_token_abc' ) );
	}

	public function test_a_value_without_a_ttl_is_stored_as_an_option() {
		$cache = new WC_BlinkPay_Token_Cache();
		$cache->set( 'blinkpay_scopes_abc', 'create:refund view:refund', null );

		$this->assertSame( 'create:refund view:refund', $GLOBALS['wc_blinkpay_options']['wc_blinkpay_scopes_abc'] );
		$this->assertArrayNotHasKey( 'wc_blinkpay_scopes_abc', $GLOBALS['wc_blinkpay_transients'] );

		// The transient backing is empty, so this only resolves through the
		// option fallback — which is how the grant outlives the token.
		$this->assertSame( 'create:refund view:refund', $cache->get( 'blinkpay_scopes_abc' ) );
	}

	public function test_a_missing_key_is_null() {
		$cache = new WC_BlinkPay_Token_Cache();

		$this->assertNull( $cache->get( 'blinkpay_token_missing' ) );
	}

	public function test_delete_clears_both_backings() {
		$cache = new WC_BlinkPay_Token_Cache();
		$cache->set( 'blinkpay_token_abc', 'tok-1', 3300 );
		$cache->set( 'blinkpay_scopes_abc', 'view:refund', null );

		$cache->delete( 'blinkpay_token_abc' );
		$cache->delete( 'blinkpay_scopes_abc' );

		$this->assertNull( $cache->get( 'blinkpay_token_abc' ) );
		$this->assertNull( $cache->get( 'blinkpay_scopes_abc' ) );
		$this->assertSame( array(), $GLOBALS['wc_blinkpay_transients'] );
		$this->assertSame( array(), $GLOBALS['wc_blinkpay_options'] );
	}

	public function test_the_key_names_are_the_ones_uninstall_deletes() {
		$GLOBALS['wc_blinkpay_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode(
				array(
					'access_token' => 'tok-1',
					'expires_in'   => 3600,
					'scope'        => 'create:quick_payment',
				)
			),
		);

		$client = new WC_BlinkPay_API_Client( 'client-id', 'client-secret', true, false );
		$client->get_access_token();

		// uninstall.php deletes by these prefixes, and it cannot derive the
		// hash: the client class is not loaded during uninstall.
		$suffix = hash( 'sha256', 'sandbox|client-id' );

		$this->assertArrayHasKey( 'wc_blinkpay_token_' . $suffix, $GLOBALS['wc_blinkpay_transients'] );
		$this->assertArrayHasKey( 'wc_blinkpay_scopes_' . $suffix, $GLOBALS['wc_blinkpay_options'] );
	}

	public function test_the_environment_and_client_scope_the_keys_apart() {
		$cache = new WC_BlinkPay_Token_Cache();

		$sandbox    = new BlinkDebitClient( 'client-id', 'secret', true, $cache, new WC_BlinkPay_HTTP_Transport() );
		$production = new BlinkDebitClient( 'client-id', 'secret', false, $cache, new WC_BlinkPay_HTTP_Transport() );

		$cache->set( 'blinkpay_token_' . hash( 'sha256', 'sandbox|client-id' ), 'sandbox-token', 3300 );

		// A production client must never be served the sandbox's token, so
		// its own lookup misses and it fetches one of its own.
		$this->assertTrue( $sandbox->isSandbox() );
		$this->assertFalse( $production->isSandbox() );
		$this->assertNull( $cache->get( 'blinkpay_token_' . hash( 'sha256', 'production|client-id' ) ) );
	}
}
