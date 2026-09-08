<?php
/**
 * Place WooCommerce orders from an isolated cart.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checkout.
 */
class MPO_Checkout_Service {

	/**
	 * Create an order from the current cart context.
	 *
	 * @param MPO_Draft $draft Draft.
	 * @param array     $args  Args: payment_method, mark_paid, status, note.
	 * @return array
	 */
	public static function place( MPO_Draft $draft, array $args ) {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			throw new Exception( __( 'The cart is empty.', 'manual-phone-orders' ) );
		}

		$payment_method = isset( $args['payment_method'] ) ? wc_clean( $args['payment_method'] ) : (string) WC()->session->get( 'chosen_payment_method' );
		$mark_paid      = ! empty( $args['mark_paid'] );
		$status         = isset( $args['status'] ) ? wc_clean( $args['status'] ) : '';
		$note           = isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : $draft->notes;
		$payment_data   = isset( $args['payment_data'] ) && is_array( $args['payment_data'] ) ? $args['payment_data'] : array();

		$needs_payment = WC()->cart->needs_payment();
		if ( $needs_payment && ! $payment_method ) {
			throw new Exception( __( 'Select a payment method.', 'manual-phone-orders' ) );
		}

		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$gateway  = ( $payment_method && isset( $gateways[ $payment_method ] ) ) ? $gateways[ $payment_method ] : null;

		if ( $needs_payment && $payment_method && ! $gateway ) {
			throw new Exception( __( 'That payment method is not available for this customer.', 'manual-phone-orders' ) );
		}

		$customer = WC()->customer;
		$data     = array(
			'payment_method' => $payment_method,
			'customer_id'    => (int) $draft->customer_id,
		);

		$fields = array(
			'billing_first_name',
			'billing_last_name',
			'billing_company',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_country',
			'billing_email',
			'billing_phone',
			'shipping_first_name',
			'shipping_last_name',
			'shipping_company',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
			'shipping_country',
			'shipping_phone',
		);

		foreach ( $fields as $field ) {
			$method = 'get_' . $field;
			$data[ $field ] = $customer && is_callable( array( $customer, $method ) ) ? $customer->{$method}() : '';
		}

