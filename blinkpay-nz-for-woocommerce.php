<?php
/**
 * Plugin Name: BlinkPay NZ for WooCommerce
 * Plugin URI: https://github.com/BlinkPay/Blink-Payments-API-WooCommerce
 * Description: Accept New Zealand bank payments through BlinkPay open banking with Blink PayNow one-off payments.
 * Version: 1.1.0
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

define( 'WC_BLINKPAY_VERSION', '1.1.0' );
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

	// WooCommerce's stock-hold sweep (wc_cancel_unpaid_orders()) cancels stale
	// pending orders after the hold window — 60 minutes by default, narrower
	// than the deferred check schedule, whose last tier spaces checks 2 hours
	// apart — so a payment could settle onto a cancelled order. Orders still
	// awaiting a payment outcome are kept.
	add_filter(
		'woocommerce_cancel_unpaid_order',
		function ( $should_cancel, $order ) {
			if ( $should_cancel ) {
				$gateway = wc_blinkpay_gateway();
				if ( $gateway && $gateway->has_unresolved_quick_payment( $order ) ) {
					return false;
				}
			}
			return $should_cancel;
		},
		10,
		2
	);

	// WooCommerce renders its "Refund manually" button unconditionally, and a
	// manual refund records money as returned without BlinkPay involvement.
	// Hiding the button is presentation; the record-integrity control is the
	// server-side rejection below, which devtools, the REST API and markup
	// changes cannot bypass.
	add_action( 'admin_head', 'wc_blinkpay_hide_manual_refund_button' );
	add_action( 'woocommerce_create_refund', 'wc_blinkpay_block_manual_refund', 10, 2 );

	// The customer's account number for a manual (account_number) refund is
	// shown in this panel, fetched live from the BlinkPay API at render time
	// — visible to the merchant on the order screen, never stored in
	// WordPress.
	add_action( 'add_meta_boxes', 'wc_blinkpay_register_manual_refunds_panel', 10, 2 );
}

/**
 * Registers the BlinkPay manual refunds panel on the order edit screen (the
 * HPOS screen and the legacy post editor) for BlinkPay orders carrying at
 * least one account_number refund. The panel shows the customer's account
 * number fetched live from the API, so the merchant can make the transfer
 * straight from the order page without the number ever being persisted.
 *
 * @param string $screen_id The current screen ID (the post type on the legacy editor).
 * @param mixed  $object    The order (HPOS) or post (legacy) being edited.
 */
function wc_blinkpay_register_manual_refunds_panel( $screen_id, $object ) {
	if ( ! in_array( $screen_id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
		return;
	}

	if ( $object instanceof WC_Abstract_Order ) {
		$order = $object;
	} else {
		$order = wc_get_order( is_object( $object ) && isset( $object->ID ) ? $object->ID : 0 );
	}

	if ( ! $order || 'blinkpay' !== $order->get_payment_method() || ! $order->get_meta( '_blinkpay_manual_refunds' ) ) {
		return;
	}

	add_meta_box(
		'wc-blinkpay-manual-refunds',
		__( 'BlinkPay manual refunds', 'blinkpay-nz-for-woocommerce' ),
		function () use ( $order ) {
			$gateway = wc_blinkpay_gateway();
			if ( $gateway ) {
				$gateway->render_manual_refund_panel( $order );
			}
		},
		$screen_id,
		'side',
		'high'
	);
}

/**
 * Rejects a manual (non-gateway) refund on an order paid with BlinkPay. A
 * manual refund records money as returned while no money has moved and no
 * account number has been retrieved, so every money-carrying BlinkPay refund
 * must go through the gateway. Throwing makes wc_create_refund() delete the
 * refund it just created and hand the message back as a WP_Error, so this
 * covers the REST API and any path that bypasses the hidden button. A
 * zero-amount refund (a restock-only correction) claims no money moved and
 * is allowed through.
 *
 * @param WC_Order_Refund $refund The refund being created.
 * @param array           $args   The wc_create_refund() arguments.
 * @throws Exception When the refund would record a return BlinkPay was not involved in.
 */
function wc_blinkpay_block_manual_refund( $refund, $args ) {
	if ( ! empty( $args['refund_payment'] ) || ( isset( $args['amount'] ) && (float) $args['amount'] <= 0 ) ) {
		return;
	}

	$order = isset( $args['order_id'] ) ? wc_get_order( $args['order_id'] ) : false;
	if ( $order && 'blinkpay' === $order->get_payment_method() ) {
		throw new Exception(
			esc_html__( 'BlinkPay orders cannot be refunded manually: a manual refund records money as returned while none has moved. Use "Refund via BlinkPay" instead.', 'blinkpay-nz-for-woocommerce' )
		);
	}
}

/**
 * Hides WooCommerce's "Refund manually" button on orders paid with BlinkPay,
 * steering merchants to "Refund via BlinkPay" before they submit anything.
 * Cosmetic only — wc_blinkpay_block_manual_refund() is the actual control.
 */
function wc_blinkpay_hide_manual_refund_button() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
		return;
	}

	// HPOS order screens carry the order in ?id=, the legacy post editor in ?post=.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
	$order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 );
	$order    = $order_id ? wc_get_order( $order_id ) : false;

	if ( $order && 'blinkpay' === $order->get_payment_method() ) {
		echo '<style>.wc-order-refund-items .do-manual-refund { display: none !important; }</style>';
	}
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
