<?php
/**
 * Plugin Name: BlinkPay NZ for WooCommerce
 * Plugin URI: https://github.com/BlinkPay/Blink-Payments-API-WooCommerce
 * Description: Accept New Zealand bank payments through BlinkPay open banking with Blink PayNow one-off payments.
 * Version: 1.0.3
 * Author: BlinkPay
 * Author URI: https://www.blinkpay.co.nz
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: blinkpay-nz-for-woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 11.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_BLINKPAY_VERSION', '1.0.3' );
define( 'WC_BLINKPAY_PLUGIN_FILE', __FILE__ );
define( 'WC_BLINKPAY_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_BLINKPAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Declare compatibility with high-performance order storage and the block-based checkout.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', 'wc_blinkpay_init', 11 );

/**
 * Initialises the plugin once WooCommerce is available.
 */
function wc_blinkpay_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'wc_blinkpay_woocommerce_missing_notice' );
		return;
	}

	require_once WC_BLINKPAY_PLUGIN_PATH . 'includes/class-wc-blinkpay-api-client.php';
	require_once WC_BLINKPAY_PLUGIN_PATH . 'includes/class-wc-blinkpay-gateway.php';

	add_filter(
		'woocommerce_payment_gateways',
		function ( $gateways ) {
			$gateways[] = 'WC_BlinkPay_Gateway';
			return $gateways;
		}
	);

	// The customer returns from the Blink gateway to /wc-api/blinkpay_return/.
	add_action(
		'woocommerce_api_blinkpay_return',
		function () {
			$gateway = wc_blinkpay_gateway();
			if ( $gateway ) {
				$gateway->handle_return();
			}
		}
	);

	// Deferred status checks run on WP-Cron, where gateways are not loaded by default.
	add_action(
		WC_BlinkPay_Gateway::STATUS_CHECK_HOOK,
		function ( $order_id ) {
			$gateway = wc_blinkpay_gateway();
			if ( $gateway ) {
				$gateway->check_payment_status( $order_id );
			}
		}
	);
}

/**
 * Shows the WooCommerce dependency notice, only to users who can act on it.
 */
function wc_blinkpay_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'BlinkPay NZ for WooCommerce requires WooCommerce to be installed and active.', 'blinkpay-nz-for-woocommerce' )
		. '</p></div>';
}

/**
 * Returns the registered BlinkPay gateway instance, if any.
 *
 * @return WC_BlinkPay_Gateway|null
 */
function wc_blinkpay_gateway() {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return null;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();

	return isset( $gateways['blinkpay'] ) ? $gateways['blinkpay'] : null;
}

// Block-based checkout integration.
add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once WC_BLINKPAY_PLUGIN_PATH . 'includes/class-wc-blinkpay-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				$registry->register( new WC_BlinkPay_Blocks_Support() );
			}
		);
	}
);

// Settings shortcut on the plugins screen.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=blinkpay' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'blinkpay-nz-for-woocommerce' ) . '</a>' );
		return $links;
	}
);
