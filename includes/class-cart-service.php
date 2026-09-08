<?php
/**
 * Cart mutations inside an isolated customer context.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add / update / serialize cart.
 */
class MPO_Cart_Service {

	/**
	 * Run a callback inside the draft's customer cart context.
	 *
	 * @param MPO_Draft $draft    Draft.
	 * @param callable  $callback Callback.
	 * @return mixed
	 */
	public static function with_context( MPO_Draft $draft, callable $callback ) {
		$context = new MPO_Context();
		$context->enter( $draft );

		try {
			wc_clear_notices();
			$result  = $callback( $context );
			self::recalculate();
			$payload = self::serialize( $draft );
		} finally {
			$context->leave();
		}

		return isset( $result ) && is_array( $result ) && isset( $result['_merge'] )
			? array_merge( $payload, $result['_merge'] )
			: $payload;
	}

	/**
	 * Recalculate shipping + totals once.
	 */
	public static function recalculate() {
		if ( ! WC()->cart ) {
			return;
		}

		WC()->cart->calculate_shipping();
		if ( self::lock_custom_shipping_choice() ) {
			WC()->cart->calculate_shipping();
		}
		WC()->cart->calculate_fees();
		WC()->cart->calculate_totals();
		if ( class_exists( 'MPO_Bogo' ) && MPO_Bogo::sync_after_totals() ) {
			WC()->cart->calculate_shipping();
			if ( self::lock_custom_shipping_choice() ) {
				WC()->cart->calculate_shipping();
			}
			WC()->cart->calculate_fees();
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Keep the operator-chosen manual rate after WooCommerce rebuilds the method list.
	 *
	 * @return bool True when a custom amount is active.
	 */
	private static function lock_custom_shipping_choice() {
		if ( ! WC()->session ) {
			return false;
		}

		$amount = WC()->session->get( 'mpo_custom_shipping', null );
		if ( null === $amount || '' === $amount ) {
			return false;
		}

		$packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();
		$counts   = array();
		foreach ( $packages as $i => $package ) {
			$counts[ $i ] = ! empty( $package['rates'] ) ? count( $package['rates'] ) : 0;
		}

		WC()->session->set( 'chosen_shipping_methods', array( 'mpo_manual' ) );
		WC()->session->set( 'shipping_method_counts', $counts );
		return true;
	}

	/**
	 * Add a catalog product.
	 *
	 * @param int   $product_id   Product.
	 * @param int   $qty          Qty.
	 * @param int   $variation_id Variation.
	 * @param array $variation    Attributes.
	 * @param array $extra        Extra cart item data.
	 * @return string|false Cart item key.
	 */
	public static function add_product( $product_id, $qty = 1, $variation_id = 0, $variation = array(), $extra = array() ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			throw new Exception( __( 'Product not found.', 'manual-phone-orders' ) );
		}

		if ( $product->is_type( 'variable' ) && ! $variation_id ) {
			throw new Exception( 'needs_variation' );
		}

		$as_gift = ! empty( $extra['mpo_gift'] );
		unset( $extra['mpo_gift'] );
		if ( $as_gift && class_exists( 'MPO_Bogo' ) ) {
			$qty = 1;
			MPO_Bogo::remove_existing_gifts();
			MPO_Bogo::prepare_gift_add( $product_id, $variation_id );
			$extra = array_merge( MPO_Bogo::gift_cart_item_data(), $extra );
		}

		$key = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation, $extra );
		if ( ! $key ) {
			$notices = wc_get_notices( 'error' );
			$message = __( 'Could not add that product to the cart.', 'manual-phone-orders' );
			if ( ! empty( $notices ) ) {
				$first   = reset( $notices );
				$message = is_array( $first ) && isset( $first['notice'] ) ? wp_strip_all_tags( $first['notice'] ) : $message;
			}
			throw new Exception( $message );
		}

		return $key;
	}

