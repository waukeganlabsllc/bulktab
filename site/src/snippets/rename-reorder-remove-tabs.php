<?php
// phpcs:ignoreFile -- example code for the blog, not part of the plugin.
/**
 * Rename, reorder and remove the default WooCommerce product tabs.
 */
add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
	// Rename: "Description" becomes "Details".
	if ( isset( $tabs['description'] ) ) {
		$tabs['description']['title'] = __( 'Details', 'your-textdomain' );
	}

	// Reorder: show Reviews first (lower priority = further left).
	if ( isset( $tabs['reviews'] ) ) {
		$tabs['reviews']['priority'] = 5;
	}

	// Remove: hide the Additional information tab.
	unset( $tabs['additional_information'] );

	return $tabs;
}, 98 );
