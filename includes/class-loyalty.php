<?php
/**
 * Points, rewards, funds, and other WooCommerce loyalty balances.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read/redeem third-party balances while in customer context.
 */
class MPO_Loyalty {

	/**
	 * Balances to show on the customer card.
	 *
	 * @param int $user_id Customer.
	 * @return array
	 */
	public static function balances( $user_id ) {
		$user_id  = (int) $user_id;
		$balances = array();

		if ( $user_id < 1 ) {
			return $balances;
		}

		$points = self::get_points( $user_id );
		if ( null !== $points ) {
			$worth      = $points > 0 ? self::points_to_money( $points ) : 0;
			$worth_html = $worth > 0 ? wc_price( $worth ) : null;
			$balances[] = array(
				'id'         => 'points',
				'label'      => self::points_label(),
				'value'      => $points,
				'html'       => number_format_i18n( $points ),
				'worth'      => $worth > 0 ? $worth : null,
				'worth_html' => $worth_html,
				'redeemable' => $points > 0,
			);
		}

		$funds = self::get_funds( $user_id );
		if ( null !== $funds ) {
			$balances[] = array(
				'id'         => 'funds',
				'label'      => __( 'Account funds', 'manual-phone-orders' ),
				'value'      => $funds,
				'html'       => wc_price( $funds ),
				'redeemable' => false,
			);
		}

		$wallet = self::get_wallet( $user_id );
		if ( null !== $wallet ) {
			$balances[] = array(
				'id'         => 'wallet',
				'label'      => __( 'Wallet', 'manual-phone-orders' ),
				'value'      => $wallet,
				'html'       => wc_price( $wallet ),
				'redeemable' => false,
			);
		}

		return $balances;
	}

