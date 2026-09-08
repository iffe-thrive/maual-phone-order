<?php
/**
 * Install and upgrades.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activation / schema.
 */
class MPO_Install {

	const DB_VERSION = '1.0.0';

	/**
	 * Run on activation.
	 */
	public static function activate() {
		self::create_tables();
		self::add_caps();
		update_option( 'mpo_db_version', self::DB_VERSION );
	}

	/**
	 * Run on deactivation.
	 */
	public static function deactivate() {
		// Caps and table are kept so held carts survive reactivation.
	}

	/**
	 * Upgrade schema if needed.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'mpo_db_version' );
		if ( $installed !== self::DB_VERSION ) {
			self::create_tables();
			self::add_caps();
			update_option( 'mpo_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Create custom tables.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'mpo_sessions';
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			operator_id bigint(20) unsigned NOT NULL,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			session_data longtext NULL,
			meta longtext NULL,
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY operator_status (operator_id, status),
			KEY customer_id (customer_id)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Grant plugin capability.
	 */
	public static function add_caps() {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( 'mpo_create_orders' );
			}
		}
	}
}
