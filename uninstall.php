<?php
/**
 * Uninstall handler.
 *
 * @package ManualPhoneOrders
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$table = $wpdb->prefix . 'mpo_sessions';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_option( 'mpo_db_version' );
delete_option( 'mpo_settings' );

$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'mpo_create_orders' );
}

$role = get_role( 'shop_manager' );
if ( $role ) {
	$role->remove_cap( 'mpo_create_orders' );
}
