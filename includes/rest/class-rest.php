<?php
/**
 * REST API for the phone-order SPA.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes under mpo/v1.
 */
class MPO_REST {

	const NS = 'mpo/v1';

	/**
	 * Register routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Permission: operators only.
	 *
	 * @return bool
	 */
	public static function permission() {
		return current_user_can( 'mpo_create_orders' );
	}

	/**
	 * Routes.
	 */
	public static function register_routes() {
		$uuid = array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);

		register_rest_route(
			self::NS,
			'/session',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_or_create_session' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'new_session' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)',
			array(
				'args' => array( 'uuid' => $uuid ),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_session' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/customer',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_customer' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/address',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_address' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/cart/add',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cart_add' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/cart/update',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cart_update' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/cart/remove',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cart_remove' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/cart/price',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cart_price' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/cart/empty',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cart_empty' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/coupon',
			array(
				'args' => array( 'uuid' => $uuid ),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'apply_coupon' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'remove_coupon' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/fee',
			array(
				'args' => array( 'uuid' => $uuid ),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'add_fee' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'remove_fee' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/shipping',
			array(
				'args'                => array(
					'uuid'   => $uuid,
					'method' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'custom' => array(
						'required'          => false,
					),
				),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_shipping' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/payment',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_payment' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/rewards',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'redeem_rewards' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/notes',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_notes' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/hold',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'hold' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/resume',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'resume' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/load-order',
			array(
				'args'                => array(
					'uuid'     => $uuid,
					'order_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'load_order' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/session/(?P<uuid>[a-f0-9-]+)/place',
			array(
				'args'                => array( 'uuid' => $uuid ),
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'place' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'products' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/products/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'product' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/customers',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'customers' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/customers',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_customer' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'orders' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/geo',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'geo' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/held',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'held' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * Get or create the operator's active draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_or_create_session( $request ) {
		$operator = get_current_user_id();
		$draft    = MPO_Draft_Repository::get_active_for_operator( $operator );
		if ( ! $draft ) {
			$draft = MPO_Draft_Repository::create( $operator, 0 );
		}
		return self::cart_response( $draft );
	}

	/**
	 * Start a brand new draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function new_session( $request ) {
		$operator = get_current_user_id();
		$active   = MPO_Draft_Repository::get_active_for_operator( $operator );
		if ( $active ) {
			$has_items        = ! empty( $active->session_data['cart'] );
			$active->status   = $has_items ? 'held' : 'abandoned';
			MPO_Draft_Repository::save( $active );
		}
		$draft = MPO_Draft_Repository::create( $operator, 0 );
		return self::cart_response( $draft );
	}

	/**
	 * Get a draft by uuid.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_session( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		return self::cart_response( $draft );
	}

	/**
	 * Attach a customer. Creates/resumes that customer's isolated cart for this operator.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_customer( $request ) {
		$source     = self::require_draft( $request );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$customer_id = absint( $request->get_param( 'customer_id' ) );
		$operator    = get_current_user_id();

		if ( $customer_id > 0 && ! get_userdata( $customer_id ) ) {
			return new WP_Error( 'mpo_customer', __( 'Customer not found.', 'manual-phone-orders' ), array( 'status' => 404 ) );
		}

		$existing = MPO_Draft_Repository::get_active_for_pair( $operator, $customer_id );
		if ( $existing && $existing->uuid !== $source->uuid ) {
			return self::cart_response( $existing );
		}

		$has_items = ! empty( $source->session_data['cart'] );
		if ( $has_items && (int) $source->customer_id !== $customer_id ) {
			$draft               = MPO_Draft_Repository::create( $operator, $customer_id );
			return self::cart_response( $draft );
		}

		if ( (int) $source->customer_id !== $customer_id ) {
			$source->set_meta( 'billing', array() );
			$source->set_meta( 'shipping', array() );
		}

		$source->customer_id = $customer_id;
		MPO_Draft_Repository::save( $source );

		return self::cart_response( $source );
	}

	/**
	 * Update billing/shipping on the draft + WC customer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_address( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$billing  = $request->get_param( 'billing' );
		$shipping = $request->get_param( 'shipping' );

		if ( is_array( $billing ) ) {
			$draft->set_meta( 'billing', wc_clean( $billing ) );
		}
		if ( is_array( $shipping ) ) {
			$draft->set_meta( 'shipping', wc_clean( $shipping ) );
		}
		MPO_Draft_Repository::save( $draft );

		return self::cart_response( $draft );
	}

	/**
	 * Add product.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cart_add( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$product_id   = absint( $request->get_param( 'product_id' ) );
		$qty          = (float) $request->get_param( 'qty' );
		$variation_id = absint( $request->get_param( 'variation_id' ) );
		$attributes   = (array) $request->get_param( 'attributes' );
		$custom_price = $request->get_param( 'custom_price' );
		$custom_name  = $request->get_param( 'custom_name' );
		$as_gift      = ! empty( $request->get_param( 'gift' ) );

		if ( $qty <= 0 ) {
			$qty = 1;
		}

		if ( $product_id && ! $variation_id && ! empty( $attributes ) ) {
			$variation_id = MPO_Catalog::find_variation( $product_id, $attributes );
		}

		try {
			return self::cart_response(
				$draft,
				static function () use ( $product_id, $qty, $variation_id, $attributes, $custom_price, $custom_name, $as_gift ) {
					$extra = array();
					if ( null !== $custom_price && '' !== $custom_price ) {
						$extra['mpo_custom_price'] = (float) $custom_price;
					}
					if ( $custom_name ) {
						$extra['mpo_custom_name'] = wc_clean( $custom_name );
					}
					if ( $as_gift ) {
						$extra['mpo_gift'] = 1;
					}
					MPO_Cart_Service::add_product( $product_id, $qty, $variation_id, $attributes, $extra );
				}
			);
		} catch ( Exception $e ) {
			if ( 'needs_variation' === $e->getMessage() ) {
				$product = MPO_Catalog::serialize_product( $product_id );
				return new WP_REST_Response(
					array(
						'code'    => 'needs_variation',
						'message' => __( 'Select product options.', 'manual-phone-orders' ),
						'product' => $product,
					),
					409
				);
			}
			return new WP_Error( 'mpo_add', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Batched quantity updates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cart_update( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'mpo_items', __( 'items must be an array.', 'manual-phone-orders' ), array( 'status' => 400 ) );
		}

		try {
			return self::cart_response(
				$draft,
				static function () use ( $items ) {
					MPO_Cart_Service::update_quantities( $items );
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_update', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Remove line.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cart_remove( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$key = wc_clean( (string) $request->get_param( 'key' ) );
		try {
			return self::cart_response(
				$draft,
				static function () use ( $key ) {
					MPO_Cart_Service::remove_item( $key );
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_remove', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Custom line price.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cart_price( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$key   = wc_clean( (string) $request->get_param( 'key' ) );
		$price = (float) $request->get_param( 'price' );
		try {
			return self::cart_response(
				$draft,
				static function () use ( $key, $price ) {
					MPO_Cart_Service::set_item_price( $key, $price );
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_price', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Empty cart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cart_empty( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		try {
			return self::cart_response(
				$draft,
				static function () {
					MPO_Cart_Service::empty_cart();
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_empty', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Apply coupon.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function apply_coupon( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$code = (string) $request->get_param( 'code' );
		try {
			return self::cart_response(
				$draft,
				static function () use ( $code ) {
					MPO_Cart_Service::apply_coupon( $code );
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_coupon', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Remove coupon.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove_coupon( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$code = (string) $request->get_param( 'code' );
		try {
			return self::cart_response(
				$draft,
				static function () use ( $code ) {
					MPO_Cart_Service::remove_coupon( $code );
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_coupon', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Add fee.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_fee( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$name    = wc_clean( (string) $request->get_param( 'name' ) );
		$amount  = (float) $request->get_param( 'amount' );
		$taxable = (bool) $request->get_param( 'taxable' );
		try {
			return self::cart_response(
				$draft,
				static function () use ( $name, $amount, $taxable ) {
					MPO_Cart_Service::add_fee( $name, $amount, $taxable );
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_fee', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Remove fee.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove_fee( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$id = wc_clean( (string) $request->get_param( 'id' ) );
		try {
			return self::cart_response(
				$draft,
				static function () use ( $id ) {
					MPO_Cart_Service::remove_fee( $id );
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_fee', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Shipping method / custom amount.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_shipping( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$json   = $request->get_json_params();
		$json   = is_array( $json ) ? $json : array();
		$method = $request->get_param( 'method' );
		$custom = $request->get_param( 'custom' );
		if ( null === $method && array_key_exists( 'method', $json ) ) {
			$method = $json['method'];
		}
		if ( ( null === $custom || '' === $custom ) && array_key_exists( 'custom', $json ) ) {
			$custom = $json['custom'];
		}
		try {
			return self::cart_response(
				$draft,
				static function () use ( $method, $custom ) {
					if ( null !== $custom && '' !== $custom ) {
						MPO_Cart_Service::set_custom_shipping( $custom );
					} elseif ( $method ) {
						MPO_Cart_Service::set_custom_shipping( null );
						MPO_Cart_Service::set_shipping_method( $method );
					}
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_shipping', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Payment method.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_payment( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$method = wc_clean( (string) $request->get_param( 'method' ) );
		$draft->set_meta( 'payment_method', $method );
		MPO_Draft_Repository::save( $draft );
		try {
			return self::cart_response(
				$draft,
				static function () use ( $method ) {
					MPO_Cart_Service::set_payment_method( $method );
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_payment', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Redeem loyalty / YITH points.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function redeem_rewards( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		if ( ! $draft->customer_id ) {
			return new WP_Error( 'mpo_rewards', __( 'Select a customer before redeeming points.', 'manual-phone-orders' ), array( 'status' => 400 ) );
		}
		$json   = $request->get_json_params();
		$points = absint( is_array( $json ) && isset( $json['points'] ) ? $json['points'] : $request->get_param( 'points' ) );
		try {
			return self::cart_response(
				$draft,
				static function () use ( $points ) {
					MPO_Cart_Service::redeem_rewards( $points );
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_rewards', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Notes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_notes( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$draft->notes = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		MPO_Draft_Repository::save( $draft );
		return self::cart_response( $draft );
	}

	/**
	 * Hold the current draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function hold( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$draft->status = 'held';
		MPO_Draft_Repository::save( $draft );
		$fresh = MPO_Draft_Repository::create( get_current_user_id(), 0 );
		return self::cart_response( $fresh );
	}

	/**
	 * Resume a held draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function resume( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$draft->status = 'active';
		MPO_Draft_Repository::save( $draft );
		return self::cart_response( $draft );
	}

	/**
	 * Load items from a previous order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function load_order( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		$order_id = absint( $request->get_param( 'order_id' ) );
		if ( ! $order_id ) {
			$json     = $request->get_json_params();
			$order_id = absint( is_array( $json ) && isset( $json['order_id'] ) ? $json['order_id'] : 0 );
		}
		$order    = wc_get_order( $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return new WP_Error( 'mpo_order', __( 'Order not found.', 'manual-phone-orders' ), array( 'status' => 404 ) );
		}

		MPO_Cart_Service::apply_order_customer_to_draft( $draft, $order );
		MPO_Draft_Repository::save( $draft );

		try {
			return self::cart_response(
				$draft,
				static function () use ( $order_id ) {
					MPO_Cart_Service::load_from_order( $order_id );
				}
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_order', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Place the order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function place( $request ) {
		$draft = self::require_draft( $request );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$json = $request->get_json_params();
		if ( ! is_array( $json ) ) {
			$json = array();
		}

		$args = array(
			'payment_method' => isset( $json['payment_method'] ) ? $json['payment_method'] : $request->get_param( 'payment_method' ),
			'mark_paid'      => ! empty( $json['mark_paid'] ) || (bool) $request->get_param( 'mark_paid' ),
			'status'         => isset( $json['status'] ) ? $json['status'] : $request->get_param( 'status' ),
			'note'           => isset( $json['note'] ) ? $json['note'] : $request->get_param( 'note' ),
			'payment_data'   => isset( $json['payment_data'] ) && is_array( $json['payment_data'] ) ? $json['payment_data'] : array(),
		);

		try {
			$placed = null;
			$payload = MPO_Cart_Service::with_context(
				$draft,
				static function () use ( $draft, $args, &$placed ) {
					$placed = MPO_Checkout_Service::place( $draft, $args );
					return array( '_merge' => array( 'placed' => $placed ) );
				}
			);
			$payload['held'] = self::held_payload();
			return rest_ensure_response( $payload );
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_place', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Product search.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function products( $request ) {
		$term = (string) $request->get_param( 'q' );
		return rest_ensure_response( MPO_Catalog::search( $term ) );
	}

	/**
	 * Single product.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function product( $request ) {
		$data = MPO_Catalog::serialize_product( absint( $request['id'] ) );
		if ( ! $data ) {
			return new WP_Error( 'mpo_product', __( 'Product not found.', 'manual-phone-orders' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Customer search.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function customers( $request ) {
		$term = (string) $request->get_param( 'q' );
		return rest_ensure_response( MPO_Customers::search( $term ) );
	}

	/**
	 * Create customer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_customer( $request ) {
		try {
			$user_id = MPO_Customers::create( $request->get_json_params() ? $request->get_json_params() : $request->get_params() );
			return rest_ensure_response( MPO_Customers::serialize( $user_id ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'mpo_customer', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Order search.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function orders( $request ) {
		return rest_ensure_response( MPO_Checkout_Service::search_orders( (string) $request->get_param( 'q' ) ) );
	}

	/**
	 * Geo.
	 *
	 * @return WP_REST_Response
	 */
	public static function geo() {
		return rest_ensure_response( MPO_Customers::geography() );
	}

	/**
	 * Held drafts.
	 *
	 * @return WP_REST_Response
	 */
	public static function held() {
		return rest_ensure_response( self::held_payload() );
	}

	/**
	 * Load draft owned by current operator.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return MPO_Draft|WP_Error
	 */
	private static function require_draft( $request ) {
		$uuid  = (string) $request['uuid'];
		$draft = MPO_Draft_Repository::get_by_uuid( $uuid );
		if ( ! $draft ) {
			return new WP_Error( 'mpo_session', __( 'Session not found.', 'manual-phone-orders' ), array( 'status' => 404 ) );
		}
		if ( (int) $draft->operator_id !== (int) get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'mpo_forbidden', __( 'You cannot access this session.', 'manual-phone-orders' ), array( 'status' => 403 ) );
		}
		return $draft;
	}

	/**
	 * Run optional mutation then serialize cart.
	 *
	 * @param MPO_Draft     $draft    Draft.
	 * @param callable|null $callback Callback.
	 * @return WP_REST_Response
	 */
	private static function cart_response( MPO_Draft $draft, $callback = null ) {
		$payload = MPO_Cart_Service::with_context(
			$draft,
			static function () use ( $callback ) {
				if ( $callback ) {
					$callback();
				}
			}
		);
		$payload['held'] = self::held_payload();
		return rest_ensure_response( $payload );
	}

	/**
	 * Held list for the operator (always the logged-in admin, not the customer).
	 *
	 * @return array
	 */
	private static function held_payload() {
		$operator = get_current_user_id();
		$held     = MPO_Draft_Repository::get_held_for_operator( $operator );
		$out      = array();
		foreach ( $held as $draft ) {
			$customer = $draft->customer_id ? get_userdata( $draft->customer_id ) : null;
			$out[]    = array(
				'uuid'      => $draft->uuid,
				'customer'  => $customer ? $customer->display_name : __( 'Guest', 'manual-phone-orders' ),
				'email'     => $customer ? $customer->user_email : '',
				'updated'   => $draft->updated_at,
				'item_hint' => isset( $draft->session_data['cart'] ) && is_array( $draft->session_data['cart'] ) ? count( $draft->session_data['cart'] ) : 0,
			);
		}
		return $out;
	}
}
