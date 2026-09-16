<?php
/**
 * WordPress token cache for the Blink Debit SDK.
 *
 * @package blinkpay-nz-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

use BlinkPay\BlinkDebit\TokenCacheInterface;

/**
 * Persists the SDK's access token and granted scopes in WordPress storage, so
 * a token survives between requests without APCu and the scope grant survives
 * the token itself.
 *
 * The two values need different lifetimes, which the interface expresses as
 * the TTL: an expiring value (the token) becomes a transient, and a value with
 * no expiry (the scopes) becomes an option. The scopes decide whether the
 * gateway advertises refund support, so they must still be known once the
 * token they arrived with has expired.
 *
 * Keys are prefixed with wc_ so they land on the names this plugin has always
 * used — the SDK hashes the same environment and client ID — which keeps a
 * token cached by an earlier version valid across the upgrade and keeps
 * uninstall.php's cleanup patterns matching.
 */
class WC_BlinkPay_Token_Cache implements TokenCacheInterface {

	const KEY_PREFIX = 'wc_';

	/**
	 * The cached value, or null when missing or expired.
	 *
	 * @param string $key The SDK's cache key.
	 * @return string|null
	 */
	public function get( string $key ): ?string {
		$name = self::KEY_PREFIX . $key;

		$value = get_transient( $name );
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}

		$value = get_option( $name, '' );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Stores a value. A TTL makes it a transient; a null TTL — "no expiry" —
	 * makes it an option, which is how the granted scopes outlive the token.
	 *
	 * @param string   $key          The SDK's cache key.
	 * @param string   $value        The value to store.
	 * @param int|null $ttl_seconds  Lifetime in seconds, or null for no expiry.
	 */
	public function set( string $key, string $value, ?int $ttl_seconds ): void {
		$name = self::KEY_PREFIX . $key;

		if ( null === $ttl_seconds ) {
			// Never autoloaded: these are read only when the API is called,
			// so they have no business on every page load.
			update_option( $name, $value, false );
			return;
		}

		set_transient( $name, $value, $ttl_seconds );
	}

	/**
	 * Clears both backings, since the caller does not know which one holds the
	 * key and a value left behind would outlive the delete.
	 *
	 * @param string $key The SDK's cache key.
	 */
	public function delete( string $key ): void {
		$name = self::KEY_PREFIX . $key;

		delete_transient( $name );
		delete_option( $name );
	}
}
