<?php
/**
 * Shipping method class (loaded on woocommerce_shipping_init).
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manual rate used when the operator types a custom shipping amount.
 */
class MPO_WC_Shipping_Manual extends WC_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Instance.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'mpo_manual';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Phone order manual shipping', 'manual-phone-orders' );
		$this->method_description = __( 'Used only on the Manual Phone Orders screen when a custom shipping price is entered.', 'manual-phone-orders' );
		$this->supports           = array();
		$this->enabled            = 'yes';
		$this->title              = __( 'Manual shipping', 'manual-phone-orders' );
		$this->init();
	}

	/**
	 * Init.
	 */
	public function init() {
		$this->init_form_fields();
		$this->init_settings();
	}

	/**
	 * Only available inside phone-order context.
	 *
	 * @param array $package Package.
	 * @return bool
	 */
	public function is_available( $package ) {
		return mpo_doing_context();
	}

	/**
	 * Rate.
	 *
	 * @param array $package Package.
	 */
	public function calculate_shipping( $package = array() ) {
		$stored = WC()->session ? WC()->session->get( 'mpo_custom_shipping', null ) : null;
		if ( null === $stored || '' === $stored ) {
			return;
		}

		$this->add_rate(
			array(
				'id'    => $this->id,
				'label' => $this->title,
				'cost'  => (float) $stored,
			)
		);
	}
}
