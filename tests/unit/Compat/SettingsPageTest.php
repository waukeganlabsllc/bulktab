<?php
/**
 * Pure helpers of the Tab settings screen (layout building and ordering).
 *
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Compat;

use PHPUnit\Framework\TestCase;
use BulkTab\Admin\SettingsPage;
use BulkTab\Layout;

/**
 * @covers \BulkTab\Admin\SettingsPage
 */
final class SettingsPageTest extends TestCase {

	public function test_priorities_default_like_order(): void {
		$this->assertSame(
			array(
				'description'            => 10,
				'additional_information' => 20,
				'reviews'                => 30,
				's:faq'                  => 70,
			),
			SettingsPage::priorities( array( 'description', 'additional_information', 'reviews', SettingsPage::OWN, 's:faq' ) )
		);
	}

	public function test_priorities_keep_order_around_own_tabs(): void {
		$above = array();
		for ( $i = 0; $i < 45; $i++ ) {
			$above[] = 's:t' . $i;
		}
		$p    = SettingsPage::priorities( array_merge( $above, array( SettingsPage::OWN, 'reviews', 'x:q' ) ) );
		$prev = PHP_INT_MIN;
		foreach ( $above as $ref ) {
			$this->assertGreaterThan( $prev, $p[ $ref ] );
			$this->assertLessThan( 41, $p[ $ref ] );
			$prev = $p[ $ref ];
		}
		$this->assertGreaterThan( 60, $p['reviews'] );
		$this->assertGreaterThan( $p['reviews'], $p['x:q'] );
		$this->assertArrayNotHasKey( SettingsPage::OWN, $p );
	}

	public function test_priorities_without_own_row(): void {
		$p = SettingsPage::priorities( array( 'reviews', 'description' ) );
		$this->assertLessThan( $p['description'], $p['reviews'] );
		$this->assertLessThan( 41, $p['description'] );
	}

	public function test_build_layout(): void {
		$saved = array(
			's:gone'      => array( 'hide' => true ),
			'description' => array( 'title' => 'Old' ),
			'c:1'         => array( 'hide' => true ),
		);
		$out   = SettingsPage::build_layout(
			$saved,
			array( 'description', 'reviews', 'x:specs', 's:faq', 'c:2', 'bad ref' ),
			array(
				'description' => ' Details ',
				'reviews'     => '',
				'x:specs'     => 'Specs',
			),
			array( 'reviews', 'c:2' ),
			array( 'x:specs' => 'Specifications' ),
			array()
		);
		$this->assertSame(
			array(
				's:gone'      => array( 'hide' => true ),
				'description' => array( 'title' => 'Details' ),
				'reviews'     => array( 'hide' => true ),
				'x:specs'     => array(
					'title' => 'Specs',
					'label' => 'Specifications',
				),
			),
			$out
		);
	}

	public function test_build_layout_with_order_round_trips_through_layout_apply(): void {
		$layout = SettingsPage::build_layout(
			array(),
			array( 'description', 'reviews', 's:faq' ),
			array( 'reviews' => 'Customer reviews' ),
			array(),
			array(),
			array( 's:faq', 'reviews', SettingsPage::OWN, 'description' )
		);
		$tabs   = array(
			'description' => array( 'priority' => 10 ),
			'reviews'     => array(
				'title'    => 'Reviews (2)',
				'priority' => 30,
			),
			'bulktab_s_faq' => array(
				'priority'    => 25,
				'bulktab_ref' => 's:faq',
			),
			'bulktab_c_1'   => array(
				'priority'    => 41,
				'bulktab_ref' => 'c:1',
			),
		);
		$out    = Layout::apply( $tabs, $layout, array(), array() );
		uasort(
			$out,
			static function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);
		$this->assertSame( array( 'bulktab_s_faq', 'reviews', 'bulktab_c_1', 'description' ), array_keys( $out ) );
		$this->assertSame( 'Customer reviews (2)', $out['reviews']['title'] );
	}

	public function test_valid_ref(): void {
		foreach ( array( 'description', 'additional_information', 'reviews', 's:size-guide', 'x:my_tab', 'x:Specs' ) as $ref ) {
			$this->assertTrue( SettingsPage::valid_ref( $ref ), $ref );
		}
		foreach ( array( 'c:1', 'x:', 's:a b', 'other', '__own__', 'x:a"b' ) as $ref ) {
			$this->assertFalse( SettingsPage::valid_ref( $ref ), $ref );
		}
	}
}
