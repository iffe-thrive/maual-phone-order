<?php
/**
 * Optional manual shipping method (only visible inside phone-order context).
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register shipping method.
 */
class MPO_Shipping_Method {

	/**
	 * Hook registration.
	 */
	public static function register() {
		add_action( 'woocommerce_shipping_init', array( __CLASS__, 'define_method_class' ) );
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'add_method' ) );
	}

	/**
	 * Load WC_Shipping_Method subclass after WooCommerce defines the parent.
	 */
	public static function define_method_class() {
		if ( class_exists( 'MPO_WC_Shipping_Manual', false ) ) {
			return;
		}
		require_once MPO_PATH . 'includes/class-wc-shipping-manual.php';
	}

	/**
	 * Add method class.
	 *
	 * @param array $methods Methods.
	 * @return array
	 */
	public static function add_method( $methods ) {
		$methods['mpo_manual'] = 'MPO_WC_Shipping_Manual';
		return $methods;
	}
}
