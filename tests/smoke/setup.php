<?php
/**
 * Smoke-test fixture: builds a small store inside WordPress Playground.
 * Writes product/tab IDs to /smoke-out/ids.json for the checks.
 *
 * @package BulkTab
 */

// phpcs:ignoreFile -- dev-only test fixture, never shipped (see .distignore).

$out = array();

wp_set_current_user( 1 );

update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );

$shoes = wp_insert_term( 'Shoes', 'product_cat' );
$boots = wp_insert_term( 'Boots', 'product_cat', array( 'parent' => $shoes['term_id'] ) );
$sale  = wp_insert_term( 'Sale', 'product_tag' );

$make = static function ( $name, $cats, $tags = array() ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$p->set_regular_price( '10' );
	$p->set_description( 'Description of ' . $name );
	$p->set_category_ids( $cats );
	$p->set_tag_ids( $tags );
	return $p->save();
};

$out['boot'] = $make( 'Trail Boot', array( $boots['term_id'] ), array( $sale['term_id'] ) );
$out['hat']  = $make( 'Wool Hat', array() );

$shared = static function ( $title, $content, $rules ) {
	$id = wp_insert_post(
		array(
			'post_type'    => 'bulktab_tab',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		)
	);
	update_post_meta( $id, '_bulktab_rules', wp_slash( wp_json_encode( $rules ) ) );
	return $id;
};

$none = array( 'v' => 1, 'all' => false, 'groups' => array(), 'include' => array(), 'exclude' => array() );

// Shoes (parent category) -> must reach Boots via ancestors.
$out['size_guide'] = $shared(
	'Size guide',
	'<table><tr><td>EU 42</td><td>US 9</td></tr></table>',
	array_merge( $none, array( 'groups' => array( array( array( 'tax' => 'product_cat', 'terms' => array( $shoes['term_id'] ), 'not' => false ) ) ) ) )
);
$out['shipping']   = $shared( 'Shipping', 'Ships in 3 days.', array_merge( $none, array( 'all' => true ) ) );
// R-14: editing a shared tab once updates every product (no copies to sync).
wp_update_post( array( 'ID' => $out['shipping'], 'post_content' => 'Ships in 2 days.' ) );
$out['empty']      = $shared( 'Empty tab', "  <p>&nbsp;</p>\n", array_merge( $none, array( 'all' => true ) ) );
$out['manual']     = $shared( 'Gift wrap', 'Gift wrap available.', $none );

BulkTab\RulesIndex::rebuild();

$store = new BulkTab\TabStore( new BulkTab\WpMetaStore() );

// Backslashes, quotes, unicode, emoji, shortcode must survive.
$out['care_content'] = 'Wash at C:\\cold \\ "30°" — é 👟 [bulktab_tab key="shipping"]';
$out['errors']       = $store->save_tabs( $out['boot'], array( 0 => array( 'title' => 'Care', 'content' => $out['care_content'] ) ) );
$store->save_tabs( $out['boot'], array( 0 => array( 'title' => 'Blank', 'content' => '' ) ) );

// Hat: attach manual tab, hide Shipping and Reviews on this product only.
$store->set_list( $out['hat'], '_bulktab_attach', array( 'gift-wrap' ) );
$store->set_list( $out['hat'], '_bulktab_hide', array( 's:shipping', 'reviews' ) );

// Store-wide: rename Description.
update_option( 'bulktab_layout', array( 'description' => array( 'title' => 'Details' ) ) );

// Edit + undo (R-31).
$store->save_tabs( $out['boot'], array( 1 => array( 'title' => 'Care', 'content' => 'oops' ) ) );
$out['undo_ok'] = $store->undo( $out['boot'], 1 );


// ---------------------------------------------------------------- YIKES import (R-6, R-7, R-8)
$out['mug']  = $make( 'Yikes Mug', array() );
$yikes_tabs  = array(
	array( 'title' => 'Material', 'id' => 'material', 'content' => 'Ceramic stoneware' ),
	array( 'title' => 'Returns', 'id' => 'returns', 'content' => '30-day returns' ),
);
update_post_meta( $out['mug'], 'yikes_woo_products_tabs', $yikes_tabs );
update_option(
	'yikes_woo_reusable_products_tabs',
	array( 1 => array( 'tab_id' => 1, 'tab_title' => 'Returns', 'tab_name' => 'returns', 'tab_content' => '30-day returns', 'tab_slug' => 'returns', 'taxonomies' => array(), 'global_tab' => '' ) )
);
update_option( 'yikes_woo_reusable_products_tabs_applied', array( $out['mug'] => array( 1 => array( 'post_id' => $out['mug'], 'reusable_tab_id' => 1, 'tab_id' => 'returns' ) ) ) );

