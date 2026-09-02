<?php
/**
 * The exception thrown to veto a refund BlinkPay was not involved in.
 *
 * @package blinkpay-nz-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thrown from the woocommerce_create_refund hook to reject a manual refund on
 * a BlinkPay order. wc_create_refund() catches Exception, deletes the refund
 * it just created and hands the message back as a WP_Error, so the veto works
 * for every creation path — the admin screen, the REST API and code.
 */
class WC_BlinkPay_Refund_Blocked_Exception extends Exception {
}
