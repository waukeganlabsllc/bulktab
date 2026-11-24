<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Compat;

use PHPUnit\Framework\TestCase;
use BulkTab\Compat\BlockTheme;

/**
 * @covers \BulkTab\Compat\BlockTheme
 */
final class BlockThemeTest extends TestCase {

	private static function block( string $name, array $inner = array(), array $attrs = array() ): array {
		return array(
			'blockName'   => $name,
			'attrs'       => $attrs,
			'innerBlocks' => $inner,
		);
	}

	private static function template( array $details ): array {
		return array(
			self::block( 'core/template-part', array(), array( 'slug' => 'header' ) ),
			self::block( 'core/group', array( self::block( 'core/columns', array( $details ) ) ) ),
			array( 'blockName' => null, 'innerHTML' => "\n" ),
		);
	}

	public function test_no_details_block_is_none(): void {
		$this->assertSame( BlockTheme::MODE_NONE, BlockTheme::analyze( array( self::block( 'core/paragraph' ) ) ) );
		$this->assertSame( BlockTheme::MODE_NONE, BlockTheme::analyze( array() ) );
	}

	public function test_details_without_inner_blocks_is_legacy(): void {
		$this->assertSame( BlockTheme::MODE_LEGACY, BlockTheme::analyze( self::template( self::block( 'woocommerce/product-details' ) ) ) );
	}

	public function test_woocommerce_accordion_group_is_accordion(): void {
		$details = self::block(
			'woocommerce/product-details',
			array( self::block( 'woocommerce/accordion-group', array( self::block( 'woocommerce/accordion-item' ) ) ) )
		);
		$this->assertSame( BlockTheme::MODE_ACCORDION, BlockTheme::analyze( self::template( $details ) ) );
	}

	public function test_nested_core_accordion_is_accordion(): void {
		$details = self::block(
			'woocommerce/product-details',
			array( self::block( 'core/group', array( self::block( 'core/accordion' ) ) ) )
		);
		$this->assertSame( BlockTheme::MODE_ACCORDION, BlockTheme::analyze( array( $details ) ) );
	}

	public function test_inner_blocks_without_accordion(): void {
		$details = self::block( 'woocommerce/product-details', array( self::block( 'core/paragraph' ) ) );
		$this->assertSame( BlockTheme::MODE_NO_ACCORDION, BlockTheme::analyze( array( $details ) ) );
	}

	public function test_first_details_block_wins(): void {
		$blocks = array(
			self::block( 'woocommerce/product-details' ),
			self::block( 'woocommerce/product-details', array( self::block( 'core/paragraph' ) ) ),
		);
		$this->assertSame( BlockTheme::MODE_LEGACY, BlockTheme::analyze( $blocks ) );
	}

	public function test_patterns_followed_only_with_resolver(): void {
		$blocks  = array( self::block( 'core/pattern', array(), array( 'slug' => 'theme/single-product' ) ) );
		$resolve = static function ( array $block ): array {
			return 'theme/single-product' === $block['attrs']['slug']
				? array( self::block( 'woocommerce/product-details', array( self::block( 'core/accordion' ) ) ) )
				: array();
		};
		$this->assertSame( BlockTheme::MODE_NONE, BlockTheme::analyze( $blocks ) );
		$this->assertSame( BlockTheme::MODE_ACCORDION, BlockTheme::analyze( $blocks, $resolve ) );
	}

	public function test_accordion_inside_pattern_within_details(): void {
		$blocks  = array(
			self::block( 'woocommerce/product-details', array( self::block( 'core/pattern', array(), array( 'slug' => 'acc' ) ) ) ),
		);
		$resolve = static function (): array {
			return array( self::block( 'woocommerce/accordion-group' ) );
		};
		$this->assertSame( BlockTheme::MODE_NO_ACCORDION, BlockTheme::analyze( $blocks ) );
		$this->assertSame( BlockTheme::MODE_ACCORDION, BlockTheme::analyze( $blocks, $resolve ) );
	}

	public function test_self_referencing_pattern_terminates(): void {
		$blocks  = array( self::block( 'core/pattern', array(), array( 'slug' => 'loop' ) ) );
		$calls   = 0;
		$resolve = static function () use ( &$blocks, &$calls ): array {
			++$calls;
			return $blocks;
		};
		$this->assertSame( BlockTheme::MODE_NONE, BlockTheme::analyze( $blocks, $resolve ) );
		$this->assertLessThanOrEqual( BlockTheme::MAX_DEPTH, $calls );
	}

	public function test_ignores_malformed_entries(): void {
		$blocks = array( 'junk', 42, array( 'innerBlocks' => 'x' ), self::block( 'woocommerce/product-details' ) );
		$this->assertSame( BlockTheme::MODE_LEGACY, BlockTheme::analyze( $blocks ) );
	}
}