	/**
	 * Batch-update quantities (one totals pass).
	 *
	 * @param array $items Array of {key, qty}.
	 */
	public static function update_quantities( array $items ) {
		foreach ( $items as $row ) {
			$key = isset( $row['key'] ) ? wc_clean( wp_unslash( $row['key'] ) ) : '';
			$qty = isset( $row['qty'] ) ? (float) $row['qty'] : 0;
			if ( ! $key ) {
				continue;
			}
			if ( $qty <= 0 ) {
				WC()->cart->remove_cart_item( $key );
			} else {
				WC()->cart->set_quantity( $key, $qty, false );
			}
		}
	}

	/**
	 * Remove a line.
	 *
	 * @param string $key Cart key.
	 */
	public static function remove_item( $key ) {
		WC()->cart->remove_cart_item( $key );
	}

	/**
	 * Set a custom unit price on a line.
	 *
	 * @param string $key   Cart key.
	 * @param float  $price Price.
	 */
	public static function set_item_price( $key, $price ) {
		$cart = WC()->cart->get_cart();
		if ( ! isset( $cart[ $key ] ) ) {
			throw new Exception( __( 'Cart item not found.', 'manual-phone-orders' ) );
		}
		WC()->cart->cart_contents[ $key ]['mpo_custom_price'] = (float) $price;
		if ( isset( WC()->cart->cart_contents[ $key ]['data'] ) ) {
			WC()->cart->cart_contents[ $key ]['data']->set_price( (float) $price );
		}
	}

	/**
	 * Apply coupon.
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function apply_coupon( $code ) {
		$code = wc_format_coupon_code( $code );
		if ( ! $code ) {
			throw new Exception( __( 'Enter a coupon code.', 'manual-phone-orders' ) );
		}
		$result = WC()->cart->apply_coupon( $code );
		if ( ! $result ) {
			$notices = wc_get_notices( 'error' );
			$message = __( 'Coupon could not be applied.', 'manual-phone-orders' );
			if ( ! empty( $notices ) ) {
				$first   = reset( $notices );
				$message = is_array( $first ) && isset( $first['notice'] ) ? wp_strip_all_tags( $first['notice'] ) : $message;
			}
			throw new Exception( $message );
		}
		return true;
	}

	/**
	 * Remove coupon.
	 *
	 * @param string $code Code.
	 */
	public static function remove_coupon( $code ) {
		WC()->cart->remove_coupon( $code );
	}

	/**
	 * Store a fee to reapply on calculate_fees.
	 *
	 * @param string $name    Label.
	 * @param float  $amount  Amount.
	 * @param bool   $taxable Taxable.
	 */
	public static function add_fee( $name, $amount, $taxable = false ) {
		$fees   = WC()->session->get( 'mpo_fees', array() );
		$fees   = is_array( $fees ) ? $fees : array();
		$fees[] = array(
			'name'    => $name,
			'amount'  => (float) $amount,
			'taxable' => (bool) $taxable,
			'id'      => uniqid( 'fee_', true ),
		);
		WC()->session->set( 'mpo_fees', $fees );
	}

	/**
	 * Remove a stored fee by id.
	 *
	 * @param string $id Fee id.
	 */
	public static function remove_fee( $id ) {
		$fees = WC()->session->get( 'mpo_fees', array() );
		$fees = array_values(
			array_filter(
				is_array( $fees ) ? $fees : array(),
				static function ( $fee ) use ( $id ) {
					return empty( $fee['id'] ) || $fee['id'] !== $id;
				}
			)
		);
		WC()->session->set( 'mpo_fees', $fees );
	}

	/**
	 * Choose shipping method.
	 *
	 * @param string $method Rate id.
	 */
	public static function set_shipping_method( $method ) {
		self::invalidate_shipping_cache();
		WC()->session->set( 'chosen_shipping_methods', array( wc_clean( $method ) ) );
		WC()->session->set( 'shipping_method_counts', array() );
	}

