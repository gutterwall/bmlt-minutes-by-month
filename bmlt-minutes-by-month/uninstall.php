<?php
/**
 * Deleting the plugin removes what it kept: the HTML of documents [bmlt_minutes_latest] converted (transients named
 * bmbm_doc_..., on every site of a network). It has no settings, and the minutes belong to BMLT Minutes.
 *
 * @version 1.2.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes this site's converted documents.
 */
function bmlt_minutes_by_month_uninstall_site(): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients have no "delete by prefix" API; this runs once, on uninstall.
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_bmbm_doc_' ) . '%' ) );
	foreach ( $names as $name ) {
		delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $bmlt_minutes_by_month_site ) {
		switch_to_blog( (int) $bmlt_minutes_by_month_site );
		bmlt_minutes_by_month_uninstall_site();
		restore_current_blog();
	}
} else {
	bmlt_minutes_by_month_uninstall_site();
}
