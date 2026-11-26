<?php
// phpcs:ignoreFile -- example code for the blog, not part of the plugin.
/**
 * Add a "Downloads" tab with PDF links from the product's
 * `product_documents` custom field. One document per line:
 *   Assembly manual | https://your-store/wp-content/uploads/manual.pdf
 * A line with only a URL uses the file name as the link text.
 * Products with an empty field get no tab.
 */
add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return $tabs;
	}

	$links = array();
	$lines = preg_split( '/\R/', (string) $product->get_meta( 'product_documents' ) );
	foreach ( $lines as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		$url   = end( $parts );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			continue; // Skip empty lines and lines without a full URL.
		}
		$label   = ( count( $parts ) > 1 && '' !== $parts[0] ) ? $parts[0] : wp_basename( $url );
		$links[] = array( 'label' => $label, 'url' => $url );
	}
	if ( ! $links ) {
		return $tabs;
	}

	$tabs['downloads'] = array(
		'title'    => __( 'Downloads', 'your-textdomain' ),
		'priority' => 25,
		'callback' => function () use ( $links ) {
			echo '<h2>' . esc_html__( 'Downloads', 'your-textdomain' ) . '</h2>';
			echo '<ul class="product-downloads">';
			foreach ( $links as $link ) {
				printf(
					'<li><a href="%s" download>%s</a></li>',
					esc_url( $link['url'] ),
					esc_html( $link['label'] )
				);
			}
			echo '</ul>';
		},
	);
	return $tabs;
} );
