<?php
/**
 * Isolated WooCommerce session that never touches cookies or the storefront cart.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Session backed by wp_mpo_sessions instead of wp_woocommerce_sessions.
 */
class MPO_Isolated_Session extends WC_Session_Handler {

	/**
	 * Draft uuid.
	 *
	 * @var string
	 */
	protected $mpo_uuid = '';

	/**
	 * Repository handle used on shutdown.
	 *
	 * @var MPO_Draft
	 */
	protected $mpo_draft;

	/**
	 * Constructor.
	 *
	 * @param MPO_Draft $draft Draft.
	 */
	public function __construct( MPO_Draft $draft ) {
		parent::__construct();
		$this->mpo_draft         = $draft;
		$this->mpo_uuid          = $draft->uuid;
		$this->_data             = is_array( $draft->session_data ) ? $draft->session_data : array();
		$this->_has_cookie       = true;
		$this->_customer_id      = 'mpo_' . $draft->uuid;
		$this->set_session_expiration();
	}

	/**
	 * Do not read cookies or wp_woocommerce_sessions.
	 */
	public function init() {
		add_action( 'shutdown', array( $this, 'save_data' ), 20 );
	}

	/**
	 * Always treat this as a live session.
	 *
	 * @return bool
	 */
	public function has_session() {
		return true;
	}

	/**
	 * Never read the browser cookie.
	 *
	 * @return array|false
	 */
	public function get_session_cookie() {
		return false;
	}

	/**
	 * Never write a WooCommerce session cookie (would collide with the operator's storefront).
	 *
	 * @param bool $set Unused.
	 */
	public function set_customer_session_cookie( $set = true ) {
		// Intentionally empty.
	}

	/**
	 * Persist into our table only.
	 *
	 * @param int $old_session_key Unused.
	 */
	public function save_data( $old_session_key = 0 ) {
		if ( ! $this->_dirty ) {
			return;
		}

		$this->mpo_draft->session_data = $this->_data;
		MPO_Draft_Repository::update_session_data( $this->mpo_uuid, $this->_data );
		$this->_dirty = false;
	}

	/**
	 * Return in-memory data instead of querying wp_woocommerce_sessions.
	 *
	 * @param string $customer_id Unused.
	 * @param mixed  $default     Default.
	 * @return array|mixed
	 */
	public function get_session( $customer_id, $default = false ) {
		return ! empty( $this->_data ) ? $this->_data : $default;
	}

	/**
	 * Skip timestamp updates on the core sessions table.
	 *
	 * @param string $customer_id Unused.
	 * @param int    $timestamp   Unused.
	 */
	public function update_session_timestamp( $customer_id, $timestamp ) {
		// Intentionally empty.
	}

	/**
	 * Empty in-memory session without touching storefront data.
	 */
	public function destroy_session() {
		$this->_data  = array();
		$this->_dirty = true;
		$this->save_data();
	}

	/**
	 * Forget session without cookies.
	 */
	public function forget_session() {
		$this->_data  = array();
		$this->_dirty = true;
		wc_empty_cart();
	}

	/**
	 * Do not clean core session rows.
	 */
	public function cleanup_sessions() {
		// Intentionally empty.
	}

	/**
	 * Full session payload.
	 *
	 * @return array
	 */
	public function get_session_data() {
		return is_array( $this->_data ) ? $this->_data : array();
	}
}
