<?php
/**
 * Uninstall: removes data only when "Delete all data on uninstall" is enabled in Settings > General.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'magstack_coaches_settings', array() );
if ( empty( $settings['uninstall_cleanup'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'magstack_coach', 'magstack_facility' ) as $type ) {
	$ids = get_posts(
		array(
			'post_type'      => $type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
}

foreach ( array( 'mc_bookings', 'mc_packages', 'mc_package_purchases' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB
}

// Coach users lose the role; accounts themselves are kept.
$coach_users = get_users( array( 'role' => 'magstack_coach', 'fields' => 'ID' ) );
foreach ( $coach_users as $uid ) {
	$u = new WP_User( $uid );
	$u->remove_role( 'magstack_coach' );
}
remove_role( 'magstack_coach' );
$admin = get_role( 'administrator' );
if ( $admin ) {
	$admin->remove_cap( 'manage_magstack_coaches' );
}

delete_option( 'magstack_coaches_settings' );
delete_option( 'magstack_coaches_db_version' );
delete_transient( 'magstack_cities' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_mc\_rl\_%' OR option_name LIKE '\_transient\_timeout\_mc\_rl\_%'" ); // phpcs:ignore WordPress.DB
wp_clear_scheduled_hook( 'magstack_coaches_reminders' );
flush_rewrite_rules();
