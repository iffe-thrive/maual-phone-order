<?php
/**
 * Plugin Name: Manual Phone Orders
 * Plugin URI:  https://github.com/iffe-thrive/maual-phone-order
 * Description: Fast, AJAX phone/manual order entry for WooCommerce. Isolated per-customer carts, no page reloads, compatible with pricing and funds plugins.
 * Version:     1.0.18
 * Author:      Manual Phone Orders
 * Text Domain: manual-phone-orders
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 10.2
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

define( 'MPO_VERSION', '1.0.18' );
define( 'MPO_FILE', __FILE__ );
define( 'MPO_PATH', plugin_dir_path( __FILE__ ) );
define( 'MPO_URL', plugin_dir_url( __FILE__ ) );
define( 'MPO_BASENAME', plugin_basename( __FILE__ ) );

require_once MPO_PATH . 'includes/class-plugin.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MPO_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MPO_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( 'MPO_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MPO_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'MPO_Plugin', 'instance' ) );
