<?php
// phpcs:ignoreFile -- example code for the blog, not part of the plugin.
/**
 * Add a "Shipping" tab to every WooCommerce product page.
 */
add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
	$tabs['shipping_info'] = array(
		'title'    => __( 'Shipping', 'your-textdomain' ),
		'priority' => 25, // Description is 10, Additional information 20, Reviews 30.
		'callback' => function () {
			echo '<h2>' . esc_html__( 'Shipping', 'your-textdomain' ) . '</h2>';
			echo '<p>' . esc_html__( 'Orders ship within 2 working days. Free returns within 30 days.', 'your-textdomain' ) . '</p>';
		},
	);
	return $tabs;
} );
