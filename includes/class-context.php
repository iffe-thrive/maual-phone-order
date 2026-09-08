<?php
/**
 * Enter/leave a customer cart context without logging the operator out.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Swaps WC session/cart/customer to an isolated per-customer draft.
 */
class MPO_Context {

	/**
	 * Original user id.
	 *
	 * @var int
	 */
	private $original_user_id = 0;

	/**
	 * Original session.
	 *
	 * @var WC_Session|null
	 */
	private $original_session = null;

	/**
	 * Original customer.
	 *
	 * @var WC_Customer|null
	 */
	private $original_customer = null;

	/**
	 * Original cart.
	 *
	 * @var WC_Cart|null
	 */
	private $original_cart = null;

	/**
	 * Active draft.
	 *
	 * @var MPO_Draft|null
	 */
	private $draft = null;

	/**
	 * Isolated cart created for this context.
	 *
	 * @var WC_Cart|null
	 */
	private $isolated_cart = null;

	/**
	 * Isolated cart session (hooks must be removed on leave).
	 *
	 * @var WC_Cart_Session|null
	 */
	private $isolated_cart_session = null;

	/**
	 * Isolated WC session.
	 *
	 * @var MPO_Isolated_Session|null
	 */
	private $isolated_session = null;

	/**
	 * Whether context is entered.
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Enter isolated cart context as the selected customer.
	 *
	 * @param MPO_Draft $draft Draft.
	 */
	public function enter( MPO_Draft $draft ) {
		if ( $this->active ) {
			return;
		}

		mpo_doing_context( true );

		$this->draft             = $draft;
		$this->original_user_id  = get_current_user_id();
		$this->original_session  = WC()->session;
		$this->original_customer = WC()->customer;
		$this->original_cart     = WC()->cart;

		$this->ensure_frontend();

		add_filter( 'woocommerce_persistent_cart_enabled', '__return_false', 9999 );
		add_filter( 'woocommerce_is_checkout', array( $this, 'force_true' ), 9999 );
		add_filter( 'woocommerce_is_cart', array( $this, 'force_true' ), 9999 );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'checkout_value' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'empty_string' ), 9999 );

		wp_set_current_user( (int) $draft->customer_id );

		$this->isolated_session = new MPO_Isolated_Session( $draft );
		$this->isolated_session->init();
		WC()->session = $this->isolated_session;

		$customer_id   = (int) $draft->customer_id;
		WC()->customer = new WC_Customer( $customer_id, true );

		$this->apply_draft_customer_overrides( $draft );

		add_filter( 'woocommerce_cart_session_initialize', array( $this, 'capture_cart_session' ), 9999, 2 );
		$this->isolated_cart = new WC_Cart();
		WC()->cart           = $this->isolated_cart;
		remove_filter( 'woocommerce_cart_session_initialize', array( $this, 'capture_cart_session' ), 9999 );

		// Cookie hooks call WC()->cart on shutdown; after leave() that is null in admin REST.
		$this->unhook_cart_cookies();

		if ( $this->isolated_cart_session && method_exists( $this->isolated_cart_session, 'get_cart_from_session' ) ) {
			$this->isolated_cart_session->get_cart_from_session();
		} else {
			WC()->cart->get_cart();
		}

		do_action( 'mpo_context_entered', $customer_id, $this->original_user_id, $draft );

		$this->active = true;
	}

	/**
	 * Remember the WC_Cart_Session created with the isolated cart.
	 *
	 * @param bool            $initialize Whether to initialize hooks.
	 * @param WC_Cart_Session $session    Cart session.
	 * @return bool
	 */
	public function capture_cart_session( $initialize, $session ) {
		$this->isolated_cart_session = $session;
		return $initialize;
	}

	/**
	 * Leave context, persist session, restore operator.
	 */
	public function leave() {
		if ( ! $this->active ) {
			return;
		}

		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}

		if ( WC()->session && method_exists( WC()->session, 'save_data' ) ) {
			WC()->session->save_data();
		}

		if ( $this->draft && WC()->session instanceof MPO_Isolated_Session ) {
			$this->draft->session_data = WC()->session->get_session_data();
			MPO_Draft_Repository::save( $this->draft );
		}

		do_action( 'mpo_context_leaving', $this->draft );

		$this->detach_isolated_hooks();

		wp_set_current_user( $this->original_user_id );

		WC()->session  = $this->original_session;
		WC()->customer = $this->original_customer;
		WC()->cart     = $this->original_cart;

		remove_filter( 'woocommerce_persistent_cart_enabled', '__return_false', 9999 );
		remove_filter( 'woocommerce_is_checkout', array( $this, 'force_true' ), 9999 );
		remove_filter( 'woocommerce_is_cart', array( $this, 'force_true' ), 9999 );
		remove_filter( 'woocommerce_checkout_get_value', array( $this, 'checkout_value' ), 10 );
		remove_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'empty_string' ), 9999 );

		mpo_doing_context( false );
		$this->active = false;
	}

	/**
	 * Stop the isolated cart from writing storefront cookies on shutdown.
	 */
	private function unhook_cart_cookies() {
		if ( ! $this->isolated_cart_session ) {
			return;
		}

		remove_action( 'woocommerce_add_to_cart', array( $this->isolated_cart_session, 'maybe_set_cart_cookies' ) );
		remove_action( 'wp', array( $this->isolated_cart_session, 'maybe_set_cart_cookies' ), 99 );
		remove_action( 'shutdown', array( $this->isolated_cart_session, 'maybe_set_cart_cookies' ), 0 );
	}

	/**
	 * Drop hooks from isolated cart/session objects so they cannot run after restore.
	 */
	private function detach_isolated_hooks() {
		if ( $this->isolated_session ) {
			remove_action( 'shutdown', array( $this->isolated_session, 'save_data' ), 20 );
		}

		if ( $this->isolated_cart ) {
			remove_action( 'woocommerce_add_to_cart', array( $this->isolated_cart, 'calculate_totals' ), 20 );
			remove_action( 'woocommerce_applied_coupon', array( $this->isolated_cart, 'calculate_totals' ), 20 );
			remove_action( 'woocommerce_removed_coupon', array( $this->isolated_cart, 'calculate_totals' ), 20 );
			remove_action( 'woocommerce_cart_item_removed', array( $this->isolated_cart, 'calculate_totals' ), 20 );
			remove_action( 'woocommerce_cart_item_restored', array( $this->isolated_cart, 'calculate_totals' ), 20 );
			remove_action( 'woocommerce_check_cart_items', array( $this->isolated_cart, 'check_cart_items' ), 1 );
			remove_action( 'woocommerce_check_cart_items', array( $this->isolated_cart, 'check_cart_coupons' ), 1 );
			remove_action( 'woocommerce_after_checkout_validation', array( $this->isolated_cart, 'check_customer_coupons' ), 1 );
		}

		if ( $this->isolated_cart_session ) {
			$session = $this->isolated_cart_session;
			remove_action( 'wp_loaded', array( $session, 'get_cart_from_session' ) );
			remove_action( 'woocommerce_cart_emptied', array( $session, 'destroy_cart_session' ) );
			remove_action( 'woocommerce_after_calculate_totals', array( $session, 'set_session' ), 1000 );
			remove_action( 'woocommerce_removed_coupon', array( $session, 'set_session' ) );
			remove_action( 'woocommerce_add_to_cart', array( $session, 'persistent_cart_update' ) );
			remove_action( 'woocommerce_cart_item_removed', array( $session, 'persistent_cart_update' ) );
			remove_action( 'woocommerce_cart_item_restored', array( $session, 'persistent_cart_update' ) );
			remove_action( 'woocommerce_cart_item_set_quantity', array( $session, 'persistent_cart_update' ) );
			remove_action( 'template_redirect', array( $session, 'clean_up_removed_cart_contents' ) );
			$this->unhook_cart_cookies();
		}
	}

	/**
	 * Current draft.
	 *
	 * @return MPO_Draft|null
	 */
	public function get_draft() {
		return $this->draft;
	}

	/**
	 * Filter helper.
	 *
	 * @return true
	 */
	public function force_true() {
		return true;
	}

	/**
	 * Empty string helper.
	 *
	 * @return string
	 */
	public function empty_string() {
		return '';
	}

	/**
	 * Prefill checkout values from the WC customer object.
	 *
	 * @param mixed  $value Value.
	 * @param string $input Input key.
	 * @return mixed
	 */
	public function checkout_value( $value, $input ) {
		if ( ! WC()->customer ) {
			return $value;
		}

		$method = 'get_' . $input;
		if ( is_callable( array( WC()->customer, $method ) ) ) {
			$from_customer = WC()->customer->{$method}();
			if ( '' !== $from_customer && null !== $from_customer ) {
				return $from_customer;
			}
		}

		return $value;
	}

	/**
	 * Load cart/session classes in admin REST.
	 */
	private function ensure_frontend() {
		if ( ! function_exists( 'wc_load_cart' ) ) {
			return;
		}

		include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
		include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
		include_once WC_ABSPATH . 'includes/class-wc-cart.php';

		if ( ! did_action( 'woocommerce_load_cart_from_session' ) && method_exists( WC(), 'frontend_includes' ) ) {
			WC()->frontend_includes();
		}

		WC()->shipping();
		WC()->payment_gateways();
	}

	/**
	 * Apply billing/shipping stored on the draft (guest or edited addresses).
	 *
	 * @param MPO_Draft $draft Draft.
	 */
	private function apply_draft_customer_overrides( MPO_Draft $draft ) {
		$billing  = $draft->get_meta( 'billing', array() );
		$shipping = $draft->get_meta( 'shipping', array() );

		if ( is_array( $billing ) ) {
			foreach ( $billing as $key => $val ) {
				$method = 'set_billing_' . $key;
				if ( is_callable( array( WC()->customer, $method ) ) ) {
					WC()->customer->{$method}( $val );
				}
			}
		}

		if ( is_array( $shipping ) ) {
			foreach ( $shipping as $key => $val ) {
				$method = 'set_shipping_' . $key;
				if ( is_callable( array( WC()->customer, $method ) ) ) {
					WC()->customer->{$method}( $val );
				}
			}
		}

		$calc_address = ! empty( $shipping ) ? $shipping : $billing;
		if ( ! empty( $calc_address['country'] ) ) {
			WC()->customer->set_calculated_shipping( true );
		}
	}
}
