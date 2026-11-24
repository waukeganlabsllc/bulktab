<?php
/**
 * Screenshot fixture: builds the fictional "Harbor Outfitters" store inside
 * WordPress Playground for the marketing-site screenshots. Never shipped.
 * Writes IDs to /screens-out/ids.json and a real WooCommerce CSV export to
 * /screens-out/export.csv.
 *
 * @package BulkTab
 */

// phpcs:ignoreFile -- dev-only fixture for site screenshots.

wp_set_current_user( 1 );

update_option( 'blogname', 'Harbor Outfitters' );
update_option( 'blogdescription', 'Clothing, shoes and gear for the coast' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_weight_unit', 'kg' );
update_option( 'woocommerce_dimension_unit', 'cm' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true, 'completed' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_allow_tracking', 'no' );
update_option( 'woocommerce_admin_disabled', 'no' );

$out = array();

$term = static function ( $name, $tax, $parent = 0 ) {
	$t = wp_insert_term( $name, $tax, $parent ? array( 'parent' => $parent ) : array() );
	return is_wp_error( $t ) ? (int) $t->get_error_data( 'term_exists' ) : (int) $t['term_id'];
};

$apparel     = $term( 'Apparel', 'product_cat' );
$tshirts     = $term( 'T-shirts', 'product_cat', $apparel );
$hoodies     = $term( 'Hoodies', 'product_cat', $apparel );
$shoes       = $term( 'Shoes', 'product_cat' );
$boots       = $term( 'Boots', 'product_cat', $shoes );
$sneakers    = $term( 'Sneakers', 'product_cat', $shoes );
$accessories = $term( 'Accessories', 'product_cat' );
$sale        = $term( 'Sale', 'product_tag' );
$new         = $term( 'New', 'product_tag' );
$brands      = taxonomy_exists( 'product_brand' );
$northfield  = $brands ? $term( 'Northfield', 'product_brand' ) : 0;
$coastline   = $brands ? $term( 'Coastline', 'product_brand' ) : 0;

$out['terms'] = compact( 'apparel', 'tshirts', 'hoodies', 'shoes', 'boots', 'sneakers', 'accessories', 'sale', 'new', 'northfield', 'coastline' );

// name, category, price, sale price, tags, brand, weight kg, short description.
$catalog = array(
	array( 'Harbor Crew Tee', $tshirts, '28', '', array( $new ), $coastline, '0.2', 'A midweight cotton crew neck with a relaxed fit.' ),
	array( 'Pocket Tee', $tshirts, '30', '', array(), $coastline, '0.2', 'Garment-dyed cotton jersey with a chest pocket.' ),
	array( 'Long Sleeve Henley', $tshirts, '42', '34', array( $sale ), $coastline, '0.3', 'Three-button henley in soft slub cotton.' ),
	array( 'Striped Breton Tee', $tshirts, '38', '', array(), $coastline, '0.25', 'Classic boat-neck stripe in heavyweight cotton.' ),
	array( 'Organic Cotton V-Neck', $tshirts, '26', '', array( $new ), $coastline, '0.2', 'Lightweight organic cotton with a clean V-neck.' ),
	array( 'Slub Jersey Tee', $tshirts, '32', '24', array( $sale ), $coastline, '0.2', 'Textured slub yarn for a lived-in feel.' ),
	array( 'Raglan Baseball Tee', $tshirts, '34', '', array(), $coastline, '0.25', 'Three-quarter raglan sleeves in contrast colours.' ),
	array( 'Fleece Pullover Hoodie', $hoodies, '68', '', array(), $northfield, '0.6', 'Brushed-back fleece with a kangaroo pocket.' ),
	array( 'Full-Zip Hoodie', $hoodies, '74', '', array( $new ), $northfield, '0.65', 'Midweight full-zip with ribbed cuffs and hem.' ),
	array( 'Heavyweight Hoodie', $hoodies, '84', '69', array( $sale ), $northfield, '0.8', 'A 450 gsm loopback cotton hoodie built for cold mornings.' ),
	array( 'Waffle Knit Hoodie', $hoodies, '72', '', array(), $northfield, '0.55', 'Thermal waffle knit that layers well.' ),
	array( 'Sherpa-Lined Hoodie', $hoodies, '96', '', array( $new ), $northfield, '0.9', 'Fully sherpa-lined body for real warmth.' ),
	array( 'Trail Boot', $boots, '149', '', array( $new ), $northfield, '1.4', 'A waterproof leather hiking boot with a grippy lugged sole.' ),
	array( 'Chelsea Boot', $boots, '139', '', array(), $northfield, '1.2', 'Pull-on suede Chelsea boot with elastic side panels.' ),
	array( 'Waterproof Hiker', $boots, '129', '99', array( $sale ), $northfield, '1.3', 'Seam-sealed mid-height hiker for wet trails.' ),
	array( 'Work Boot', $boots, '159', '', array(), $northfield, '1.6', 'Full-grain leather work boot with a steel shank.' ),
	array( 'Winter Snow Boot', $boots, '169', '', array( $new ), $northfield, '1.7', 'Insulated snow boot rated for cold, wet days.' ),
	array( 'Leather Lace-Up Boot', $boots, '145', '119', array( $sale ), $northfield, '1.3', 'Goodyear-welted lace-up boot that can be resoled.' ),
	array( 'Canvas Low-Top', $sneakers, '58', '', array(), $coastline, '0.7', 'Everyday canvas sneaker with a vulcanised sole.' ),
	array( 'Court Sneaker', $sneakers, '89', '', array( $new ), $coastline, '0.8', 'Clean leather court shoe with a cupsole.' ),
	array( 'Trail Runner', $sneakers, '119', '', array(), $coastline, '0.6', 'Lightweight trail shoe with a rock plate.' ),
	array( 'Slip-On Sneaker', $sneakers, '54', '42', array( $sale ), $coastline, '0.6', 'Easy slip-on canvas with a padded collar.' ),
	array( 'Knit Running Shoe', $sneakers, '109', '', array(), $coastline, '0.5', 'Breathable knit upper with a cushioned midsole.' ),
	array( 'Retro Jogger', $sneakers, '95', '76', array( $sale ), $coastline, '0.7', 'Suede and mesh runner in a 1980s shape.' ),
	array( 'Wool Beanie', $accessories, '24', '', array(), $coastline, '0.1', 'Ribbed merino blend beanie.' ),
	array( 'Bucket Hat', $accessories, '32', '', array( $new ), $coastline, '0.1', 'Washed cotton twill bucket hat.' ),
	array( 'Baseball Cap', $accessories, '26', '', array(), $coastline, '0.1', 'Six-panel cap with an adjustable strap.' ),
	array( 'Canvas Tote', $accessories, '36', '', array(), $coastline, '0.4', 'Heavy canvas tote with an inside pocket.' ),
	array( 'Leather Belt', $accessories, '48', '', array(), $northfield, '0.3', 'Vegetable-tanned leather with a brass buckle.' ),
	array( 'Merino Socks 3-Pack', $accessories, '30', '', array(), $northfield, '0.2', 'Cushioned merino hiking socks, three pairs.' ),
	array( 'Wool Scarf', $accessories, '45', '36', array( $sale ), $northfield, '0.2', 'Lambswool scarf in a herringbone weave.' ),
	array( 'Waxed Canvas Backpack', $accessories, '128', '', array( $new ), $northfield, '1.1', 'Waxed cotton canvas backpack with leather trim.' ),
	array( 'Knit Gloves', $accessories, '22', '', array(), $coastline, '0.1', 'Touchscreen-friendly knit gloves.' ),
	array( 'Insulated Water Bottle', $accessories, '34', '', array(), $coastline, '0.4', 'Double-wall steel bottle, 750 ml.' ),
	array( 'Rain Shell Jacket', $apparel, '118', '', array( $new ), $coastline, '0.4', 'Packable waterproof shell with taped seams.' ),
	array( 'Quilted Vest', $apparel, '88', '70', array( $sale ), $northfield, '0.4', 'Lightly insulated quilted vest.' ),
);

$ids = array();
foreach ( $catalog as $row ) {
	list( $name, $cat, $price, $sale_price, $tags, $brand, $weight, $short ) = $row;
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$p->set_regular_price( $price );
	if ( '' !== $sale_price ) {
		$p->set_sale_price( $sale_price );
	}
	$p->set_sku( 'HO-' . strtoupper( substr( md5( $name ), 0, 6 ) ) );
	$p->set_short_description( $short );
	$p->set_description( '<p>' . $short . ' Designed in our harbour-side workshop and tested on the coast path before it goes on sale.</p>' );
	$p->set_category_ids( array( $cat ) );
	$p->set_tag_ids( $tags );
	$p->set_weight( $weight );
	$p->set_manage_stock( false );
	$p->set_stock_status( 'instock' );
	$id = $p->save();
	if ( $brand ) {
		wp_set_object_terms( $id, array( (int) $brand ), 'product_brand' );
	}
	$ids[ $name ] = $id;
}
$out['products'] = $ids;

$shared = static function ( $title, $content, $rules, $order ) {
	$id = wp_insert_post(
		array(
			'post_type'    => 'bulktab_tab',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
			'menu_order'   => $order,
		)
	);
	update_post_meta( $id, '_bulktab_rules', wp_slash( wp_json_encode( $rules ) ) );
	return array( 'id' => $id, 'key' => (string) get_post_meta( $id, '_bulktab_key', true ) );
};
$none = array( 'v' => 1, 'all' => false, 'groups' => array(), 'include' => array(), 'exclude' => array() );
$one  = static function ( $tax, $terms ) use ( $none ) {
	return array_merge( $none, array( 'groups' => array( array( array( 'tax' => $tax, 'terms' => $terms, 'not' => false ) ) ) ) );
};

$shoe_table = '<p>Our boots and sneakers are made on a standard last. If you are between sizes, choose the larger size.</p>
<table>
<thead><tr><th>EU</th><th>US men</th><th>US women</th><th>UK</th><th>Foot length</th></tr></thead>
<tbody>
<tr><td>38</td><td>5.5</td><td>7.5</td><td>5</td><td>24.0 cm</td></tr>
<tr><td>39</td><td>6.5</td><td>8.5</td><td>6</td><td>24.6 cm</td></tr>
<tr><td>40</td><td>7</td><td>9</td><td>6.5</td><td>25.3 cm</td></tr>
<tr><td>41</td><td>8</td><td>10</td><td>7.5</td><td>26.0 cm</td></tr>
<tr><td>42</td><td>8.5</td><td>10.5</td><td>8</td><td>26.6 cm</td></tr>
<tr><td>43</td><td>9.5</td><td>11.5</td><td>9</td><td>27.3 cm</td></tr>
<tr><td>44</td><td>10.5</td><td>12.5</td><td>9.5</td><td>28.0 cm</td></tr>
<tr><td>45</td><td>11</td><td>13</td><td>10.5</td><td>28.6 cm</td></tr>
</tbody>
</table>
<p>Measure your foot from heel to longest toe, standing, at the end of the day.</p>';

$apparel_table = '<table>
<thead><tr><th>Size</th><th>Chest</th><th>Waist</th><th>Sleeve</th></tr></thead>
<tbody>
<tr><td>XS</td><td>86–91 cm</td><td>71–76 cm</td><td>81 cm</td></tr>
<tr><td>S</td><td>91–96 cm</td><td>76–81 cm</td><td>83 cm</td></tr>
<tr><td>M</td><td>96–102 cm</td><td>81–86 cm</td><td>85 cm</td></tr>
<tr><td>L</td><td>102–107 cm</td><td>86–91 cm</td><td>87 cm</td></tr>
<tr><td>XL</td><td>107–112 cm</td><td>91–97 cm</td><td>89 cm</td></tr>
<tr><td>XXL</td><td>112–117 cm</td><td>97–102 cm</td><td>91 cm</td></tr>
</tbody>
</table>
<p>Measurements are of your body, not the garment. Our tees and hoodies have a relaxed fit.</p>';

$tabs = array(
	'shipping' => $shared( 'Shipping & returns', '<p><strong>Free standard shipping</strong> on orders over $75. Orders placed before 2 pm ship the same working day.</p><ul><li>Standard: 3–5 working days</li><li>Express: 1–2 working days</li></ul><p>Not quite right? Return unworn items within 30 days for a full refund. Start a return from your account page.</p>', array_merge( $none, array( 'all' => true ) ), 1 ),
	'shoes'    => $shared( 'Size guide – shoes', $shoe_table, $one( 'product_cat', array( $shoes ) ), 2 ),
	'apparel'  => $shared( 'Size guide – apparel', $apparel_table, $one( 'product_cat', array( $apparel ) ), 3 ),
	'care'     => $shared( 'Care instructions', '<ul><li>Machine wash cold, inside out, with similar colours.</li><li>Do not tumble dry; dry flat in the shade.</li><li>Cool iron on the reverse if needed.</li></ul>', $one( 'product_cat', array( $apparel ) ), 4 ),
	'warranty' => $shared( 'Warranty', '<p>Every Northfield product carries a <strong>two-year warranty</strong> against defects in materials and workmanship. Keep your order number and contact us with a photo of the problem.</p>', $brands ? $one( 'product_brand', array( $northfield ) ) : $none, 5 ),
	'giftwrap' => $shared( 'Gift wrap', '<p>Add recycled-paper gift wrap and a handwritten card at checkout for $5.</p>', $none, 6 ),
);
$out['tabs'] = $tabs;

BulkTab\RulesIndex::rebuild();

$store = new BulkTab\TabStore( new BulkTab\WpMetaStore() );

// Trail Boot: own "Materials" tab, Gift wrap attached, Additional information hidden on this product.
$boot = $ids['Trail Boot'];
$store->save_tabs(
	$boot,
	array(
		1 => array(
			'title'   => 'Materials',
			'content' => '<ul><li>Upper: full-grain waxed leather</li><li>Lining: waterproof, breathable membrane</li><li>Midsole: EVA with nylon shank</li><li>Outsole: 5 mm lugged rubber</li></ul>',
		),
	)
);
$store->set_list( $boot, '_bulktab_attach', array( $tabs['giftwrap']['key'] ) );
$store->set_list( $boot, '_bulktab_hide', array( 'additional_information' ) );

// Hat: Gift wrap attached, Shipping hidden on this product only.
$hat = $ids['Wool Beanie'];
$store->set_list( $hat, '_bulktab_attach', array( $tabs['giftwrap']['key'] ) );
$store->set_list( $hat, '_bulktab_hide', array( 's:' . $tabs['shipping']['key'] ) );

// Own "Fabric" tabs on tees and hoodies (used by the Pro bulk replace screenshot).
foreach ( $catalog as $row ) {
	if ( in_array( $row[1], array( $tshirts, $hoodies ), true ) ) {
		$store->save_tabs( $ids[ $row[0] ], array( 1 => array( 'title' => 'Fabric', 'content' => '<p>100% cotton. Pre-washed to reduce shrinkage. Machine wash at 40°C.</p>' ) ) );
	}
}

// Store-wide layout: Description renamed "Details", Reviews hidden.
update_option(
	'bulktab_layout',
	array(
		'description' => array( 'title' => 'Details' ),
		'reviews'     => array( 'hide' => true ),
	)
);

// Real WooCommerce CSV export of the Trail Boot (header used as text on the site).
require_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
$exporter = new class() extends WC_Product_CSV_Exporter {
	public function csv(): string {
		$this->prepare_data_to_export();
		return $this->export_column_headers() . $this->get_csv_data();
	}
};
$exporter->set_product_ids_to_export( array( $boot ) );
file_put_contents( '/screens-out/export.csv', $exporter->csv() );

file_put_contents( '/screens-out/ids.json', wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
