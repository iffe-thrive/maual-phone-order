<?php
/**
 * Customer search and creation.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customers.
 */
class MPO_Customers {

	/**
	 * Search customers by email, name, phone. Prefix-first for large user tables.
	 *
	 * @param string $term  Query.
	 * @param int    $limit Limit.
	 * @return array
	 */
	public static function search( $term, $limit = 20 ) {
		global $wpdb;

		$term  = trim( (string) $term );
		$limit = max( 1, min( 50, (int) $limit ) );

		if ( strlen( $term ) < 2 ) {
			return array();
		}

		$like_prefix = $wpdb->esc_like( $term ) . '%';
		$like_any    = '%' . $wpdb->esc_like( $term ) . '%';
		$ids         = array();

		$email_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE user_email LIKE %s OR user_login LIKE %s OR display_name LIKE %s LIMIT %d",
				$like_prefix,
				$like_prefix,
				$like_any,
				$limit
			)
		);
		foreach ( (array) $email_ids as $id ) {
			$ids[] = (int) $id;
		}

		$meta_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
				WHERE meta_key IN ('billing_phone','billing_email','billing_first_name','billing_last_name','shipping_phone')
				AND meta_value LIKE %s
				LIMIT %d",
				$like_prefix,
				$limit
			)
		);
		foreach ( (array) $meta_ids as $id ) {
			$ids[] = (int) $id;
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		$ids = array_slice( $ids, 0, $limit );

		$out = array();
		foreach ( $ids as $id ) {
			$row = self::serialize( $id );
			if ( $row ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Serialize a customer.
	 *
	 * @param int $user_id User.
	 * @return array|null
	 */
	public static function serialize( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$first = get_user_meta( $user_id, 'billing_first_name', true );
		$last  = get_user_meta( $user_id, 'billing_last_name', true );
		$name  = trim( $first . ' ' . $last );
		if ( ! $name ) {
			$name = $user->display_name;
		}

		$order_count = function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $user_id ) : 0;

		$points = null;
		try {
			$points = class_exists( 'MPO_Loyalty' ) ? MPO_Loyalty::get_points( $user_id ) : null;
		} catch ( Throwable $e ) {
			$points = null;
		}

		return array(
			'id'          => (int) $user_id,
			'name'        => $name,
			'email'       => $user->user_email,
			'username'    => $user->user_login,
			'phone'       => (string) get_user_meta( $user_id, 'billing_phone', true ),
			'roles'       => array_values( $user->roles ),
			'order_count' => $order_count,
			'city'        => (string) get_user_meta( $user_id, 'billing_city', true ),
			'country'     => (string) get_user_meta( $user_id, 'billing_country', true ),
			'points'      => $points,
		);
	}

	/**
	 * Create a customer account.
	 *
	 * @param array $data Data.
	 * @return int User id.
	 */
	public static function create( array $data ) {
		$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		if ( ! is_email( $email ) ) {
			throw new Exception( __( 'A valid email is required.', 'manual-phone-orders' ) );
		}

		$username = isset( $data['username'] ) ? sanitize_user( $data['username'] ) : '';
		if ( ! $username ) {
			$username = wc_create_new_customer_username( $email );
		}

		$password = isset( $data['password'] ) && $data['password'] ? $data['password'] : wp_generate_password( 12, true );

		$user_id = wc_create_new_customer( $email, $username, $password );
		if ( is_wp_error( $user_id ) ) {
			throw new Exception( $user_id->get_error_message() );
		}

		$map = array(
			'first_name' => 'billing_first_name',
			'last_name'  => 'billing_last_name',
			'phone'      => 'billing_phone',
			'company'    => 'billing_company',
			'address_1'  => 'billing_address_1',
			'address_2'  => 'billing_address_2',
			'city'       => 'billing_city',
			'state'      => 'billing_state',
			'postcode'   => 'billing_postcode',
			'country'    => 'billing_country',
		);

		foreach ( $map as $from => $meta_key ) {
			if ( ! empty( $data[ $from ] ) ) {
				update_user_meta( $user_id, $meta_key, wc_clean( wp_unslash( $data[ $from ] ) ) );
			}
		}

		if ( ! empty( $data['first_name'] ) ) {
			update_user_meta( $user_id, 'first_name', wc_clean( $data['first_name'] ) );
		}
		if ( ! empty( $data['last_name'] ) ) {
			update_user_meta( $user_id, 'last_name', wc_clean( $data['last_name'] ) );
		}

		$display = trim( ( isset( $data['first_name'] ) ? $data['first_name'] : '' ) . ' ' . ( isset( $data['last_name'] ) ? $data['last_name'] : '' ) );
		if ( $display ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $display,
				)
			);
		}

		update_user_meta( $user_id, 'billing_email', $email );

		return (int) $user_id;
	}

	/**
	 * Countries / states for address forms.
	 *
	 * @return array
	 */
	public static function geography() {
		$countries = WC()->countries->get_countries();
		$states    = WC()->countries->get_states();
		$base      = array(
			'country'  => WC()->countries->get_base_country(),
			'state'    => WC()->countries->get_base_state(),
		);

		return array(
			'countries' => $countries,
			'states'    => $states,
			'base'      => $base,
		);
	}
}