	/**
	 * Drop cached package rates so a new custom amount is recalculated.
	 */
	public static function invalidate_shipping_cache() {
		if ( ! WC()->session ) {
			return;
		}
		for ( $i = 0; $i < 10; $i++ ) {
			WC()->session->set( 'shipping_for_package_' . $i, null );
		}
	}

	/**
	 * Set a manual shipping amount (adds/selects mpo_manual rate).
	 *
	 * @param float|null $amount Amount or null to clear.
	 */
	public static function set_custom_shipping( $amount ) {
		self::invalidate_shipping_cache();
		if ( null === $amount || '' === $amount ) {
			WC()->session->set( 'mpo_custom_shipping', null );
			return;
		}
		WC()->session->set( 'mpo_custom_shipping', (float) $amount );
		self::set_shipping_method( 'mpo_manual' );
	}

	/**
	 * Choose payment method.
	 *
	 * @param string $method Gateway id.
	 */
	public static function set_payment_method( $method ) {
		WC()->session->set( 'chosen_payment_method', wc_clean( $method ) );
		WC()->session->set( 'mpo_payment_method', wc_clean( $method ) );
	}

	/**
	 * Redeem loyalty points against the current cart.
	 *
	 * @param int $points Points to redeem.
	 */
	public static function redeem_rewards( $points ) {
		MPO_Loyalty::redeem_points( (int) $points );
	}

	/**
	 * Empty cart but keep customer on the draft.
	 */
	public static function empty_cart() {
		WC()->cart->empty_cart( true );
		WC()->session->set( 'mpo_fees', array() );
		WC()->session->set( 'mpo_custom_shipping', null );
		WC()->session->set( 'mpo_ywpar_points', null );
		WC()->session->set( 'mpo_ywpar_discount', null );
	}

	/**
	 * Copy customer, addresses, and note from an order onto the draft (before context).
	 *
	 * @param MPO_Draft $draft Draft.
	 * @param WC_Order  $order Order.
	 */
	public static function apply_order_customer_to_draft( MPO_Draft $draft, WC_Order $order ) {
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id && ! get_userdata( $customer_id ) ) {
			$customer_id = 0;
		}

