<?php
/**
 * Make third-party WooCommerce plugins see the selected customer.
 *
 * YITH Dynamic Pricing, Account Funds, role-based pricing, memberships, etc.
 * all typically key off get_current_user_id() and WC()->customer. The context
 * switch already handles that; this class covers plugins that also check
 * is_admin() or store their own user pointer.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility shims.
 */
class MPO_Compatibility {

	/**
	 * Hook shims.
	 */
	public static function init() {
		add_action( 'mpo_context_entered', array( __CLASS__, 'on_enter' ), 10, 3 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'reapply_manual_fees' ), 5 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'maybe_add_custom_shipping' ), 100, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'tag_shipping_packages' ), 20 );
		add_filter( 'woocommerce_cart_ready_to_calc_shipping', array( __CLASS__, 'ready_to_calc_shipping' ), 999 );
		add_filter( 'woocommerce_cart_needs_shipping', array( __CLASS__, 'needs_shipping' ), 999 );
		add_filter(
			'wc_get_price_decimals',
			static function ( $decimals ) {
				return is_callable( array( 'MPO_Compatibility', 'price_decimals' ) )
					? self::price_decimals( $decimals )
					: $decimals;
			},
			999
		);
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_custom_prices' ), 20, 1 );
		add_action( 'woocommerce_before_calculate_totals', array( 'MPO_Bogo', 'zero_gift_prices' ), 999, 1 );
		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_custom_price' ), 9999, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_custom_price' ), 9999, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_item' ), 10, 4 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'cart_item_from_session' ), 20, 2 );
		add_filter( 'woocommerce_checkout_customer_id', array( __CLASS__, 'filter_customer_id' ), 9999 );
		add_filter( 'account_funds_get_user_id', array( __CLASS__, 'filter_customer_id' ), 9999 );
		add_filter( 'yith_ywf_current_customer_id', array( __CLASS__, 'filter_customer_id' ), 9999 );
		add_filter( 'yith_account_funds_customer_id', array( __CLASS__, 'filter_customer_id' ), 9999 );
		add_filter( 'ywdpd_get_current_user_id', array( __CLASS__, 'filter_customer_id' ), 9999 );
		add_filter( 'ywpar_customer_id', array( __CLASS__, 'filter_customer_id' ), 9999 );
		add_filter( 'yith_ywpar_customer_id', array( __CLASS__, 'filter_customer_id' ), 9999 );
		add_filter( 'ywpar_get_customer_id', array( __CLASS__, 'filter_customer_id' ), 9999 );
		add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'virtual_points_coupon' ), 10, 2 );
		add_filter( 'woocommerce_coupons_enabled', array( __CLASS__, 'enable_coupons' ), 999 );
		add_filter( 'woocommerce_is_rest_api_request', array( __CLASS__, 'not_rest_during_context' ), 1 );
		add_filter( 'wc_bogof_is_frontend', array( __CLASS__, 'true_during_pricing' ), 1 );
		add_filter( 'woocommerce_bogof_is_frontend', array( __CLASS__, 'true_during_pricing' ), 1 );
		add_filter( 'advanced_woo_discount_rules_calculate_discount_for_rest_api', array( __CLASS__, 'true_during_pricing' ), 1 );
		add_filter( 'wdp_is_enabled_on_rest_api', array( __CLASS__, 'true_during_pricing' ), 1 );
		add_filter( 'wdp_calculate_on_rest_api', array( __CLASS__, 'true_during_pricing' ), 1 );
		add_filter( 'ywdpd_disable_dynamic_pricing', array( __CLASS__, 'false_during_pricing' ), 999 );
		add_filter( 'ywdpd_skip_cart_process', array( __CLASS__, 'false_during_pricing' ), 999 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'run_dynamic_pricing' ), 15, 1 );
	}

	/**
	 * Customer currently being shopped-as.
	 *
	 * @var int
	 */
	protected static $customer_id = 0;

	/**
	 * Remember customer id for plugin-specific lookups.
	 *
	 * @param int       $customer_id Customer.
	 * @param int       $operator_id Operator.
	 * @param MPO_Draft $draft       Draft.
	 */
	public static function on_enter( $customer_id, $operator_id, $draft ) {
		self::$customer_id = (int) $customer_id;
		do_action( 'mpo_compatibility_enter', self::$customer_id, $operator_id, $draft );
	}

	/**
	 * Replace plugin-specific "current user" lookups during context.
	 *
	 * @param mixed $user_id Incoming.
	 * @return int|mixed
	 */
	public static function filter_customer_id( $user_id ) {
		if ( mpo_doing_context() && self::$customer_id > 0 ) {
			return self::$customer_id;
		}
		return $user_id;
	}

	/**
	 * Virtual coupon so redeemed points can discount the isolated cart.
	 *
	 * @param mixed  $data Coupon data.
	 * @param string $code Code.
	 * @return mixed
	 */
	public static function virtual_points_coupon( $data, $code ) {
		if ( ! mpo_doing_context() || ! WC()->session ) {
			return $data;
		}
		if ( 0 !== strpos( (string) $code, 'mpo_pts_' ) ) {
			return $data;
		}
		$amount = (float) WC()->session->get( 'mpo_ywpar_discount', 0 );
		if ( $amount <= 0 ) {
			return $data;
		}
		return array(
			'id'             => 0,
			'discount_type'  => 'fixed_cart',
			'amount'         => $amount,
			'individual_use' => false,
			'usage_limit'    => '',
			'free_shipping'  => false,
		);
	}

	/**
	 * Coupons must be on so YITH / points discounts can apply.
	 *
	 * @param bool $enabled Enabled.
	 * @return bool
	 */
	public static function enable_coupons( $enabled ) {
		return mpo_doing_context() ? true : $enabled;
	}

	/**
	 * Let cart-pricing plugins treat the isolated session as storefront, not REST.
	 *
	 * @param bool $is REST.
	 * @return bool
	 */
	public static function not_rest_during_context( $is ) {
		return mpo_doing_context() ? false : $is;
	}

	/**
	 * Enable third-party pricing on MPO requests.
	 *
	 * @param mixed $value Incoming.
	 * @return mixed
	 */
	public static function true_during_pricing( $value ) {
		if ( mpo_doing_context() || self::is_mpo_request() ) {
			return true;
		}
		return $value;
	}

	/**
	 * Do not skip YITH / similar pricing on MPO requests.
	 *
	 * @param mixed $value Incoming.
	 * @return mixed
	 */
	public static function false_during_pricing( $value ) {
		if ( mpo_doing_context() || self::is_mpo_request() ) {
			return false;
		}
		return $value;
	}

	/**
	 * Whether this is a Phone Orders REST call.
	 *
	 * @return bool
	 */
	public static function is_mpo_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return false !== strpos( $uri, '/mpo/v1/' ) || false !== strpos( $uri, '/wp-json/mpo/' );
	}

	/**
	 * Run popular dynamic-pricing engines if they skipped REST/admin bootstrap.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function run_dynamic_pricing( $cart ) {
		if ( ! mpo_doing_context() || ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}

		try {
			if ( class_exists( 'WC_Dynamic_Pricing' ) && is_callable( array( 'WC_Dynamic_Pricing', 'instance' ) ) ) {
				$dp = WC_Dynamic_Pricing::instance();
				foreach ( array( 'on_calculate_totals', 'apply_discounting', 'on_cart_loaded_from_session' ) as $method ) {
					if ( is_object( $dp ) && is_callable( array( $dp, $method ) ) ) {
						$dp->{$method}( $cart );
						break;
					}
				}
			}

			if ( function_exists( 'adp_functions' ) ) {
				$fn = adp_functions();
				foreach ( array( 'processCart', 'applyToCart', 'calculateCart' ) as $method ) {
					if ( is_object( $fn ) && is_callable( array( $fn, $method ) ) ) {
						$fn->{$method}();
						break;
					}
				}
			}

			if ( class_exists( 'WDP_Frontend' ) ) {
				if ( is_callable( array( 'WDP_Frontend', 'get_instance' ) ) ) {
					$front = WDP_Frontend::get_instance();
				} elseif ( function_exists( 'WDP_Frontend' ) ) {
					$front = WDP_Frontend();
				} else {
					$front = null;
				}
				if ( is_object( $front ) ) {
					foreach ( array( 'woocommerce_before_calculate_totals', 'process_cart', 'apply_cart_rules' ) as $method ) {
						if ( is_callable( array( $front, $method ) ) ) {
							$front->{$method}( $cart );
							break;
						}
					}
				}
			}

			if ( function_exists( 'YITH_WC_Dynamic_Pricing' ) ) {
				$yith = YITH_WC_Dynamic_Pricing();
				if ( is_object( $yith ) ) {
					foreach ( array( 'apply_cart_rules', 'cart_process', 'apply_discounts' ) as $method ) {
						if ( is_callable( array( $yith, $method ) ) ) {
							$yith->{$method}();
							break;
						}
					}
				}
			}

			if ( function_exists( 'YITH_WC_Dynamic_Pricing_Frontend' ) ) {
				$front = YITH_WC_Dynamic_Pricing_Frontend();
				if ( is_object( $front ) ) {
					foreach ( array( 'apply_cart_discounts', 'cart_process', 'apply_discounts', 'woocommerce_before_calculate_totals' ) as $method ) {
						if ( is_callable( array( $front, $method ) ) ) {
							$front->{$method}( $cart );
							break;
						}
					}
				}
			}

			do_action( 'mpo_run_dynamic_pricing', $cart );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Re-apply operator-added fees on every totals calculation.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function reapply_manual_fees( $cart ) {
		if ( ! mpo_doing_context() || ! WC()->session ) {
			return;
		}

		$fees = WC()->session->get( 'mpo_fees', array() );
		if ( empty( $fees ) || ! is_array( $fees ) ) {
			return;
		}

		foreach ( $fees as $fee ) {
			$name     = isset( $fee['name'] ) ? wc_clean( $fee['name'] ) : __( 'Fee', 'manual-phone-orders' );
			$amount   = isset( $fee['amount'] ) ? (float) $fee['amount'] : 0;
			$taxable  = ! empty( $fee['taxable'] );
			$tax_class = isset( $fee['tax_class'] ) ? $fee['tax_class'] : '';
			if ( $name && 0 != $amount ) {
				$cart->fees_api()->add_fee(
					array(
						'id'        => 'mpo_fee_' . sanitize_title( $name ),
						'name'      => $name,
						'amount'    => $amount,
						'taxable'   => $taxable,
						'tax_class' => $tax_class,
					)
				);
			}
		}
	}

	/**
	 * Keep cents in the phone-order UI even when the storefront uses 0 decimals.
	 *
	 * @param int $decimals Decimals.
	 * @return int
	 */
	public static function price_decimals( $decimals ) {
		if ( function_exists( 'mpo_doing_context' ) && mpo_doing_context() ) {
			return max( 2, (int) $decimals );
		}
		return (int) $decimals;
	}

	/**
	 * Always calculate shipping on the phone-order screen (no address required).
	 *
	 * @param bool $ready Ready.
	 * @return bool
	 */
	public static function ready_to_calc_shipping( $ready ) {
		return mpo_doing_context() ? true : $ready;
	}

	/**
	 * Apply custom shipping even when the cart has no shippable items.
	 *
	 * @param bool $needs Needs shipping.
	 * @return bool
	 */
	public static function needs_shipping( $needs ) {
		if ( mpo_doing_context() && self::custom_shipping_amount() !== null ) {
			return true;
		}
		return $needs;
	}

	/**
	 * Put the custom amount on the package so WooCommerce invalidates its rate cache.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public static function tag_shipping_packages( $packages ) {
		if ( ! mpo_doing_context() ) {
			return $packages;
		}

		$amount = self::custom_shipping_amount();
		foreach ( $packages as $i => $package ) {
			$packages[ $i ]['mpo_custom_shipping'] = $amount;
		}
		return $packages;
	}

	/**
	 * Stored custom shipping amount, or null if unset.
	 *
	 * @return float|null
	 */
	public static function custom_shipping_amount() {
		if ( ! WC()->session ) {
			return null;
		}
		$amount = WC()->session->get( 'mpo_custom_shipping', null );
		if ( null === $amount || '' === $amount ) {
			return null;
		}
		return (float) $amount;
	}

	/**
	 * Inject a custom shipping rate when the operator set a manual amount.
	 *
	 * @param array $rates   Rates.
	 * @param array $package Package.
	 * @return array
	 */
	public static function maybe_add_custom_shipping( $rates, $package ) {
		if ( ! mpo_doing_context() ) {
			return $rates;
		}

		$amount = self::custom_shipping_amount();
		if ( null === $amount ) {
			return $rates;
		}

		$rate = new WC_Shipping_Rate(
			'mpo_manual',
			__( 'Manual shipping', 'manual-phone-orders' ),
			(string) $amount,
			array(),
			'mpo_manual'
		);

		$rates['mpo_manual'] = $rate;
		return $rates;
	}

	/**
	 * Apply per-line custom prices before totals.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function apply_custom_prices( $cart ) {
		if ( is_admin() && ! mpo_doing_context() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['mpo_custom_price'] ) && '' !== $item['mpo_custom_price'] && isset( $item['data'] ) && is_a( $item['data'], 'WC_Product' ) ) {
				$item['data']->set_price( (float) $item['mpo_custom_price'] );
			}
			if ( ! empty( $item['mpo_custom_name'] ) && isset( $item['data'] ) && is_a( $item['data'], 'WC_Product' ) ) {
				$item['data']->set_name( $item['mpo_custom_name'] );
			}
		}
	}

	/**
	 * Keep custom price on get_price for plugins that read the product directly.
	 *
	 * @param mixed      $price   Price.
	 * @param WC_Product $product Product.
	 * @return mixed
	 */
	public static function filter_custom_price( $price, $product ) {
		if ( ! mpo_doing_context() || ! WC()->cart ) {
			return $price;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['data'] ) && $item['data'] === $product ) {
				if ( class_exists( 'MPO_Bogo' ) && MPO_Bogo::is_gift_item( $item ) ) {
					return 0;
				}
				if ( isset( $item['mpo_custom_price'] ) && '' !== $item['mpo_custom_price'] ) {
					return (float) $item['mpo_custom_price'];
				}
			}
		}

		return $price;
	}

	/**
	 * Show custom price flag in item data.
	 *
	 * @param array $data Item data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		if ( ! empty( $item['mpo_custom_price'] ) ) {
			$data[] = array(
				'key'   => __( 'Custom price', 'manual-phone-orders' ),
				'value' => wc_price( $item['mpo_custom_price'] ),
			);
		}
		return $data;
	}

	/**
	 * Pass through custom cart item flags.
	 *
	 * @param array $cart_item_data Data.
	 * @param int   $product_id     Product.
	 * @param int   $variation_id   Variation.
	 * @return array
	 */
	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		return $cart_item_data;
	}

	/**
	 * Restore custom flags from session.
	 *
	 * @param array $item    Item.
	 * @param array $values  Session values.
	 * @return array
	 */
	public static function cart_item_from_session( $item, $values ) {
		foreach ( array( 'mpo_custom_price', 'mpo_custom_name', 'mpo_custom_product', 'mpo_gift', 'wc_bogof_gift', '_bogof' ) as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$item[ $key ] = $values[ $key ];
			}
		}
		return $item;
	}

	/**
	 * Persist custom name onto the order line.
	 *
	 * @param WC_Order_Item_Product $item          Item.
	 * @param string                $cart_item_key Key.
	 * @param array                 $values        Values.
	 * @param WC_Order              $order         Order.
	 */
	public static function order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['mpo_custom_name'] ) ) {
			$item->set_name( $values['mpo_custom_name'] );
		}
		if ( isset( $values['mpo_custom_price'] ) && '' !== $values['mpo_custom_price'] ) {
			$item->add_meta_data( '_mpo_custom_price', $values['mpo_custom_price'], true );
		}
	}
}
