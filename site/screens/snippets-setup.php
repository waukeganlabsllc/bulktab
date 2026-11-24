<?php
/**
 * Store for testing the blog's woocommerce_product_tabs snippets (WooCommerce only,
 * no tab plugin). The snippets are mounted as mu-plugins. Never shipped.
 */

// phpcs:ignoreFile -- dev-only fixture.

update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
$shoes = wp_insert_term( 'Shoes', 'product_cat', array( 'slug' => 'shoes' ) );
$boots = wp_insert_term( 'Boots', 'product_cat', array( 'parent' => $shoes['term_id'] ) );
$tees  = wp_insert_term( 'T-shirts', 'product_cat' );
$ids   = array();
foreach ( array( 'Trail Boot' => $boots['term_id'], 'Crew Tee' => $tees['term_id'] ) as $name => $cat ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$p->set_regular_price( '50' );
	$p->set_description( 'About the ' . $name . '.' );
	$p->set_weight( '1' );
	$p->set_reviews_allowed( true );
	$p->set_category_ids( array( $cat ) );
	$ids[ $name ] = $p->save();
}
file_put_contents( '/screens-out/snippet-ids.json', wp_json_encode( $ids ) );
