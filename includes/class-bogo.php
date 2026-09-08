<?php
/**
 * WooCommerce Buy One Get One Free (Oscar Gare) on the phone-order screen.
 *
 * The storefront popup never loads in wp-admin. This collects eligible gifts
 * from the plugin's cart/session APIs and lets the operator add them here.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * BOGO gift chooser.
 */
class MPO_Bogo {

	/**
	 * Whether the Oscar Gare BOGO plugin (or the choose-gift shortcode) is present.
	 *
	 * @return bool
	 */
	public static function active() {
		return defined( 'WC_BOGOF_VERSION' )
			|| defined( 'WC_BOGOF_PLUGIN_FILE' )
			|| defined( 'WC_BOGOF_FILE' )
			|| class_exists( 'WC_BOGOF' )
			|| class_exists( 'WC_Buy_One_Get_One_Free' )
			|| function_exists( 'wc_bogof' )
			|| shortcode_exists( 'wc_choose_your_gift' );
	}

	/**
	 * Avoid nested eligibility probes.
	 *
	 * @var bool
	 */
	private static $syncing = false;

	/**
	 * Cart payload for the SPA.
	 *
	 * @return array
	 */
	public static function serialize() {
		if ( ! self::active() ) {
			return array(
				'enabled' => false,
				'qty'     => 0,
				'notice'  => '',
				'items'   => array(),
			);
		}

		$state = self::current_state();
		if ( ! $state['eligible'] ) {
			return array(
				'enabled' => true,
				'qty'     => 0,
				'notice'  => '',
				'items'   => array(),
			);
		}

		$items = array();
		$seen  = array();
		foreach ( $state['ids'] as $id ) {
			$id = absint( $id );
			if ( ! $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$product     = MPO_Catalog::serialize_product( $id );
			if ( $product ) {
				$regular = (float) ( $product['regular_price'] > 0 ? $product['regular_price'] : $product['price'] );
				$product['price']           = 0;
				$product['gift_price_html'] = '<del>' . wc_price( $regular ) . '</del> ' . wc_price( 0 );
				$items[]                    = $product;
			}
		}

		$notice = $state['notice'];
		$qty    = max( 1, (int) $state['remaining'] );
		if ( ! $notice ) {
			$notice = sprintf(
				/* translators: %d remaining free gifts */
				_n( 'This cart can take %d free gift.', 'This cart can take %d free gifts.', $qty, 'manual-phone-orders' ),
				$qty
			);
		}

		return array(
			'enabled' => true,
			'qty'     => $qty,
			'notice'  => $notice,
			'items'   => $items,
		);
	}

	/**
	 * Drop the free gift when the cart no longer meets the BOGO condition.
	 *
	 * @return bool True when the cart was changed.
	 */
	public static function sync_after_totals() {
		if ( self::$syncing || ! self::active() || ! WC()->cart ) {
			return false;
		}

		if ( self::is_currently_eligible() ) {
			self::set_qualified( true );
			return false;
		}

		if ( ! self::cart_has_gift() ) {
			self::set_qualified( false );
			return false;
		}

		self::$syncing = true;
		$snapshot      = self::snapshot_gifts();
		self::remove_existing_gifts();
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}
		WC()->cart->calculate_totals();

		$still_eligible = self::is_currently_eligible();
		self::set_qualified( $still_eligible );
		if ( $still_eligible ) {
			self::restore_gifts( $snapshot );
		}

		self::$syncing = false;
		return true;
	}

	/**
	 * Seed request/cart data so BOGO treats the next add_to_cart as a gift.
	 *
	 * @param int $product_id   Product.
	 * @param int $variation_id Variation.
	 */
	public static function prepare_gift_add( $product_id, $variation_id = 0 ) {
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		$_POST['add-to-cart']          = $product_id;
		$_REQUEST['add-to-cart']       = $product_id;
		$_POST['wc_bogof_gift']        = 'yes';
		$_REQUEST['wc_bogof_gift']     = 'yes';
		$_POST['bogof']                = 1;
		$_REQUEST['bogof']             = 1;
		$_POST['choose_your_gift']     = 1;
		$_REQUEST['choose_your_gift']  = 1;
		if ( $variation_id ) {
			$_POST['variation_id']    = $variation_id;
			$_REQUEST['variation_id'] = $variation_id;
		}

		do_action( 'wc_bogof_before_add_gift', $product_id, $variation_id );
	}

