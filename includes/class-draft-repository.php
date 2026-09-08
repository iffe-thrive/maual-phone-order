<?php
/**
 * Draft persistence.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for isolated cart drafts.
 */
class MPO_Draft_Repository {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mpo_sessions';
	}

	/**
	 * Create a new draft for the current operator.
	 *
	 * @param int $operator_id Operator.
	 * @param int $customer_id Customer.
	 * @return MPO_Draft
	 */
	public static function create( $operator_id, $customer_id = 0 ) {
		global $wpdb;

		$now   = current_time( 'mysql' );
		$uuid  = wp_generate_uuid4();
		$draft = new MPO_Draft();

		$draft->uuid        = $uuid;
		$draft->operator_id = (int) $operator_id;
		$draft->customer_id = (int) $customer_id;
		$draft->status      = 'active';
		$draft->created_at  = $now;
		$draft->updated_at  = $now;

		$wpdb->insert(
			self::table(),
			array(
				'uuid'         => $draft->uuid,
				'operator_id'  => $draft->operator_id,
				'customer_id'  => $draft->customer_id,
				'status'       => $draft->status,
				'session_data' => wp_json_encode( array() ),
				'meta'         => wp_json_encode( array() ),
				'notes'        => '',
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$draft->id = (int) $wpdb->insert_id;
		return $draft;
	}

	/**
	 * Find by uuid.
	 *
	 * @param string $uuid UUID.
	 * @return MPO_Draft|null
	 */
	public static function get_by_uuid( $uuid ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE uuid = %s LIMIT 1',
				$uuid
			)
		);

		return $row ? MPO_Draft::from_row( $row ) : null;
	}

	/**
	 * Active draft for this operator (most recently updated).
	 *
	 * @param int $operator_id Operator.
	 * @return MPO_Draft|null
	 */
	public static function get_active_for_operator( $operator_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE operator_id = %d AND status = %s ORDER BY updated_at DESC LIMIT 1',
				$operator_id,
				'active'
			)
		);

		return $row ? MPO_Draft::from_row( $row ) : null;
	}

	/**
	 * Active draft for operator + customer pair.
	 *
	 * @param int $operator_id Operator.
	 * @param int $customer_id Customer.
	 * @return MPO_Draft|null
	 */
	public static function get_active_for_pair( $operator_id, $customer_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE operator_id = %d AND customer_id = %d AND status = %s ORDER BY updated_at DESC LIMIT 1',
				$operator_id,
				$customer_id,
				'active'
			)
		);

		return $row ? MPO_Draft::from_row( $row ) : null;
	}

	/**
	 * Held drafts for an operator.
	 *
	 * @param int $operator_id Operator.
	 * @return MPO_Draft[]
	 */
	public static function get_held_for_operator( $operator_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE operator_id = %d AND status = %s ORDER BY updated_at DESC LIMIT 50',
				$operator_id,
				'held'
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = MPO_Draft::from_row( $row );
		}
		return $out;
	}

	/**
	 * Persist draft fields.
	 *
	 * @param MPO_Draft $draft Draft.
	 */
	public static function save( MPO_Draft $draft ) {
		global $wpdb;

		$draft->updated_at = current_time( 'mysql' );

		$wpdb->update(
			self::table(),
			array(
				'customer_id'  => (int) $draft->customer_id,
				'status'       => $draft->status,
				'session_data' => wp_json_encode( $draft->session_data ? $draft->session_data : new stdClass() ),
				'meta'         => wp_json_encode( $draft->meta ? $draft->meta : new stdClass() ),
				'notes'        => $draft->notes,
				'updated_at'   => $draft->updated_at,
			),
			array( 'id' => (int) $draft->id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Update only session payload (called from isolated session).
	 *
	 * @param string $uuid UUID.
	 * @param array  $data Session data.
	 */
	public static function update_session_data( $uuid, array $data ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'session_data' => wp_json_encode( $data ? $data : new stdClass() ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'uuid' => $uuid ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}
}
