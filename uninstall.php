<?php
/**
 * Removes plugin settings and cached tokens on uninstall. Order metadata is
 * kept: it is part of the merchant's payment records.
 *
 * @package blinkpay-nz-for-woocommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_blinkpay_settings' );

// Pending deferred status checks would otherwise fire against a hook with no
// handler forever. The hook name is a literal: the gateway class that defines
// the constant is not loaded during uninstall.
wp_unschedule_hook( 'wc_blinkpay_check_payment_status' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transient and option names are hashed or per-order, so they cannot be deleted by key.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_wc\_blinkpay\_token\_%'
	    OR option_name LIKE '\_transient\_timeout\_wc\_blinkpay\_token\_%'
	    OR option_name LIKE 'wc\_blinkpay\_scopes\_%'
	    OR option_name LIKE 'wc\_blinkpay\_order\_%.lock'"
);

// The direct DELETE bypasses the object cache entries update_option() made
// for the hashed wc_blinkpay_scopes_* options, whose names cannot be derived
// here; without a flush, a reinstall inside the cache TTL would read the old
// scope grant.
wp_cache_flush();
