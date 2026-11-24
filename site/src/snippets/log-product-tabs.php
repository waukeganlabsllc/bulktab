<?php
// phpcs:ignoreFile -- example code for the blog, not part of the plugin.
/**
 * Debug: log which tabs are left after every plugin and theme has run.
 * View a product page while logged in as an admin, then check wp-content/debug.log
 * (needs WP_DEBUG and WP_DEBUG_LOG set to true). Remove when done.
 */
add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
	if ( current_user_can( 'manage_options' ) ) {
		$summary = array();
		foreach ( $tabs as $key => $tab ) {
			$summary[] = $key . ' (' . ( $tab['priority'] ?? '?' ) . ')';
		}
		error_log( 'Product tabs on ' . get_the_ID() . ': ' . implode( ', ', $summary ) );
	}
	return $tabs;
}, 9999 );