	/**
	 * Extra cart item data BOGO often keys off.
	 *
	 * @return array
	 */
	public static function gift_cart_item_data() {
		return array(
			'mpo_gift'         => 1,
			'mpo_custom_price' => 0,
			'wc_bogof_gift'    => 'yes',
			'_bogof'           => 1,
		);
	}

	/**
	 * Remove any gift already in the cart so only one free item remains.
	 */
	public static function remove_existing_gifts() {
		if ( ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( self::is_gift_item( $item ) ) {
				WC()->cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * Whether the isolated cart already has a free gift.
	 *
	 * @return bool
	 */
	public static function cart_has_gift() {
		if ( ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_gift_item( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Force gift lines to $0 after other pricing plugins run.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function zero_gift_prices( $cart ) {
		if ( ! mpo_doing_context() || ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( self::is_gift_item( $item ) && isset( $item['data'] ) && is_a( $item['data'], 'WC_Product' ) ) {
				$item['data']->set_price( 0 );
			}
		}
	}

	/**
	 * Whether a cart line is a BOGO / free gift.
	 *
	 * @param array $item Cart item.
	 * @return bool
	 */
	public static function is_gift_item( $item ) {
		if ( ! is_array( $item ) ) {
			return false;
		}

		$keys = array(
			'mpo_gift',
			'bogof',
			'_bogof',
			'wc_bogof',
			'wc_bogof_gift',
			'wc_bogof_rule_id',
			'bogof_id',
			'bogof_rule_id',
			'free_gift',
			'is_gift',
			'_wc_bogof',
			'wc_bogof_data',
			'parent_key',
			'free_product',
		);

		foreach ( $keys as $key ) {
			if ( ! empty( $item[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remember that this cart still qualifies after a gift is already added.
	 *
	 * @param bool $qualified Qualified.
	 */
	private static function set_qualified( $qualified ) {
		if ( WC()->session ) {
			WC()->session->set( 'mpo_bogo_qualified', $qualified ? 1 : 0 );
		}
	}

	/**
	 * Whether the last sync found the BOGO condition still met.
	 *
	 * @return bool
	 */
	private static function is_qualified() {
		if ( ! WC()->session ) {
			return false;
		}
		return (int) WC()->session->get( 'mpo_bogo_qualified', 0 ) > 0;
	}

	/**
	 * Whether the BOGO plugin currently offers a gift for this cart.
	 *
	 * @return bool
	 */
	public static function is_currently_eligible() {
		$notice    = self::gift_notice_text();
		$raw       = self::discover();
		$remaining = isset( $raw['remaining'] ) ? (int) $raw['remaining'] : 0;
		return '' !== $notice || $remaining > 0;
	}

	/**
	 * Live eligibility + gift product ids (not leftover session lists).
	 *
	 * @return array
	 */
	private static function current_state() {
		$notice    = self::gift_notice_text();
		$raw       = self::discover();
		$remaining = isset( $raw['remaining'] ) ? (int) $raw['remaining'] : 0;
		$ids       = isset( $raw['ids'] ) ? $raw['ids'] : array();
		$eligible  = '' !== $notice || $remaining > 0 || ( self::cart_has_gift() && self::is_qualified() );

		return array(
			'eligible'  => $eligible,
			'notice'    => $notice,
			'remaining' => $remaining,
			'ids'       => $eligible ? $ids : array(),
		);
	}

	/**
	 * Cart gift lines to restore after an eligibility probe.
	 *
	 * @return array
	 */
	private static function snapshot_gifts() {
		$out = array();
		if ( ! WC()->cart ) {
			return $out;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! self::is_gift_item( $item ) ) {
				continue;
			}
			$out[] = array(
				'product_id'   => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
				'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				'variation'    => isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array(),
			);
		}

		return $out;
	}

	/**
	 * Put the previous gift back after a successful eligibility probe.
	 *
	 * @param array $snapshot Snapshot.
	 */
	private static function restore_gifts( array $snapshot ) {
		if ( ! WC()->cart ) {
			return;
		}

		foreach ( $snapshot as $gift ) {
			$product_id = isset( $gift['product_id'] ) ? (int) $gift['product_id'] : 0;
			if ( ! $product_id ) {
				continue;
			}
			$variation_id = isset( $gift['variation_id'] ) ? (int) $gift['variation_id'] : 0;
			$variation    = isset( $gift['variation'] ) ? $gift['variation'] : array();
			self::prepare_gift_add( $product_id, $variation_id );
			WC()->cart->add_to_cart( $product_id, 1, $variation_id, $variation, self::gift_cart_item_data() );
		}
	}

	/**
	 * Whether a WooCommerce notice is the choose-gift banner.
	 *
	 * @param string $text Notice HTML/text.
	 * @return bool
	 */
	public static function is_gift_notice( $text ) {
		return (bool) preg_match( '/gift|choose your/i', wp_strip_all_tags( (string) $text ) );
	}

	/**
	 * Pull gift lists from the BOGO plugin.
	 *
	 * @return array { remaining: int, ids: int[], live: bool }
	 */
	private static function discover() {
		$ids       = array();
		$remaining = 0;
		$live      = false;

		$from_api = self::from_plugin_api();
		self::merge_discovery( $from_api, $ids, $remaining );
		if ( ! empty( $from_api['remaining'] ) ) {
			$live = true;
		}

		$from_html = self::from_frontend_html();
		self::merge_discovery( $from_html, $ids, $remaining );
		if ( ! empty( $from_html['live'] ) || ! empty( $from_html['remaining'] ) ) {
			$live = true;
		}

		if ( $live || $remaining > 0 || self::gift_notice_text() ) {
			$from_session = self::from_session();
			self::merge_discovery( $from_session, $ids, $remaining );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		return array(
			'remaining' => $remaining,
			'ids'       => $ids,
			'live'      => $live,
		);
	}

	/**
	 * Call known BOGO classes / filters.
	 *
	 * @return array
	 */
	private static function from_plugin_api() {
		$collected = array();

		foreach ( array( 'wc_bogof_get_available_gifts', 'wc_bogof_available_gifts', 'woocommerce_bogof_available_gifts' ) as $hook ) {
			$filtered = apply_filters( $hook, array() );
			if ( ! empty( $filtered ) ) {
				$collected[] = $filtered;
			}
		}

		if ( function_exists( 'wc_bogof' ) ) {
			try {
				$plugin = wc_bogof();
				if ( is_object( $plugin ) ) {
					foreach ( array( 'cart', 'frontend', 'choose_your_gift', 'gifts', 'choose_gift' ) as $prop ) {
						if ( isset( $plugin->{$prop} ) && is_object( $plugin->{$prop} ) ) {
							$collected[] = self::call_gift_methods( $plugin->{$prop} );
						}
					}
					$collected[] = self::call_gift_methods( $plugin );
				}
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		foreach ( array(
			'WC_BOGOF',
			'WC_BOGOF_Cart',
			'WC_BOGOF_Frontend',
			'WC_BOGOF_Choose_Your_Gift',
			'WC_BOGOF_Choose_Gift',
			'WC_BOGOF_Gifts',
			'WC_BOGOF_Cart_Rules',
			'WC_Buy_One_Get_One_Free',
		) as $class ) {
			$obj = self::plugin_instance( $class );
			if ( $obj ) {
				$collected[] = self::call_gift_methods( $obj );
			} elseif ( class_exists( $class ) ) {
				$collected[] = self::call_gift_methods( $class );
			}
		}

		$ids = array();
		$qty = 0;
		foreach ( $collected as $chunk ) {
			self::merge_discovery( self::normalize_discovery( $chunk ), $ids, $qty );
		}

		return array(
			'remaining' => $qty,
			'ids'       => $ids,
		);
	}

	/**
	 * Session keys that mention bogo/gift.
	 *
	 * @return array
	 */
	private static function from_session() {
		$ids = array();
		$qty = 0;

		if ( ! WC()->session ) {
			return array(
				'remaining' => 0,
				'ids'       => array(),
			);
		}

		$data = array();
		if ( WC()->session instanceof MPO_Isolated_Session ) {
			$data = WC()->session->get_session_data();
		} elseif ( is_callable( array( WC()->session, 'get_session_data' ) ) ) {
			$data = WC()->session->get_session_data();
		}

		foreach ( (array) $data as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/bogo|gift/i', $key ) ) {
				continue;
			}
			self::merge_discovery( self::normalize_discovery( $value ), $ids, $qty );
		}

		return array(
			'remaining' => $qty,
			'ids'       => $ids,
		);
	}

	/**
	 * Render the plugin's own gift UI and scrape product IDs.
	 *
	 * @return array
	 */
	private static function from_frontend_html() {
		$html = '';

		if ( shortcode_exists( 'wc_choose_your_gift' ) ) {
			try {
				$html .= (string) do_shortcode( '[wc_choose_your_gift]' );
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		ob_start();
		try {
			do_action( 'woocommerce_before_cart' );
			do_action( 'woocommerce_after_cart_table' );
			do_action( 'woocommerce_after_cart' );
			do_action( 'wc_bogof_choose_your_gift' );
			do_action( 'woocommerce_bogof_choose_your_gift' );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
		$html .= (string) ob_get_clean();

		$ids = array();
		if ( $html && preg_match_all( '/data-product[_-]id=["\']?(\d+)/i', $html, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
		if ( $html && preg_match_all( '/add-to-cart=(\d+)/i', $html, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}

		$qty = 0;
		if ( $html && preg_match( '/data-(?:qty|quantity|remaining)=["\']?(\d+)/i', $html, $m ) ) {
			$qty = (int) $m[1];
		}

		return array(
			'remaining' => $qty,
			'ids'       => $ids,
			'live'      => $qty > 0 || ! empty( $ids ),
		);
	}

	/**
	 * Gift notice currently in the WC notice stack.
	 *
	 * @return string
	 */
	private static function gift_notice_text() {
		$all = function_exists( 'wc_get_notices' ) ? wc_get_notices() : array();
		foreach ( (array) $all as $notices ) {
			foreach ( (array) $notices as $notice ) {
				$text = is_array( $notice ) && isset( $notice['notice'] ) ? $notice['notice'] : (string) $notice;
				if ( self::is_gift_notice( $text ) ) {
					return wp_strip_all_tags( $text );
				}
			}
		}
		return '';
	}

	/**
	 * Instance or singleton of a BOGO class.
	 *
	 * @param string $class Class.
	 * @return object|null
	 */
	private static function plugin_instance( $class ) {
		if ( ! class_exists( $class ) ) {
			return null;
		}
		foreach ( array( 'instance', 'get_instance', 'getInstance' ) as $method ) {
			if ( is_callable( array( $class, $method ) ) ) {
				try {
					$obj = $class::$method();
					if ( is_object( $obj ) ) {
						return $obj;
					}
				} catch ( Throwable $e ) {
					return null;
				}
			}
		}
		return null;
	}

	/**
	 * Call methods whose names suggest a gift list.
	 *
	 * @param object|string $obj Object or class.
	 * @return mixed
	 */
	private static function call_gift_methods( $obj ) {
		$methods = array(
			'get_available_gifts',
			'get_available_gift_products',
			'get_gifts',
			'get_gift_products',
			'get_choose_your_gift_products',
			'get_products',
			'get_eligible_gifts',
			'available_gifts',
		);
		foreach ( $methods as $method ) {
			if ( is_callable( array( $obj, $method ) ) ) {
				try {
					$result = call_user_func( array( $obj, $method ) );
					if ( ! empty( $result ) ) {
						return $result;
					}
				} catch ( Throwable $e ) {
					continue;
				}
			}
		}
		return array();
	}

	/**
	 * Flatten plugin output into ids + qty.
	 *
	 * @param mixed $data Raw.
	 * @return array
	 */
	private static function normalize_discovery( $data ) {
		$ids = array();
		$qty = 0;

		if ( empty( $data ) ) {
			return array(
				'remaining' => 0,
				'ids'       => array(),
			);
		}

		if ( is_numeric( $data ) ) {
			return array(
				'remaining' => (int) $data,
				'ids'       => array(),
			);
		}

		if ( $data instanceof WC_Product ) {
			return array(
				'remaining' => 0,
				'ids'       => array( $data->get_id() ),
			);
		}

		if ( is_array( $data ) ) {
			if ( isset( $data['qty'] ) || isset( $data['quantity'] ) || isset( $data['remaining'] ) || isset( $data['ids'] ) || isset( $data['products'] ) || isset( $data['items'] ) ) {
				$qty = (int) ( isset( $data['qty'] ) ? $data['qty'] : ( isset( $data['quantity'] ) ? $data['quantity'] : ( isset( $data['remaining'] ) ? $data['remaining'] : 0 ) ) );
				foreach ( array( 'ids', 'products', 'items', 'gifts' ) as $key ) {
					if ( ! empty( $data[ $key ] ) ) {
						self::collect_product_ids( $data[ $key ], $ids, $qty );
					}
				}
				self::collect_product_ids( $data, $ids, $qty );
				return array(
					'remaining' => $qty,
					'ids'       => $ids,
				);
			}

			self::collect_product_ids( $data, $ids, $qty );
		}

		return array(
			'remaining' => $qty,
			'ids'       => $ids,
		);
	}

	/**
	 * Recursively collect product IDs.
	 *
	 * @param mixed $data Data.
	 * @param int[] $ids  Collector.
	 * @param int   $qty  Qty collector.
	 */
	private static function collect_product_ids( $data, array &$ids, &$qty ) {
		if ( $data instanceof WC_Product ) {
			$ids[] = $data->get_id();
			return;
		}

		if ( is_numeric( $data ) ) {
			$id = absint( $data );
			if ( $id && wc_get_product( $id ) ) {
				$ids[] = $id;
			}
			return;
		}

		if ( ! is_array( $data ) ) {
			return;
		}

		foreach ( array( 'product_id', 'variation_id', 'id' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
				$id = absint( $data[ $key ] );
				if ( $id && ( 'id' !== $key || wc_get_product( $id ) ) ) {
					$ids[] = $id;
				}
			}
		}

		foreach ( array( 'qty', 'quantity', 'remaining', 'free_qty', 'available_qty' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) && (int) $data[ $key ] > $qty ) {
				$qty = (int) $data[ $key ];
			}
		}

		$skip = array( 'qty', 'quantity', 'remaining', 'free_qty', 'available_qty', 'rule_id', 'priority' );
		foreach ( $data as $key => $row ) {
			if ( is_string( $key ) && in_array( $key, $skip, true ) ) {
				continue;
			}
			if ( is_array( $row ) || $row instanceof WC_Product || is_numeric( $row ) ) {
				self::collect_product_ids( $row, $ids, $qty );
			}
		}
	}

	/**
	 * Merge one discovery chunk.
	 *
	 * @param array $chunk Chunk.
	 * @param int[] $ids   Collector.
	 * @param int   $qty   Qty.
	 */
	private static function merge_discovery( $chunk, array &$ids, &$qty ) {
		if ( empty( $chunk ) || ! is_array( $chunk ) ) {
			return;
		}
		if ( ! empty( $chunk['ids'] ) ) {
			$ids = array_merge( $ids, (array) $chunk['ids'] );
		}
		$chunk_qty = 0;
		if ( isset( $chunk['remaining'] ) ) {
			$chunk_qty = (int) $chunk['remaining'];
		} elseif ( isset( $chunk['qty'] ) ) {
			$chunk_qty = (int) $chunk['qty'];
		}
		if ( $chunk_qty > $qty ) {
			$qty = $chunk_qty;
		}
	}
}