$yikes_hash = static function () use ( $out ) {
	return md5( serialize( array( get_post_meta( $out['mug'], 'yikes_woo_products_tabs', true ), get_option( 'yikes_woo_reusable_products_tabs' ), get_option( 'yikes_woo_reusable_products_tabs_applied' ) ) ) );
};
$count_ours = static function () use ( $out ) {
	return array(
		'shared' => count( get_posts( array( 'post_type' => 'bulktab_tab', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) ),
		'slots'  => count( ( new BulkTab\TabStore( new BulkTab\WpMetaStore() ) )->get_tabs( $out['mug'] ) ),
	);
};
$out['yikes_hash_before'] = $yikes_hash();
$out['ours_before_import'] = $count_ours();

BulkTab\Migration\Migrator::start( 'dry' );
BulkTab\Migration\Migrator::drive( 60 );
$out['yikes_dry']         = BulkTab\Migration\Migrator::get_job();
$out['ours_after_dry']    = $count_ours();

BulkTab\Migration\Migrator::start( 'run' );
BulkTab\Migration\Migrator::drive( 60 );
$out['yikes_run']         = BulkTab\Migration\Migrator::get_job();
$out['ours_after_run']    = $count_ours();

BulkTab\Migration\Migrator::start( 'run' );
BulkTab\Migration\Migrator::drive( 60 );
$out['ours_after_rerun']  = $count_ours();
$out['yikes_hash_after']  = $yikes_hash();

// ---------------------------------------------------------------- WooCommerce CSV round trip (R-2, R-25)
$out['shirt'] = $make( 'Csv Shirt', array() );
$store->save_tabs( $out['shirt'], array( 0 => array( 'title' => 'Fabric', 'content' => 'Cotton' ) ) );

require_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
require_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';

$exporter = new class() extends WC_Product_CSV_Exporter {
	public function csv(): string {
		$this->prepare_data_to_export();
		return $this->export_column_headers() . $this->get_csv_data();
	}
};
$exporter->set_product_ids_to_export( array( $out['shirt'] ) );
$csv = $exporter->csv();
file_put_contents( '/smoke-out/export.csv', $csv );

$fh   = fopen( 'php://memory', 'w+' );
fwrite( $fh, $csv );
rewind( $fh );
$rows = array();
while ( false !== ( $r = fgetcsv( $fh, 0, ',', '"', "\0" ) ) ) {
	$rows[] = $r;
}
$head                  = $rows[0];
$out['csv_headers']    = array_values( array_filter( $head, static function ( $h ) { return 0 === stripos( $h, 'BulkTab' ); } ) );
$ci                    = array_search( 'BulkTab 1 content', $head, true );
$out['csv_exported']   = false === $ci ? null : $rows[1][ $ci ];
$out['csv_edit']       = "Organic <strong>cotton</strong>, \"pre-washed\"\nSecond line é";
if ( false !== $ci ) {
	$rows[1][ $ci ] = $out['csv_edit'];
}
$edited = fopen( '/smoke-out/import.csv', 'w' );
foreach ( $rows as $r ) {
	fputcsv( $edited, $r, ',', '"', "\0" );
}
fclose( $edited );

$importer             = new WC_Product_CSV_Importer(
	'/smoke-out/import.csv',
	array(
		'update_existing' => true,
		'parse'           => true,
		'mapping'         => array(
			'ID'                => 'id',
			'BulkTab 1 title'   => 'bulktab:title:1',
			'BulkTab 1 content' => 'bulktab:content:1',
		),
	)
);
$result               = $importer->import();
$out['csv_result']    = array( 'updated' => count( $result['updated'] ), 'failed' => count( $result['failed'] ), 'skipped' => count( $result['skipped'] ) );
$out['csv_after']     = get_post_meta( $out['shirt'], '_bulktab_1_content', true );


// ---------------------------------------------------------------- R-23: manual placement via shortcode
$out['sc_page'] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Tabs anywhere', 'post_content' => '[bulktab_tabs id="' . $out['boot'] . '"]' ) );

// ---------------------------------------------------------------- R-5: uninstall without opt-in keeps data
$tab_state = static function () use ( $out ) {
	return array(
		count( get_posts( array( 'post_type' => 'bulktab_tab', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) ),
		get_post_meta( $out['boot'], '_bulktab_1_content', true ),
		get_option( 'bulktab_layout' ),
	);
};
$out['uninstall_before'] = $tab_state();
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'bulktab/bulktab.php' );
}
delete_option( 'bulktab_delete_data_on_uninstall' );
include WP_PLUGIN_DIR . '/bulktab/uninstall.php';
$out['uninstall_after'] = $tab_state();

$out['keys'] = array_keys( BulkTab\RulesIndex::get() );
file_put_contents( '/smoke-out/ids.json', wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
