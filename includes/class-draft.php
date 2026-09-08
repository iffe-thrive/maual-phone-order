<?php
/**
 * Phone-order draft (isolated cart session).
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * In-memory draft model.
 */
class MPO_Draft {

	/**
	 * Database id.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Public uuid.
	 *
	 * @var string
	 */
	public $uuid = '';

	/**
	 * Operator user id.
	 *
	 * @var int
	 */
	public $operator_id = 0;

	/**
	 * Customer user id (0 = guest).
	 *
	 * @var int
	 */
	public $customer_id = 0;

	/**
	 * Status: active, held, completed, abandoned.
	 *
	 * @var string
	 */
	public $status = 'active';

	/**
	 * WooCommerce session payload.
	 *
	 * @var array
	 */
	public $session_data = array();

	/**
	 * Extra plugin meta (fees, custom shipping, payment, addresses).
	 *
	 * @var array
	 */
	public $meta = array();

	/**
	 * Operator notes.
	 *
	 * @var string
	 */
	public $notes = '';

	/**
	 * Created at.
	 *
	 * @var string
	 */
	public $created_at = '';

	/**
	 * Updated at.
	 *
	 * @var string
	 */
	public $updated_at = '';

	/**
	 * Hydrate from a database row.
	 *
	 * @param object $row Row.
	 * @return self
	 */
	public static function from_row( $row ) {
		$draft               = new self();
		$draft->id           = (int) $row->id;
		$draft->uuid         = (string) $row->uuid;
		$draft->operator_id  = (int) $row->operator_id;
		$draft->customer_id  = (int) $row->customer_id;
		$draft->status       = (string) $row->status;
		$draft->session_data = self::decode( $row->session_data );
		$draft->meta         = self::decode( $row->meta );
		$draft->notes        = (string) $row->notes;
		$draft->created_at   = (string) $row->created_at;
		$draft->updated_at   = (string) $row->updated_at;
		return $draft;
	}

	/**
	 * JSON decode helper.
	 *
	 * @param mixed $value Raw.
	 * @return array
	 */
	private static function decode( $value ) {
		if ( empty( $value ) ) {
			return array();
		}
		if ( is_array( $value ) ) {
			return $value;
		}
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get a meta value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get_meta( $key, $default = null ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : $default;
	}

	/**
	 * Set a meta value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function set_meta( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
}
