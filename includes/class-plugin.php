<?php
/**
 * Plugin bootstrap.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class MPO_Plugin {

	/**
	 * Instance.
	 *
	 * @var MPO_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return MPO_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Activate.
	 */
	public static function activate() {
		require_once MPO_PATH . 'includes/class-install.php';
		MPO_Install::activate();
	}

	/**
	 * Deactivate.
	 */
	public static function deactivate() {
		require_once MPO_PATH . 'includes/class-install.php';
		MPO_Install::deactivate();
	}

	/**
	 * Load includes and hooks.
	 */
	private function init() {
		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		$this->includes();

		MPO_Install::maybe_upgrade();
		MPO_Compatibility::init();
		MPO_Shipping_Method::register();
		MPO_REST::init();

		if ( is_admin() ) {
			MPO_Admin::init();
		}
	}

	/**
	 * Load class files.
	 */
	private function includes() {
		require_once MPO_PATH . 'includes/class-install.php';
		require_once MPO_PATH . 'includes/class-draft.php';
		require_once MPO_PATH . 'includes/class-draft-repository.php';
		require_once MPO_PATH . 'includes/class-isolated-session.php';
		require_once MPO_PATH . 'includes/class-context.php';
		require_once MPO_PATH . 'includes/class-compatibility.php';
		require_once MPO_PATH . 'includes/class-bogo.php';
		require_once MPO_PATH . 'includes/class-loyalty.php';
		require_once MPO_PATH . 'includes/class-shipping-method.php';
		require_once MPO_PATH . 'includes/class-cart-service.php';
		require_once MPO_PATH . 'includes/class-catalog.php';
		require_once MPO_PATH . 'includes/class-customers.php';
		require_once MPO_PATH . 'includes/class-checkout-service.php';
		require_once MPO_PATH . 'includes/rest/class-rest.php';
		require_once MPO_PATH . 'includes/admin/class-admin.php';
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	private function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function missing_woocommerce_notice() {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Manual Phone Orders requires WooCommerce to be installed and active.', 'manual-phone-orders' );
		echo '</p></div>';
	}
}

/**
 * Toggle / read the isolated cart context flag.
 *
 * @param bool|null $set Optional new value.
 * @return bool
 */
function mpo_doing_context( $set = null ) {
	static $active = false;
	if ( null !== $set ) {
		$active = (bool) $set;
	}
	return $active;
}
