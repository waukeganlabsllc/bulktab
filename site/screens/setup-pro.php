<?php
/**
 * Screenshot fixture, Pro part (runs after setup.php). Never shipped.
 *
 * @package BulkTab
 */

// phpcs:ignoreFile -- dev-only fixture for site screenshots.

wp_set_current_user( 1 );

$ids = json_decode( (string) file_get_contents( '/screens-out/ids.json' ), true );
$t   = $ids['terms'];

// Shoes AND NOT tagged Sale.
$rules = array(
	'v'       => 1,
	'all'     => false,
	'groups'  => array(
		array(
			array( 'tax' => 'product_cat', 'terms' => array( (int) $t['shoes'] ), 'not' => false ),
			array( 'tax' => 'product_tag', 'terms' => array( (int) $t['sale'] ), 'not' => true ),
		),
	),
	'include' => array(),
	'exclude' => array(),
);
$pm = wp_insert_post(
	array(
		'post_type'    => 'bulktab_tab',
		'post_status'  => 'publish',
		'post_title'   => 'Price match promise',
		'post_content' => '<p>Found this pair cheaper at another store within 14 days of buying? We will refund the difference. Sale items are not included.</p>',
		'menu_order'   => 7,
	)
);
update_post_meta( $pm, '_bulktab_rules', wp_slash( wp_json_encode( $rules ) ) );
BulkTab\RulesIndex::rebuild();

$ids['pro_tab'] = $pm;

// Two backups so the backups screen shows a real list.
$ids['backup1'] = BulkTabPro\Backup\Backup::create_now( 'manual' );
sleep( 1 );
$ids['backup2'] = BulkTabPro\Backup\Backup::create_now( 'manual' );

file_put_contents( '/screens-out/ids.json', wp_json_encode( $ids, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
