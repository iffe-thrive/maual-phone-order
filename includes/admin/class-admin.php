<?php
/**
 * Admin screen.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Phone Orders page in wp-admin.
 */
class MPO_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'woocommerce_is_checkout', array( __CLASS__, 'force_checkout' ) );
	}

	/**
	 * Top-level menu for operators.
	 */
	public static function menu() {
		add_menu_page(
			__( 'Phone Orders', 'manual-phone-orders' ),
			__( 'Phone Orders', 'manual-phone-orders' ),
			'mpo_create_orders',
			'mpo-phone-orders',
			array( __CLASS__, 'render' ),
			'dashicons-phone',
			56
		);
	}

	/**
	 * Whether this is our screen.
	 *
	 * @return bool
	 */
	private static function is_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'toplevel_page_mpo-phone-orders' === $screen->id;
	}

	/**
	 * Body class for full-bleed layout.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		if ( self::is_screen() ) {
			$classes .= ' mpo-screen';
		}
		return $classes;
	}

	/**
	 * Let payment gateways enqueue their checkout scripts on this screen.
	 *
	 * @param bool $is Checkout.
	 * @return bool
	 */
	public static function force_checkout( $is ) {
		return self::is_screen() ? true : $is;
	}

	/**
	 * Enqueue SPA assets.
	 *
	 * @param string $hook Hook.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_mpo-phone-orders' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'mpo-app',
			MPO_URL . 'assets/css/app.css',
			array(),
			MPO_VERSION
		);

		wp_enqueue_script(
			'mpo-app',
			MPO_URL . 'assets/js/app.js',
			array( 'jquery' ),
			MPO_VERSION,
			true
		);

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
				if ( is_callable( array( $gateway, 'payment_scripts' ) ) ) {
					$gateway->payment_scripts();
				}
			}
		}

		wp_localize_script(
			'mpo-app',
			'MPO',
			array(
				'root'  => esc_url_raw( rest_url( 'mpo/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'guest'            => __( 'Guest', 'manual-phone-orders' ),
					'searchCustomers'  => __( 'Search name, email, or phone…', 'manual-phone-orders' ),
					'searchProducts'   => __( 'Search name or SKU…', 'manual-phone-orders' ),
					'add'              => __( 'Add', 'manual-phone-orders' ),
					'emptyCart'        => __( 'Cart is empty. Search a product to add it.', 'manual-phone-orders' ),
					'placeOrder'       => __( 'Place order', 'manual-phone-orders' ),
					'placePaid'        => __( 'Place order & mark paid', 'manual-phone-orders' ),
					'hold'             => __( 'Hold', 'manual-phone-orders' ),
					'newOrder'         => __( 'New order', 'manual-phone-orders' ),
					'loadOrder'        => __( 'Load order', 'manual-phone-orders' ),
					'selectOptions'    => __( 'Select options', 'manual-phone-orders' ),
					'qtyPending'       => __( 'Update the cart to save quantities.', 'manual-phone-orders' ),
					'orderPlaced'      => __( 'Order placed', 'manual-phone-orders' ),
					'error'            => __( 'Something went wrong.', 'manual-phone-orders' ),
					'selectPayment'    => __( 'Select a payment method.', 'manual-phone-orders' ),
					'enterCardDetails' => __( 'Enter the card number, expiry, and CVC in Payment, then place the order.', 'manual-phone-orders' ),
					'completeFields'   => __( 'Complete the highlighted fields in Payment, then place the order.', 'manual-phone-orders' ),
					'emptyCartPlace'   => __( 'Add a product to the cart before placing the order.', 'manual-phone-orders' ),
				),
			)
		);
	}

	/**
	 * App shell.
	 */
	public static function render() {
		if ( ! current_user_can( 'mpo_create_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to create phone orders.', 'manual-phone-orders' ) );
		}
		include MPO_PATH . 'includes/admin/views/app.php';
	}
}
