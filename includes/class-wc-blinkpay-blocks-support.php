<?php
/**
 * Block-based checkout integration for the BlinkPay gateway.
 *
 * @package blinkpay-nz-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers BlinkPay as a payment method on the checkout block.
 */
final class WC_BlinkPay_Blocks_Support extends AbstractPaymentMethodType {

	/** @var string */
	protected $name = 'blinkpay';

	/**
	 * Loads the gateway settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_blinkpay_settings', array() );
	}

	/**
	 * @return bool
	 */
	public function is_active() {
		$gateway = wc_blinkpay_gateway();

		return $gateway && $gateway->is_available();
	}

	/**
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'wc-blinkpay-blocks',
			WC_BLINKPAY_PLUGIN_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			WC_BLINKPAY_VERSION,
			true
		);

		return array( 'wc-blinkpay-blocks' );
	}

	/**
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = wc_blinkpay_gateway();

		return array(
			'title'       => $this->get_setting( 'title', __( 'Checkout with BlinkPay', 'blinkpay-nz-for-woocommerce' ) ),
			'description' => $this->get_setting( 'description', '' ),
			'icon'        => $gateway ? $gateway->icon : '',
			'supports'    => $gateway ? array_filter( $gateway->supports, array( $gateway, 'supports' ) ) : array( 'products' ),
		);
	}
}
