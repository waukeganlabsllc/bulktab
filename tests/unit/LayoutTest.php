<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use BulkTab\Layout;

/**
 * @covers \BulkTab\Layout
 */
final class LayoutTest extends TestCase {

	/** A typical WooCommerce tabs array with our tabs and a third-party one. */
	private static function tabs(): array {
		return array(
			'description'            => array(
				'title'    => 'Description',
				'priority' => 10,
			),
			'additional_information' => array(
				'title'    => 'Additional information',
				'priority' => 20,
			),
			'bulktab_s_size-guide'     => array(
				'title'     => 'Size guide',
				'priority'  => 25,
				'bulktab_ref' => 's:size-guide',
			),
			'reviews'                => array(
				'title'    => 'Reviews (%d)',
				'priority' => 30,
			),
			'bulktab_c_1'              => array(
				'title'     => 'Care',
				'priority'  => 26,
				'bulktab_ref' => 'c:1',
			),
			'vendor_faq'             => array(
				'title'    => 'FAQ',
				'priority' => 40,
			),
		);
	}

	/** Refs in the order WooCommerce would display them (stable sort by priority). */
	private static function display_order( array $tabs ): array {
		$i    = 0;
		$rows = array();
		foreach ( $tabs as $key => $tab ) {
			$rows[] = array( (int) ( $tab['priority'] ?? 0 ), $i++, Layout::ref_for( (string) $key, $tab ) );
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( $a[0], $a[1] ) <=> array( $b[0], $b[1] );
			}
		);
		return array_column( $rows, 2 );
	}

	// ---- ref_for -----------------------------------------------------------

	/**
	 * @dataProvider provide_refs
	 */
	public function test_ref_for( string $key, array $tab, string $expected ): void {
		$this->assertSame( $expected, Layout::ref_for( $key, $tab ) );
	}

	public function provide_refs(): array {
		return array(
			'description'            => array( 'description', array(), 'description' ),
			'additional_information' => array( 'additional_information', array(), 'additional_information' ),
			'reviews'                => array( 'reviews', array( 'title' => 'Reviews' ), 'reviews' ),
			'shared tab'             => array( 'bulktab_s_size-guide', array( 'bulktab_ref' => 's:size-guide' ), 's:size-guide' ),
			'custom tab'             => array( 'bulktab_c_3', array( 'bulktab_ref' => 'c:3' ), 'c:3' ),
			'numeric key ours'       => array( '0', array( 'bulktab_ref' => 'c:1' ), 'c:1' ),
			'other plugin'           => array( 'vendor_faq', array(), 'x:vendor_faq' ),
			'numeric key other'      => array( '3', array(), 'x:3' ),
			'built-in name casing'   => array( 'Description', array(), 'x:Description' ),
			'bulktab_ref wins'         => array( 'description', array( 'bulktab_ref' => 's:desc' ), 's:desc' ),
		);
	}

	// ---- apply: global -----------------------------------------------------

	public function test_apply_with_nothing_is_identity(): void {
		$this->assertSame( self::tabs(), Layout::apply( self::tabs(), array(), array(), array() ) );
	}

	public function test_global_rename_hide_priority(): void {
		$global = array(
			'description'    => array(
				'title'    => 'Details',
				'priority' => 50,
			),
			'reviews'        => array( 'title' => 'Opinions (%d)' ),
			's:size-guide'   => array( 'hide' => true ),
			'x:vendor_faq'   => array(
				'title'    => '   ',
				'priority' => '5',
			),
			'additional_information' => array(
				'title'    => '',
				'priority' => 'abc',
				'hide'     => false,
			),
			's:not-present'  => array( 'hide' => true ),
		);
		$out    = Layout::apply( self::tabs(), $global, array(), array() );

		$this->assertArrayNotHasKey( 'bulktab_s_size-guide', $out );
		$this->assertSame( 'Details', $out['description']['title'] );
		$this->assertSame( 50, $out['description']['priority'] );
		$this->assertSame( 'Opinions (%d)', $out['reviews']['title'] );
		$this->assertSame( 30, $out['reviews']['priority'] );
		$this->assertSame( 'FAQ', $out['vendor_faq']['title'], 'blank title keeps original' );
		$this->assertSame( 5, $out['vendor_faq']['priority'] );
		$this->assertSame( 'Additional information', $out['additional_information']['title'] );
		$this->assertSame( 20, $out['additional_information']['priority'], 'non-numeric priority ignored' );
		$this->assertSame( self::tabs()['bulktab_c_1'], $out['bulktab_c_1'] );
		$this->assertSame(
			array( 'x:vendor_faq', 'additional_information', 'c:1', 'reviews', 'description' ),
			self::display_order( $out )
		);
	}

	public function test_global_hide_truthy_values(): void {
		foreach ( array( true, 1, '1', 'yes' ) as $hide ) {
			$out = Layout::apply( self::tabs(), array( 'reviews' => array( 'hide' => $hide ) ), array(), array() );
			$this->assertArrayNotHasKey( 'reviews', $out );
		}
		foreach ( array( false, 0, '0', '' ) as $hide ) {
			$out = Layout::apply( self::tabs(), array( 'reviews' => array( 'hide' => $hide ) ), array(), array() );
			$this->assertArrayHasKey( 'reviews', $out );
		}
	}

	public function test_preserves_other_tab_fields(): void {
		$tabs = array(
			'description' => array(
				'title'    => 'Description',
				'priority' => 10,
				'callback' => 'woocommerce_product_description_tab',
			),
		);
		$out  = Layout::apply( $tabs, array( 'description' => array( 'title' => 'Info' ) ), array( 'description' ), array() );
		$this->assertSame( 'woocommerce_product_description_tab', $out['description']['callback'] );
		$this->assertSame( 'Info', $out['description']['title'] );
	}

	// ---- apply: per product ------------------------------------------------

	/**
	 * @dataProvider provide_hidden
	 */
	public function test_per_product_hide( array $hidden, array $expected_keys ): void {
		$this->assertSame( $expected_keys, array_keys( Layout::apply( self::tabs(), array(), array(), $hidden ) ) );
	}

	public function provide_hidden(): array {
		$all = array_keys( self::tabs() );
		return array(
			'none'             => array( array(), $all ),
			'built-in'         => array( array( 'reviews' ), array_values( array_diff( $all, array( 'reviews' ) ) ) ),
			'shared'           => array( array( 's:size-guide' ), array_values( array_diff( $all, array( 'bulktab_s_size-guide' ) ) ) ),
			'custom'           => array( array( 'c:1' ), array_values( array_diff( $all, array( 'bulktab_c_1' ) ) ) ),
			'other plugin'     => array( array( 'x:vendor_faq' ), array_values( array_diff( $all, array( 'vendor_faq' ) ) ) ),
			'raw key no match' => array( array( 'vendor_faq', 'bulktab_c_1' ), $all ),
			'several'          => array( array( 'description', 'c:1', 'unknown' ), array( 'additional_information', 'bulktab_s_size-guide', 'reviews', 'vendor_faq' ) ),
		);
	}

	public function test_per_product_hide_beats_global_settings(): void {
		$out = Layout::apply( self::tabs(), array( 'reviews' => array( 'title' => 'X' ) ), array( 'reviews' ), array( 'reviews' ) );
		$this->assertArrayNotHasKey( 'reviews', $out );
	}

	/**
	 * @dataProvider provide_orders
	 */
	public function test_per_product_order( array $order, array $expected ): void {
		$this->assertSame( $expected, self::display_order( Layout::apply( self::tabs(), array(), $order, array() ) ) );
	}

	public function provide_orders(): array {
		return array(
			'full order'                   => array(
				array( 'x:vendor_faq', 'reviews', 'c:1', 's:size-guide', 'additional_information', 'description' ),
				array( 'x:vendor_faq', 'reviews', 'c:1', 's:size-guide', 'additional_information', 'description' ),
			),
			'partial: unlisted after, kept relative order' => array(
				array( 'reviews', 'c:1' ),
				array( 'reviews', 'c:1', 'description', 'additional_information', 's:size-guide', 'x:vendor_faq' ),
			),
			'one item'                     => array(
				array( 'x:vendor_faq' ),
				array( 'x:vendor_faq', 'description', 'additional_information', 's:size-guide', 'c:1', 'reviews' ),
			),
			'unknown refs ignored'         => array(
				array( 'gone', 'reviews', 's:deleted', 'description' ),
				array( 'reviews', 'description', 'additional_information', 's:size-guide', 'c:1', 'x:vendor_faq' ),
			),
		);
	}

	public function test_order_assigns_10_20_30(): void {
		$out = Layout::apply( self::tabs(), array(), array( 'reviews', 'description', 'c:1' ), array() );
		$this->assertSame( 10, $out['reviews']['priority'] );
		$this->assertSame( 20, $out['description']['priority'] );
		$this->assertSame( 30, $out['bulktab_c_1']['priority'] );
	}

	public function test_order_overrides_global_priority_and_keeps_global_rename(): void {
		$global = array(
			'reviews'     => array(
				'priority' => 1,
				'title'    => 'Opinions',
			),
			'description' => array( 'priority' => 500 ),
		);
		$out    = Layout::apply( self::tabs(), $global, array( 'description' ), array() );
		$this->assertSame( 'Opinions', $out['reviews']['title'] );
		$this->assertSame(
			array( 'description', 'reviews', 'additional_information', 's:size-guide', 'c:1', 'x:vendor_faq' ),
			self::display_order( $out ),
			'unlisted tabs keep their (global) relative order after the listed ones'
		);
	}

	public function test_unlisted_go_after_a_long_order_list(): void {
		// A long stored order (e.g. many shared tabs, most not on this product) must not push listed tabs past unlisted ones.
		$order   = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$order[] = 's:old-' . $i;
		}
		$order[] = 'reviews';
		$out     = Layout::apply( self::tabs(), array(), $order, array() );
		$this->assertSame(
			array( 'reviews', 'description', 'additional_information', 's:size-guide', 'c:1', 'x:vendor_faq' ),
			self::display_order( $out )
		);
	}

	public function test_unlisted_with_extreme_priorities_stay_after_listed(): void {
		$tabs                         = self::tabs();
		$tabs['vendor_faq']['priority'] = -5000;
		$tabs['description']['priority'] = 99999;
		$out = Layout::apply( $tabs, array(), array( 'c:1' ), array() );
		$this->assertSame(
			array( 'c:1', 'x:vendor_faq', 'additional_information', 's:size-guide', 'reviews', 'description' ),
			self::display_order( $out )
		);
	}

	public function test_unlisted_with_equal_priorities_keep_array_order(): void {
		$tabs = array(
			'b'       => array( 'priority' => 50 ),
			'a'       => array( 'priority' => 50 ),
			'reviews' => array( 'priority' => 30 ),
			'c'       => array(),
		);
		$out  = Layout::apply( $tabs, array(), array( 'reviews' ), array() );
		$this->assertSame( array( 'reviews', 'x:b', 'x:a', 'x:c' ), self::display_order( $out ) );
	}

	public function test_hidden_and_order_together(): void {
		$out = Layout::apply( self::tabs(), array(), array( 'c:1', 'reviews' ), array( 'reviews', 'description' ) );
		$this->assertSame( array( 'c:1', 'additional_information', 's:size-guide', 'x:vendor_faq' ), self::display_order( $out ) );
	}

	public function test_numeric_keys_with_bulktab_ref(): void {
		// The accordion/block layer passes numeric keys; refs come from bulktab_ref.
		$tabs = array(
			0 => array(
				'title'     => 'Care',
				'priority'  => 20,
				'bulktab_ref' => 'c:1',
			),
			1 => array(
				'title'    => 'Other',
				'priority' => 10,
			),
		);
		$out  = Layout::apply( $tabs, array( 'c:1' => array( 'title' => 'Care & wash' ) ), array( 'c:1' ), array( 'x:1' ) );
		$this->assertSame( array( 0 ), array_keys( $out ) );
		$this->assertSame( 'Care & wash', $out[0]['title'] );
	}

	// ---- parse_list --------------------------------------------------------

	/**
	 * @dataProvider provide_lists
	 * @param mixed $value Raw value.
	 */
	public function test_parse_list( $value, array $expected ): void {
		$this->assertSame( $expected, Layout::parse_list( $value ) );
	}

	public function provide_lists(): array {
		return array(
			'csv'             => array( 'description,s:size-guide,c:1', array( 'description', 's:size-guide', 'c:1' ) ),
			'spaces'          => array( ' description , c:1 ,', array( 'description', 'c:1' ) ),
			'empty string'    => array( '', array() ),
			'null'            => array( null, array() ),
			'only commas'     => array( ',,,', array() ),
			'dupes'           => array( 'c:1,c:1,reviews,c:1', array( 'c:1', 'reviews' ) ),
			'bad chars'       => array( 'c:1,<script>,s:a b,s:ok_1-x,x:é', array( 'c:1', 's:ok_1-x' ) ),
			'array input'     => array( array( 'reviews', ' c:2 ', '', 'reviews' ), array( 'reviews', 'c:2' ) ),
			'array nonscalar' => array( array( 'c:1', array( 'c:2' ), new \stdClass(), null, 7 ), array( 'c:1', '7' ) ),
			'int input'       => array( 5, array( '5' ) ),
			'case kept'       => array( 'X:Foo', array( 'X:Foo' ) ),
			'newline'         => array( "c:1\n,c:2", array( 'c:1', 'c:2' ) ),
		);
	}
}
