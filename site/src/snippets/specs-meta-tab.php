<?php
// phpcs:ignoreFile -- example code for the blog, not part of the plugin.
/**
 * Add a "Specifications" tab built from the product's `specs` custom field.
 * Fill the field from a CSV column named `meta:specs`, or map it in an importer.
 * Products with an empty field get no tab.
 */
add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return $tabs;
	}

	$specs = (string) $product->get_meta( 'specs' );
	if ( '' === trim( $specs ) ) {
		return $tabs;
	}

	$tabs['specs'] = array(
		'title'    => __( 'Specifications', 'your-textdomain' ),
		'priority' => 25,
		'callback' => function () use ( $specs ) {
			echo '<h2>' . esc_html__( 'Specifications', 'your-textdomain' ) . '</h2>';
			echo wp_kses_post( wpautop( $specs ) );
		},
	);
	return $tabs;
} );
