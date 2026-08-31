<?php
/**
 * Removes plugin settings and cached tokens on uninstall. Order metadata is
 * kept: it is part of the merchant's payment records.
 *
 * @package blinkpay-nz-for-woocommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_blinkpay_settings' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transient and option names are hashed, so they cannot be deleted by key.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_wc\_blinkpay\_token\_%'
	    OR option_name LIKE '\_transient\_timeout\_wc\_blinkpay\_token\_%'
	    OR option_name LIKE 'wc\_blinkpay\_scopes\_%'"
);
