<?php
// phpcs:ignoreFile -- example code for the blog, not part of the plugin.
/**
 * Add a "Size chart" tab to products in the Shoes category
 * (and its sub-categories, such as Boots and Sneakers).
 */
add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return $tabs;
	}

	$shoes = get_term_by( 'slug', 'shoes', 'product_cat' );
	if ( ! $shoes ) {
		return $tabs;
	}

	// wc_get_product_cat_ids() includes the parent categories of each category.
	if ( ! in_array( $shoes->term_id, wc_get_product_cat_ids( $product->get_id() ), true ) ) {
		return $tabs;
	}

	$tabs['size_chart'] = array(
		'title'    => __( 'Size chart', 'your-textdomain' ),
		'priority' => 15,
		'callback' => function () {
			?>
			<h2><?php esc_html_e( 'Size chart', 'your-textdomain' ); ?></h2>
			<table>
				<thead><tr><th>EU</th><th>US men</th><th>US women</th><th>UK</th></tr></thead>
				<tbody>
					<tr><td>40</td><td>7</td><td>9</td><td>6.5</td></tr>
					<tr><td>41</td><td>8</td><td>10</td><td>7.5</td></tr>
					<tr><td>42</td><td>8.5</td><td>10.5</td><td>8</td></tr>
					<tr><td>43</td><td>9.5</td><td>11.5</td><td>9</td></tr>
				</tbody>
			</table>
			<?php
		},
	);
	return $tabs;
} );