		$draft->customer_id = $customer_id;
		$draft->notes       = (string) $order->get_customer_note();
		$draft->set_meta( 'billing', wc_clean( $order->get_address( 'billing' ) ) );
		$draft->set_meta( 'shipping', wc_clean( $order->get_address( 'shipping' ) ) );
		$draft->set_meta( 'payment_method', (string) $order->get_payment_method() );
	}

	/**
	 * Load items and checkout choices from an existing order into the current cart.
	 *
	 * @param int $order_id Order id.
	 */
	public static function load_from_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new Exception( __( 'Order not found.', 'manual-phone-orders' ) );
		}

		self::empty_cart();

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$qty          = $item->get_quantity();
			$variation    = array();

			if ( $variation_id ) {
				$product = $item->get_product();
				if ( $product && is_callable( array( $product, 'get_variation_attributes' ) ) ) {
					$variation = $product->get_variation_attributes();
				}
			}

			if ( ! $variation ) {
				foreach ( $item->get_meta_data() as $meta ) {
					if ( ! is_object( $meta ) ) {
						continue;
					}
					$key = (string) $meta->key;
					if ( 0 === strpos( $key, 'pa_' ) || 0 === strpos( $key, 'attribute_' ) ) {
						$variation[ $key ] = $meta->value;
					}
				}
			}

			try {
				$key = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation );
				if ( $key && (float) $item->get_subtotal() && $qty ) {
					$unit    = (float) $item->get_subtotal() / max( 1, (float) $qty );
					$product = $item->get_product();
					if ( $product && (float) $product->get_price() !== $unit ) {
						WC()->cart->cart_contents[ $key ]['mpo_custom_price'] = $unit;
					}
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		foreach ( $order->get_coupon_codes() as $code ) {
			WC()->cart->apply_coupon( $code );
		}

		foreach ( $order->get_fees() as $fee ) {
			if ( ! is_a( $fee, 'WC_Order_Item_Fee' ) ) {
				continue;
			}
			self::add_fee( $fee->get_name(), (float) $fee->get_total(), (float) $fee->get_total_tax() > 0 );
		}

		$shipping_total = (float) $order->get_shipping_total();
		if ( $shipping_total > 0 ) {
			self::set_custom_shipping( $shipping_total );
		} else {
			foreach ( $order->get_shipping_methods() as $shipping_item ) {
				$method_id = (string) $shipping_item->get_method_id();
				$instance  = (int) $shipping_item->get_instance_id();
				if ( $instance ) {
					$method_id .= ':' . $instance;
				}
				if ( $method_id ) {
					self::set_shipping_method( $method_id );
				}
				break;
			}
		}

		$payment = (string) $order->get_payment_method();
		if ( $payment ) {
			self::set_payment_method( $payment );
		}
	}

	/**
	 * JSON payload for the SPA.
	 *
	 * @param MPO_Draft $draft Draft.
	 * @return array
	 */
	public static function serialize( MPO_Draft $draft ) {
		$cart     = WC()->cart;
		$customer = WC()->customer;
		$items    = array();

		if ( $cart ) {
			foreach ( $cart->get_cart() as $key => $item ) {
				$product = isset( $item['data'] ) ? $item['data'] : null;
				if ( ! $product ) {
					continue;
				}
				$image_id = $product->get_image_id();
				$is_gift  = class_exists( 'MPO_Bogo' ) && MPO_Bogo::is_gift_item( $item );
				$unit     = $is_gift ? 0.0 : (float) $product->get_price();
				$regular  = (float) $product->get_regular_price();
				$items[] = array(
					'key'               => $key,
					'product_id'        => (int) $item['product_id'],
					'variation_id'      => (int) $item['variation_id'],
					'name'              => $product->get_name(),
					'sku'               => $product->get_sku(),
					'qty'               => (float) $item['quantity'],
					'qty_input'         => $product->get_sold_individually() ? 1 : null,
					'max_qty'           => $product->get_max_purchase_quantity(),
					'unit_price'        => $unit,
					'regular_price'     => $regular,
					'line_subtotal'     => (float) $item['line_subtotal'],
					'line_total'        => (float) $item['line_total'],
					'unit_html'         => wc_price( $unit ),
					'regular_html'      => ( $regular > 0 && ( $regular > $unit || $is_gift ) ) ? wc_price( $regular ) : null,
					'line_html'         => wc_price( $is_gift ? 0 : $item['line_subtotal'] ),
					'line_total_html'   => wc_price( $is_gift ? 0 : $item['line_total'] ),
					'image'             => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_gallery_thumbnail' ) : wc_placeholder_img_src(),
					'permalink'         => $product->get_permalink(),
					'custom_price'      => $is_gift ? 0.0 : ( isset( $item['mpo_custom_price'] ) ? (float) $item['mpo_custom_price'] : null ),
					'sold_individually' => (bool) $product->get_sold_individually(),
					'is_gift'           => $is_gift,
				);
			}
		}

		$chosen_shipping = WC()->session ? WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		$chosen_payment  = WC()->session ? WC()->session->get( 'chosen_payment_method', $draft->get_meta( 'payment_method', '' ) ) : '';

		return array(
			'uuid'            => $draft->uuid,
			'status'          => $draft->status,
			'notes'           => $draft->notes,
			'customer'        => self::serialize_customer( $draft, $customer ),
			'items'           => $items,
			'item_count'      => $cart ? $cart->get_cart_contents_count() : 0,
			'coupons'         => $cart ? array_values( $cart->get_applied_coupons() ) : array(),
			'fees'            => self::serialize_fees(),
			'shipping'        => self::serialize_shipping( $chosen_shipping ),
			'payment'         => self::serialize_payment( $chosen_payment ),
			'totals'          => self::serialize_totals( $cart ),
			'gifts'           => class_exists( 'MPO_Bogo' ) ? MPO_Bogo::serialize() : array(),
			'needs_shipping'  => $cart ? $cart->needs_shipping() : false,
			'notices'         => self::serialize_notices(),
			'held'            => self::serialize_held(),
		);
	}

	/**
	 * Customer payload.
	 *
	 * @param MPO_Draft        $draft    Draft.
	 * @param WC_Customer|null $customer Customer.
	 * @return array
	 */
	private static function serialize_customer( MPO_Draft $draft, $customer ) {
		$user = $draft->customer_id ? get_userdata( $draft->customer_id ) : null;

		$billing_keys  = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		$shipping_keys = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );

		$billing  = array();
		$shipping = array();

		foreach ( $billing_keys as $key ) {
			$billing[ $key ] = $customer && is_callable( array( $customer, 'get_billing_' . $key ) ) ? (string) $customer->{'get_billing_' . $key}() : '';
		}
		foreach ( $shipping_keys as $key ) {
			$shipping[ $key ] = $customer && is_callable( array( $customer, 'get_shipping_' . $key ) ) ? (string) $customer->{'get_shipping_' . $key}() : '';
		}

		$balances   = $draft->customer_id ? MPO_Loyalty::balances( $draft->customer_id ) : array();
		$funds      = null;
		$funds_html = null;
		foreach ( $balances as $row ) {
			if ( 'funds' === $row['id'] ) {
				$funds      = $row['value'];
				$funds_html = $row['html'];
				break;
			}
		}

		return array(
			'id'                => (int) $draft->customer_id,
			'is_guest'          => 0 === (int) $draft->customer_id,
			'name'              => $user ? $user->display_name : ( trim( $billing['first_name'] . ' ' . $billing['last_name'] ) ?: __( 'Guest', 'manual-phone-orders' ) ),
			'email'             => $user ? $user->user_email : $billing['email'],
			'username'          => $user ? $user->user_login : '',
			'roles'             => $user ? array_values( $user->roles ) : array(),
			'role_label'        => self::role_label( $user ),
			'billing'           => $billing,
			'shipping'          => $shipping,
			'ship_to_different' => self::shipping_differs( $billing, $shipping ),
			'funds'             => $funds,
			'funds_html'        => $funds_html,
			'balances'          => $balances,
		);
	}

	/**
	 * Whether shipping has any filled field that differs from billing.
	 *
	 * @param array $billing  Billing.
	 * @param array $shipping Shipping.
	 * @return bool
	 */
	private static function shipping_differs( $billing, $shipping ) {
		$keys = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
		foreach ( $keys as $key ) {
			$ship = isset( $shipping[ $key ] ) ? trim( (string) $shipping[ $key ] ) : '';
			if ( '' === $ship ) {
				continue;
			}
			$bill = isset( $billing[ $key ] ) ? trim( (string) $billing[ $key ] ) : '';
			if ( $ship !== $bill ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Human-readable role for the customer card.
	 *
	 * @param WP_User|null $user User.
	 * @return string
	 */
	private static function role_label( $user ) {
		if ( ! $user ) {
			return __( 'Guest', 'manual-phone-orders' );
		}

		$wp_roles = wp_roles();
		$labels   = array();
		foreach ( (array) $user->roles as $role ) {
			$name = isset( $wp_roles->role_names[ $role ] ) ? translate_user_role( $wp_roles->role_names[ $role ] ) : $role;
			if ( $name ) {
				$labels[] = $name;
			}
		}

		return $labels ? implode( ', ', $labels ) : __( 'Customer', 'manual-phone-orders' );
	}

	/**
	 * Fees payload.
	 *
	 * @return array
	 */
	private static function serialize_fees() {
		$stored = WC()->session ? WC()->session->get( 'mpo_fees', array() ) : array();
		$out    = array();
		$seen   = array();
		foreach ( (array) $stored as $fee ) {
			$id      = isset( $fee['id'] ) ? $fee['id'] : '';
			$seen[]  = $id;
			$out[]   = array(
				'id'       => $id,
				'name'     => isset( $fee['name'] ) ? $fee['name'] : '',
				'amount'   => isset( $fee['amount'] ) ? (float) $fee['amount'] : 0,
				'html'     => wc_price( isset( $fee['amount'] ) ? $fee['amount'] : 0 ),
				'readonly' => false,
			);
		}

		if ( WC()->cart ) {
			foreach ( WC()->cart->get_fees() as $fee ) {
				$fid = isset( $fee->id ) ? (string) $fee->id : '';
				if ( $fid && ( in_array( $fid, $seen, true ) || 0 === strpos( $fid, 'mpo_fee_' ) ) ) {
					continue;
				}
				$out[] = array(
					'id'       => $fid,
					'name'     => isset( $fee->name ) ? $fee->name : '',
					'amount'   => isset( $fee->amount ) ? (float) $fee->amount : 0,
					'html'     => wc_price( isset( $fee->amount ) ? $fee->amount : 0 ),
					'readonly' => true,
				);
			}
		}

		return $out;
	}

	/**
	 * Shipping methods + chosen.
	 *
	 * @param array $chosen Chosen methods.
	 * @return array
	 */
	private static function serialize_shipping( $chosen ) {
		$methods = array();
		$packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();

		foreach ( $packages as $i => $package ) {
			if ( empty( $package['rates'] ) ) {
				continue;
			}
			foreach ( $package['rates'] as $rate_id => $rate ) {
				$methods[] = array(
					'id'    => $rate_id,
					'label' => $rate->get_label(),
					'cost'  => (float) $rate->get_cost(),
					'html'  => wc_price( $rate->get_cost() ),
					'package' => (int) $i,
				);
			}
		}

		$custom = WC()->session ? WC()->session->get( 'mpo_custom_shipping', null ) : null;
		$custom = ( null !== $custom && '' !== $custom ) ? (float) $custom : null;

		if ( null !== $custom ) {
			$has_manual = false;
			foreach ( $methods as $method ) {
				if ( 'mpo_manual' === $method['id'] ) {
					$has_manual = true;
					break;
				}
			}
			if ( ! $has_manual ) {
				array_unshift(
					$methods,
					array(
						'id'      => 'mpo_manual',
						'label'   => __( 'Manual shipping', 'manual-phone-orders' ),
						'cost'    => $custom,
						'html'    => wc_price( $custom ),
						'package' => 0,
					)
				);
			}
			$chosen = array( 'mpo_manual' );
		}

		return array(
			'methods'  => $methods,
			'chosen'   => isset( $chosen[0] ) ? $chosen[0] : '',
			'custom'   => $custom,
		);
	}

	/**
	 * Payment gateways available for this customer/cart.
	 *
	 * @param string $chosen Chosen id.
	 * @return array
	 */
	private static function serialize_payment( $chosen ) {
		$gateways  = array();
		$available = WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : array();

		foreach ( $available as $id => $gateway ) {
			$gateway->chosen = ( $id === $chosen );
			$has_fields      = is_callable( array( $gateway, 'has_fields' ) ) ? (bool) $gateway->has_fields() : false;
			$fields_html     = '';

			if ( is_callable( array( $gateway, 'payment_fields' ) ) ) {
				ob_start();
				try {
					$gateway->payment_fields();
				} catch ( Exception $e ) {
					echo '<p>' . esc_html( $e->getMessage() ) . '</p>';
				}
				$fields_html = trim( ob_get_clean() );
			}

			$is_redirect = (bool) preg_match( '/paypal|ppcp|ppec/i', (string) $id );

			if ( $is_redirect ) {
				$label        = sprintf(
					/* translators: %s gateway title */
					__( 'Pay with %s', 'manual-phone-orders' ),
					$gateway->get_title()
				);
				$fields_html .= '<div class="mpo-paypal-wrap">';
				$fields_html .= '<div id="paypal-standard-container"></div>';
				$fields_html .= '<button type="button" class="mpo-paypal-btn" data-action="place">' . esc_html( $label ) . '</button>';
				$fields_html .= '<p class="mpo-paypal-hint">' . esc_html__( 'Place the order to continue to PayPal, the same as checkout.', 'manual-phone-orders' ) . '</p>';
				$fields_html .= '</div>';
			}

			$gateways[] = array(
				'id'          => $id,
				'title'       => $gateway->get_title(),
				'description' => wp_kses_post( (string) $gateway->get_description() ),
				'has_fields'  => $has_fields || '' !== $fields_html,
				'fields_html' => $fields_html,
				'redirect'    => $is_redirect,
			);
		}

		return array(
			'methods' => $gateways,
			'chosen'  => $chosen,
		);
	}

	/**
	 * Totals.
	 *
	 * @param WC_Cart|null $cart Cart.
	 * @return array
	 */
	private static function serialize_totals( $cart ) {
		if ( ! $cart ) {
			return array(
				'subtotal'       => 0,
				'discount'       => 0,
				'shipping'       => 0,
				'fees'           => 0,
				'tax'            => 0,
				'total'          => 0,
				'subtotal_html'  => wc_price( 0 ),
				'discount_html'  => wc_price( 0 ),
				'shipping_html'  => wc_price( 0 ),
				'fees_html'      => wc_price( 0 ),
				'tax_html'       => wc_price( 0 ),
				'total_html'     => wc_price( 0 ),
				'points_to_earn'    => null,
				'points_redeemed'   => null,
				'needs_payment'     => false,
			);
		}

		$subtotal = (float) $cart->get_subtotal();
		$discount = (float) $cart->get_discount_total();
		$shipping = (float) $cart->get_shipping_total();
		$fees     = (float) $cart->get_fee_total();
		$tax      = (float) $cart->get_total_tax();
		$total    = (float) $cart->get_total( 'edit' );
		$points   = MPO_Loyalty::cart_points_to_earn();
		$redeemed = WC()->session ? WC()->session->get( 'mpo_ywpar_points' ) : null;

		return array(
			'subtotal'          => $subtotal,
			'discount'          => $discount,
			'shipping'          => $shipping,
			'fees'              => $fees,
			'tax'               => $tax,
			'total'             => $total,
			'subtotal_html'     => wc_price( $subtotal ),
			'discount_html'     => wc_price( $discount ),
			'shipping_html'     => wc_price( $shipping ),
			'fees_html'         => wc_price( $fees ),
			'tax_html'          => wc_price( $tax ),
			'total_html'        => wc_price( $total ),
			'points_to_earn'    => $points,
			'points_redeemed'   => null !== $redeemed ? (int) $redeemed : null,
			'needs_payment'     => (bool) $cart->needs_payment(),
		);
	}

	/**
	 * WC notices as simple messages.
	 *
	 * @return array
	 */
	private static function serialize_notices() {
		$all = wc_get_notices();
		wc_clear_notices();
		$out = array();
		foreach ( $all as $type => $notices ) {
			foreach ( (array) $notices as $notice ) {
				$text = is_array( $notice ) && isset( $notice['notice'] ) ? $notice['notice'] : (string) $notice;
				if ( class_exists( 'MPO_Bogo' ) && MPO_Bogo::is_gift_notice( $text ) ) {
					continue;
				}
				$out[] = array(
					'type'    => $type,
					'message' => wp_strip_all_tags( $text ),
				);
			}
		}
		return $out;
	}

	/**
	 * Held drafts for the operator (lightweight).
	 *
	 * @return array
	 */
	private static function serialize_held() {
		$operator = get_current_user_id();
		// During context, current user is the customer. Use the original via a stored flag.
		// Held list is attached later in REST where we know the operator.
		return array();
	}
}