		$ship_keys = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );
		$ship_to_different = false;
		foreach ( $ship_keys as $key ) {
			$ship = isset( $data[ 'shipping_' . $key ] ) ? trim( (string) $data[ 'shipping_' . $key ] ) : '';
			$bill = isset( $data[ 'billing_' . $key ] ) ? trim( (string) $data[ 'billing_' . $key ] ) : '';
			if ( '' !== $ship && $ship !== $bill ) {
				$ship_to_different = true;
				break;
			}
		}

		if ( ! $ship_to_different ) {
			foreach ( $ship_keys as $key ) {
				$bill_key = 'billing_' . $key;
				if ( isset( $data[ $bill_key ] ) ) {
					$data[ 'shipping_' . $key ] = $data[ $bill_key ];
				}
			}
		}

		$data['ship_to_different_address'] = $ship_to_different ? 1 : 0;

		if ( empty( $data['billing_email'] ) && $draft->customer_id ) {
			$user = get_userdata( $draft->customer_id );
			if ( $user ) {
				$data['billing_email'] = $user->user_email;
			}
		}

		self::seed_checkout_post( $data, $payment_method, $payment_data );

		if ( $gateway && ! $mark_paid ) {
			self::assert_card_fields_present( $payment_data );
			self::validate_gateway_fields( $gateway );
		}

		add_filter( 'woocommerce_checkout_customer_id', array( __CLASS__, 'checkout_customer_id' ), 9999 );
		self::$placing_customer_id = (int) $draft->customer_id;

		try {
			$order_id = WC()->checkout()->create_order( $data );
		} finally {
			remove_filter( 'woocommerce_checkout_customer_id', array( __CLASS__, 'checkout_customer_id' ), 9999 );
		}

		if ( is_wp_error( $order_id ) ) {
			throw new Exception( $order_id->get_error_message() );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new Exception( __( 'Order could not be created.', 'manual-phone-orders' ) );
		}

		$order->set_created_via( 'manual-phone-orders' );
		$order->set_customer_id( (int) $draft->customer_id );

		$operator = get_userdata( $draft->operator_id );
		$who      = $operator ? $operator->display_name : '#' . $draft->operator_id;
		$order->add_order_note(
			sprintf(
				/* translators: %s operator name */
				__( 'Created via Manual Phone Orders by %s.', 'manual-phone-orders' ),
				$who
			)
		);

		if ( $note ) {
			$order->set_customer_note( $note );
		}

		if ( $gateway ) {
			$order->set_payment_method( $gateway );
		}

		$order->save();

		$payment_redirect = null;
		if ( $mark_paid ) {
			$order->payment_complete();
		} elseif ( $status ) {
			$order->update_status( $status, __( 'Status set by phone order operator.', 'manual-phone-orders' ), true );
		} elseif ( $gateway && is_callable( array( $gateway, 'process_payment' ) ) ) {
			try {
				$payment_redirect = self::process_gateway_payment( $gateway, $order );
			} catch ( Exception $e ) {
				$order->delete( true );
				throw $e;
			}
		}

		$used_points = WC()->session ? WC()->session->get( 'ywpar_coupon_code_points' ) : null;
		if ( $used_points ) {
			$order->update_meta_data( '_ywpar_coupon_points', $used_points );
			$order->update_meta_data( '_ywpar_coupon_amount', WC()->session->get( 'ywpar_coupon_code_discount' ) );
			$order->update_meta_data( '_mpo_points_redeemed', $used_points );
			$order->save();
		}

		$order = wc_get_order( $order_id );

		WC()->cart->empty_cart( true );

		$draft->status = 'completed';
		MPO_Draft_Repository::save( $draft );

		return array(
			'order_id'          => $order->get_id(),
			'number'            => $order->get_order_number(),
			'status'            => $order->get_status(),
			'total'             => (float) $order->get_total(),
			'total_html'        => $order->get_formatted_order_total(),
			'edit_url'          => $order->get_edit_order_url(),
			'view_url'          => $order->get_view_order_url(),
			'payment_redirect'  => $payment_redirect,
		);
	}

	/**
	 * Put checkout + gateway fields into $_POST so WooCommerce gateways can read them.
	 *
	 * @param array  $data            Address payload.
	 * @param string $payment_method  Gateway id.
	 * @param array  $payment_data    Posted gateway fields.
	 */
	private static function seed_checkout_post( array $data, $payment_method, array $payment_data ) {
		foreach ( $data as $key => $value ) {
			$_POST[ $key ]     = $value;
			$_REQUEST[ $key ]  = $value;
		}

		$_POST['payment_method']                   = $payment_method;
		$_REQUEST['payment_method']                = $payment_method;
		$_POST['woocommerce_checkout_place_order'] = 1;

		foreach ( $payment_data as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			$_POST[ $key ]    = wp_unslash( $value );
			$_REQUEST[ $key ] = $_POST[ $key ];
		}
	}

	/**
	 * Run gateway field validation before creating the order.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway.
	 */
	private static function validate_gateway_fields( $gateway ) {
		if ( is_callable( array( $gateway, 'validate_fields' ) ) ) {
			$ok = $gateway->validate_fields();
			if ( false === $ok ) {
				throw new Exception( self::friendly_payment_error( self::first_wc_error( __( 'Please complete the payment form.', 'manual-phone-orders' ) ) ) );
			}
		}

		$errors = wc_get_notices( 'error' );
		if ( ! empty( $errors ) ) {
			throw new Exception( self::friendly_payment_error( self::first_wc_error( __( 'Please complete the payment form.', 'manual-phone-orders' ) ) ) );
		}
	}

	/**
	 * Process payment for the chosen gateway.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway.
	 * @param WC_Order           $order   Order.
	 * @return string|null Redirect URL when the customer must finish payment off-site.
	 */
	private static function process_gateway_payment( $gateway, WC_Order $order ) {
		$result = $gateway->process_payment( $order->get_id() );

		$errors = wc_get_notices( 'error' );
		if ( ! empty( $errors ) ) {
			throw new Exception( self::friendly_payment_error( self::first_wc_error( __( 'Payment could not be processed.', 'manual-phone-orders' ) ) ) );
		}

		if ( ! is_array( $result ) ) {
			return null;
		}

		if ( isset( $result['result'] ) && 'success' !== $result['result'] ) {
			throw new Exception( self::friendly_payment_error( self::first_wc_error( __( 'Payment could not be processed.', 'manual-phone-orders' ) ) ) );
		}

		$redirect = isset( $result['redirect'] ) ? (string) $result['redirect'] : '';
		if ( ! $redirect ) {
			return null;
		}

		$received = $order->get_checkout_order_received_url();
		if ( $redirect === $received || false !== strpos( $redirect, 'order-received' ) ) {
			return null;
		}

		return $redirect;
	}

	/**
	 * First WooCommerce error notice, or a fallback.
	 *
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function first_wc_error( $fallback ) {
		$notices = wc_get_notices( 'error' );
		wc_clear_notices();
		$parts   = array();
		foreach ( (array) $notices as $notice ) {
			$text = is_array( $notice ) && isset( $notice['notice'] ) ? $notice['notice'] : (string) $notice;
			$text = wp_strip_all_tags( $text );
			if ( $text && ! in_array( $text, $parts, true ) ) {
				$parts[] = $text;
			}
		}
		return $parts ? implode( ' ', $parts ) : $fallback;
	}

	/**
	 * Replace opaque gateway failures with an operator-facing prompt.
	 *
	 * @param string $message Gateway or WooCommerce message.
	 * @return string
	 */
	private static function friendly_payment_error( $message ) {
		$stripped   = wp_strip_all_tags( (string) $message );
		$normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $stripped ) ) );
		$prompt     = __( 'Enter the card number, expiry, and CVC in the Payment section, then place the order.', 'manual-phone-orders' );

		if ( ! $normalized ) {
			return $prompt;
		}

		$without = preg_replace(
			array(
				'/an error occurred[,.]?\s*please try again or try an alternate form of payment\.?/i',
				'/please complete the payment form\.?/i',
				'/payment could not be processed\.?/i',
				'/please enter your payment details\.?/i',
			),
			'',
			$stripped
		);
		$without = trim( preg_replace( '/\s+/', ' ', $without ) );

		if ( $without ) {
			return $without;
		}

		return $prompt;
	}

	/**
	 * Catch empty classic card fields before WooCommerce creates an order.
	 *
	 * @param array $payment_data Posted gateway fields.
	 */
	private static function assert_card_fields_present( array $payment_data ) {
		$found_card = false;
		foreach ( $payment_data as $key => $value ) {
			if ( ! preg_match( '/card[-_]?number|cc[-_]?num|account[-_]?number/i', (string) $key ) ) {
				continue;
			}
			$found_card = true;
			$digits     = preg_replace( '/\D+/', '', (string) $value );
			if ( strlen( $digits ) < 13 ) {
				throw new Exception( __( 'Enter the card number, expiry, and CVC in the Payment section, then place the order.', 'manual-phone-orders' ) );
			}
		}

		if ( $found_card ) {
			foreach ( $payment_data as $key => $value ) {
				if ( ! preg_match( '/cvc|cvv|card[-_]?code/i', (string) $key ) ) {
					continue;
				}
				$digits = preg_replace( '/\D+/', '', (string) $value );
				if ( strlen( $digits ) < 3 ) {
					throw new Exception( __( 'Enter the card CVC in the Payment section, then place the order.', 'manual-phone-orders' ) );
				}
			}
			foreach ( $payment_data as $key => $value ) {
				if ( ! preg_match( '/expir/i', (string) $key ) ) {
					continue;
				}
				if ( ! preg_match( '/\d{1,2}\s*\/\s*\d{2,4}/', (string) $value ) ) {
					throw new Exception( __( 'Enter the card expiry in the Payment section, then place the order.', 'manual-phone-orders' ) );
				}
			}
		}
	}

	/**
	 * Customer id during create_order.
	 *
	 * @var int
	 */
	private static $placing_customer_id = 0;

	/**
	 * Filter customer id.
	 *
	 * @return int
	 */
	public static function checkout_customer_id() {
		return (int) self::$placing_customer_id;
	}

	/**
	 * Search past orders (HPOS-safe).
	 *
	 * @param string $term  Query.
	 * @param int    $limit Limit.
	 * @return array
	 */
	public static function search_orders( $term, $limit = 20 ) {
		$term  = trim( (string) $term );
		$limit = max( 1, min( 50, (int) $limit ) );
		$args  = array(
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => 'shop_order',
			'return'  => 'objects',
		);

		if ( is_numeric( $term ) ) {
			$args['id'] = (int) $term;
		} elseif ( $term ) {
			$args['s'] = $term;
		}

		$orders = wc_get_orders( $args );
		$out    = array();

		foreach ( $orders as $order ) {
			if ( ! is_a( $order, 'WC_Order' ) ) {
				continue;
			}
			if ( $order->get_type() !== 'shop_order' ) {
				continue;
			}
			$out[] = array(
				'id'         => $order->get_id(),
				'number'     => $order->get_order_number(),
				'status'     => $order->get_status(),
				'total_html' => $order->get_formatted_order_total(),
				'date'       => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '',
				'customer'   => trim( $order->get_formatted_billing_full_name() ),
				'email'      => $order->get_billing_email(),
				'item_count' => $order->get_item_count(),
			);
		}

		return $out;
	}
}
