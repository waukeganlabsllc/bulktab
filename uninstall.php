<?php
/**
 * Uninstall: deletes data ONLY when the site opted in via the
 * `bulktab_delete_data_on_uninstall` option. Otherwise tabs are kept, so
 * reinstalling (or a mistaken uninstall) loses nothing.
 *
 * @package BulkTab
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete this site's BulkTab data if the site opted in.
 */
function bulktab_uninstall_site() {
	global $wpdb;

	// Scheduled jobs and per-user notice dismissals are always removed; tab data only on opt-in.
	wp_clear_scheduled_hook( 'bulktab_health_scan' );
	delete_metadata( 'user', 0, 'bulktab_dismissed_notices', '', true );

	if ( ! get_option( 'bulktab_delete_data_on_uninstall' ) ) {
		return;
	}

	// Shared tabs (any status) and their revisions. The post type is not registered here, so query directly.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'bulktab_tab' ) );
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}

	// Product tab meta and any leftover BulkTab meta.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_bulktab_' ) . '%' ) );

	// Options (bulktab_*) and transients (e.g. queued notices).
	$patterns = array(
		$wpdb->esc_like( 'bulktab_' ) . '%',
		$wpdb->esc_like( '_transient_bulktab_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_bulktab_' ) . '%',
	);
	foreach ( $patterns as $pattern ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}

	wp_cache_flush();
}

if ( is_multisite() ) {
	$bulktab_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $bulktab_site_ids as $bulktab_site_id ) {
		switch_to_blog( (int) $bulktab_site_id );
		bulktab_uninstall_site();
		restore_current_blog();
	}
} else {
	bulktab_uninstall_site();
}
