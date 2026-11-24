<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Compat;

use PHPUnit\Framework\TestCase;
use BulkTab\Compat\Compat;

/**
 * @covers \BulkTab\Compat\Compat::wrap_callbacks
 */
final class CompatTest extends TestCase {

	public static function inner(): void {
	}

	public function test_wraps_only_bulktab_tabs(): void {
		$inner = array( self::class, 'inner' );
		$tabs  = array(
			'description'   => array(
				'title'    => 'Description',
				'callback' => 'woocommerce_product_description_tab',
			),
			'bulktab_s_faq' => array(
				'title'       => 'FAQ',
				'callback'    => $inner,
				'bulktab_ref' => 's:faq',
			),
		);
		$out   = Compat::wrap_callbacks( $tabs );
		$this->assertSame( $tabs['description'], $out['description'] );
		$this->assertSame( array( Compat::class, 'panel' ), $out['bulktab_s_faq']['callback'] );
		$this->assertSame( $inner, $out['bulktab_s_faq']['bulktab_callback'] );
	}

	public function test_wrapping_twice_keeps_original_callback(): void {
		$tabs = array(
			3 => array(
				'callback'    => array( self::class, 'inner' ),
				'bulktab_ref' => 'c:1',
			),
		);
		$once  = Compat::wrap_callbacks( $tabs );
		$twice = Compat::wrap_callbacks( $once );
		$this->assertSame( $once, $twice );
		$this->assertSame( array( self::class, 'inner' ), $twice[3]['bulktab_callback'] );
	}

	public function test_leaves_uncallable_and_non_arrays(): void {
		$tabs = array(
			'a' => array(
				'callback'    => 'bulktab_no_such_function',
				'bulktab_ref' => 'c:1',
			),
			'b' => 'junk',
			'c' => array( 'bulktab_ref' => 'c:2' ),
		);
		$this->assertSame( $tabs, Compat::wrap_callbacks( $tabs ) );
		$this->assertSame( 'not-array', Compat::wrap_callbacks( 'not-array' ) );
	}
}