	/**
	 * Points the current cart would earn.
	 *
	 * @return int|null
	 */
	public static function cart_points_to_earn() {
		try {
			if ( function_exists( 'ywpar_get_cart_points' ) ) {
				return (int) ywpar_get_cart_points();
			}

			$earning = self::yith_earning();
			if ( $earning && is_callable( array( $earning, 'get_point_earned_from_cart' ) ) ) {
				return (int) $earning->get_point_earned_from_cart();
			}
			if ( $earning && is_callable( array( $earning, 'get_conversion_earn_points' ) ) && WC()->cart ) {
				return (int) $earning->get_conversion_earn_points( WC()->cart->get_subtotal() );
			}

			$earned = apply_filters( 'mpo_cart_points_to_earn', null );
			return null === $earned ? null : (int) $earned;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Points a catalog product would earn.
	 *
	 * @param WC_Product $product Product.
	 * @return int|null
	 */
	public static function product_points( $product ) {
		if ( ! $product ) {
			return null;
		}

		try {
			if ( function_exists( 'ywpar_get_product_points' ) ) {
				return (int) ywpar_get_product_points( $product );
			}

			$earning = self::yith_earning();
			if ( $earning && is_callable( array( $earning, 'get_point_earned' ) ) ) {
				return (int) $earning->get_point_earned( $product );
			}

			$meta = $product->get_meta( '_ywpar_point_earned', true );
			if ( '' !== $meta && is_numeric( $meta ) ) {
				return (int) $meta;
			}
		} catch ( Throwable $e ) {
			return null;
		}

		return null;
	}

	/**
	 * YITH earning helper, if present.
	 *
	 * @return object|null
	 */
	private static function yith_earning() {
		if ( function_exists( 'YITH_WC_Points_Rewards_Earning' ) ) {
			$earning = YITH_WC_Points_Rewards_Earning();
			return is_object( $earning ) ? $earning : null;
		}
		if ( class_exists( 'YITH_WC_Points_Rewards_Earning' ) && is_callable( array( 'YITH_WC_Points_Rewards_Earning', 'get_instance' ) ) ) {
			$earning = YITH_WC_Points_Rewards_Earning::get_instance();
			return is_object( $earning ) ? $earning : null;
		}
		return null;
	}

	/**
	 * Redeem points against the current cart.
	 *
	 * @param int $points Points.
	 */
	public static function redeem_points( $points ) {
		$points = absint( $points );
		if ( $points < 1 ) {
			throw new Exception( __( 'Enter how many points to redeem.', 'manual-phone-orders' ) );
		}

		$available = self::get_points( get_current_user_id() );
		if ( null !== $available && $available < 1 ) {
			throw new Exception( __( 'This customer has no reward points to redeem.', 'manual-phone-orders' ) );
		}
		if ( null !== $available && $points > $available ) {
			$points = (int) $available;
		}

		$applied = self::redeem_via_yith( $points );

		if ( ! $applied ) {
			$money = self::points_to_money( $points );
			if ( $money <= 0 ) {
				throw new Exception( __( 'Could not convert those points into a discount. Check the rewards conversion rate.', 'manual-phone-orders' ) );
			}
			if ( WC()->cart ) {
				$cap = (float) WC()->cart->get_subtotal();
				if ( $cap > 0 && $money > $cap ) {
					$money = $cap;
				}
			}
			self::apply_points_discount( $points, $money );
			$applied = true;
		}

		if ( ! $applied ) {
			throw new Exception( __( 'Points could not be redeemed.', 'manual-phone-orders' ) );
		}
	}

	/**
	 * Ask YITH to apply its own coupon, using the fields it expects from checkout.
	 *
	 * @param int $points Points.
	 * @return bool
	 */
	private static function redeem_via_yith( $points ) {
		$redemption = self::yith_redemption();
		if ( ! $redemption ) {
			return false;
		}

		$max_discount = 0;
		$max_points   = $points;
		if ( is_callable( array( $redemption, 'calculate_rewards_discount' ) ) ) {
			$max_discount = (float) $redemption->calculate_rewards_discount();
		}
		if ( is_callable( array( $redemption, 'get_max_points' ) ) ) {
			$max_points = (int) $redemption->get_max_points();
		}
		if ( $max_points > 0 && $points > $max_points ) {
			$points = $max_points;
		}
		if ( $max_discount <= 0 ) {
			$max_discount = self::points_to_money( $points );
		}

		$posted = array(
			'ywpar_rate_method'        => 'fixed',
			'ywpar_points_max'         => max( $points, $max_points ),
			'ywpar_max_discount'       => $max_discount > 0 ? $max_discount : 999999,
			'ywpar_input_points'       => $points,
			'ywpar_input_points_check' => 1,
			'ywpar_apply_discounts'    => 'yes',
		);

		foreach ( $posted as $key => $value ) {
			$_POST[ $key ]    = $value;
			$_REQUEST[ $key ] = $value;
		}

		if ( WC()->session ) {
			WC()->session->set( 'ywpar_input_points', $points );
			WC()->session->set( 'ywpar_coupon_code_points', $points );
			WC()->session->set( 'ywpar_coupon_code_discount', $max_discount );
			WC()->session->set( 'ywpar_coupon_posted', $posted );
		}

		try {
			if ( is_callable( array( $redemption, 'apply_discount_calculation' ) ) ) {
				$redemption->apply_discount_calculation( $posted, true );
			} elseif ( is_callable( array( $redemption, 'apply_discount' ) ) ) {
				$redemption->apply_discount();
			}
		} catch ( Throwable $e ) {
			return false;
		}

		if ( WC()->cart && WC()->cart->get_applied_coupons() ) {
			if ( WC()->session ) {
				WC()->session->set( 'mpo_ywpar_points', $points );
				WC()->session->set( 'mpo_ywpar_discount', $max_discount );
			}
			return true;
		}

		return false;
	}

	/**
	 * Apply a virtual coupon / fee for redeemed points.
	 *
	 * @param int   $points Points.
	 * @param float $money  Discount.
	 */
	private static function apply_points_discount( $points, $money ) {
		if ( WC()->session ) {
			WC()->session->set( 'mpo_ywpar_points', $points );
			WC()->session->set( 'mpo_ywpar_discount', $money );
			WC()->session->set( 'ywpar_coupon_code_points', $points );
			WC()->session->set( 'ywpar_coupon_code_discount', $money );
		}

		$code = self::points_coupon_code();
		if ( WC()->cart && ! WC()->cart->has_discount( $code ) ) {
			$ok = WC()->cart->apply_coupon( $code );
			if ( $ok || WC()->cart->has_discount( $code ) ) {
				return;
			}
		}

		$fees = WC()->session ? (array) WC()->session->get( 'mpo_fees', array() ) : array();
		$fees = array_values(
			array_filter(
				$fees,
				static function ( $fee ) {
					return empty( $fee['id'] ) || 'mpo_ywpar' !== $fee['id'];
				}
			)
		);
		$fees[] = array(
			'id'      => 'mpo_ywpar',
			'name'    => sprintf(
				/* translators: %s points */
				__( 'Reward points (%s)', 'manual-phone-orders' ),
				number_format_i18n( $points )
			),
			'amount'  => -1 * (float) $money,
			'taxable' => false,
		);
		if ( WC()->session ) {
			WC()->session->set( 'mpo_fees', $fees );
		}
	}

	/**
	 * Virtual coupon code for phone-order point redemptions.
	 *
	 * @return string
	 */
	public static function points_coupon_code() {
		return 'mpo_pts_' . get_current_user_id();
	}

	/**
	 * Convert points to store currency using YITH / WC Points rates.
	 *
	 * @param int $points Points.
	 * @return float
	 */
	public static function points_to_money( $points ) {
		$redemption = self::yith_redemption();
		if ( $redemption && is_callable( array( $redemption, 'get_conversion_rate_rewards' ) ) ) {
			$c = $redemption->get_conversion_rate_rewards();
			if ( is_array( $c ) && ! empty( $c['points'] ) ) {
				return ( $points / (float) $c['points'] ) * (float) $c['money'];
			}
		}

		if ( function_exists( 'ywpar_get_option' ) ) {
			$rate = ywpar_get_option( 'rewards_conversion_rate' );
			if ( ! $rate ) {
				$rate = ywpar_get_option( 'conversion_rate' );
			}
			if ( is_array( $rate ) ) {
				$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
				if ( $currency && isset( $rate[ $currency ] ) && is_array( $rate[ $currency ] ) ) {
					$rate = $rate[ $currency ];
				}
				$p = isset( $rate['points'] ) ? $rate['points'] : ( isset( $rate['point'] ) ? $rate['point'] : 0 );
				$m = isset( $rate['money'] ) ? $rate['money'] : 0;
				if ( $p > 0 ) {
					return ( $points / (float) $p ) * (float) $m;
				}
			}
		}

		$ratio = get_option( 'wc_points_rewards_redeem_points_ratio', '' );
		if ( $ratio && false !== strpos( (string) $ratio, ':' ) ) {
			$parts = array_map( 'floatval', explode( ':', $ratio, 2 ) );
			if ( ! empty( $parts[0] ) ) {
				return ( $points / $parts[0] ) * ( isset( $parts[1] ) ? $parts[1] : 1 );
			}
		}

		return 0;
	}

	/**
	 * YITH redemption helper.
	 *
	 * @return object|null
	 */
	private static function yith_redemption() {
		if ( function_exists( 'YITH_WC_Points_Rewards_Redemption' ) ) {
			$obj = YITH_WC_Points_Rewards_Redemption();
			return is_object( $obj ) ? $obj : null;
		}
		if ( class_exists( 'YITH_WC_Points_Rewards_Redemption' ) && is_callable( array( 'YITH_WC_Points_Rewards_Redemption', 'get_instance' ) ) ) {
			$obj = YITH_WC_Points_Rewards_Redemption::get_instance();
			return is_object( $obj ) ? $obj : null;
		}
		if ( function_exists( 'YITH_WC_Points_Rewards_Redeeming' ) ) {
			$obj = YITH_WC_Points_Rewards_Redeeming();
			return is_object( $obj ) ? $obj : null;
		}
		return null;
	}

	/**
	 * Points balance.
	 *
	 * @param int $user_id User.
	 * @return int|null
	 */
	public static function get_points( $user_id ) {
		try {
			if ( function_exists( 'ywpar_get_customer' ) ) {
				$customer = ywpar_get_customer( $user_id );
				if ( is_object( $customer ) ) {
					if ( is_callable( array( $customer, 'get_total_points' ) ) ) {
						return (int) $customer->get_total_points();
					}
					if ( is_callable( array( $customer, 'get_points' ) ) ) {
						return (int) $customer->get_points();
					}
				}
			}

			if ( function_exists( 'YITH_WC_Points_Rewards' ) ) {
				$plugin = YITH_WC_Points_Rewards();
				if ( is_object( $plugin ) && is_callable( array( $plugin, 'get_points_registered_manually' ) ) ) {
					$val = $plugin->get_points_registered_manually( $user_id );
					if ( is_numeric( $val ) ) {
						return (int) $val;
					}
				}
			}

			if ( class_exists( 'WC_Points_Rewards_Manager' ) && is_callable( array( 'WC_Points_Rewards_Manager', 'get_users_points' ) ) ) {
				return (int) WC_Points_Rewards_Manager::get_users_points( $user_id );
			}

			$meta_keys = array(
				'_ywpar_user_total_points',
				'ywpar_user_total_points',
				'_ywpar_points',
				'ywpar_points',
				'_ywdsd_rewards_points',
			);
			foreach ( $meta_keys as $key ) {
				$val = get_user_meta( $user_id, $key, true );
				if ( '' !== $val && false !== $val && is_numeric( $val ) ) {
					return (int) $val;
				}
			}

			$filtered = apply_filters( 'mpo_customer_points', null, $user_id );
			return null === $filtered ? null : (int) $filtered;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Account funds / store credit.
	 *
	 * @param int $user_id User.
	 * @return float|null
	 */
	public static function get_funds( $user_id ) {
		if ( class_exists( 'WC_Account_Funds' ) && method_exists( 'WC_Account_Funds', 'get_account_funds' ) ) {
			return (float) WC_Account_Funds::get_account_funds( $user_id );
		}

		if ( function_exists( 'YITH_YWF_Customer' ) ) {
			$customer = YITH_YWF_Customer( $user_id );
			if ( is_object( $customer ) && is_callable( array( $customer, 'get_funds' ) ) ) {
				return (float) $customer->get_funds();
			}
		}

		$meta_keys = array( '_account_funds', 'account_funds', '_ywf_funds', 'ywf_funds', 'yith_funds' );
		foreach ( $meta_keys as $key ) {
			$val = get_user_meta( $user_id, $key, true );
			if ( '' !== $val && false !== $val && is_numeric( $val ) ) {
				return (float) $val;
			}
		}

		return null;
	}

	/**
	 * Wallet plugins (TeraWallet / WooWallet).
	 *
	 * @param int $user_id User.
	 * @return float|null
	 */
	public static function get_wallet( $user_id ) {
		if ( function_exists( 'woo_wallet' ) && is_object( woo_wallet()->wallet ) && is_callable( array( woo_wallet()->wallet, 'get_wallet_balance' ) ) ) {
			return (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
		}

		$val = get_user_meta( $user_id, '_current_woo_wallet_balance', true );
		if ( '' !== $val && is_numeric( $val ) ) {
			return (float) $val;
		}

		return null;
	}

	/**
	 * Points label from YITH when available.
	 *
	 * @return string
	 */
	private static function points_label() {
		if ( function_exists( 'ywpar_get_option' ) ) {
			$label = ywpar_get_option( 'points_label_plural' );
			if ( $label ) {
				return $label;
			}
		}
		return __( 'Reward points', 'manual-phone-orders' );
	}

	/**
	 * Whether a redeem action can be attempted.
	 *
	 * @return bool
	 */
	private static function can_redeem_points() {
		return function_exists( 'YITH_WC_Points_Rewards_Redemption' )
			|| class_exists( 'YITH_WC_Points_Rewards_Redeeming' )
			|| class_exists( 'YITH_WC_Points_Rewards_Redemption' )
			|| class_exists( 'WC_Points_Rewards_Discount' )
			|| function_exists( 'ywpar_get_customer' );
	}
}
