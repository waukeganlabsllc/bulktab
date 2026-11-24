<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use BulkTab\Migration\Mapper;

/**
 * @covers \BulkTab\Migration\Mapper
 */
final class MapperTest extends TestCase {

	private static function tab( string $title, string $content, ?string $id = null ): array {
		return array(
			'id'      => null === $id ? strtolower( str_replace( ' ', '-', $title ) ) : $id,
			'title'   => $title,
			'content' => $content,
		);
	}

	private static function saved(): array {
		return array(
			1 => array(
				'id'       => 1,
				'title'    => 'Shipping',
				'name'     => 'Shipping (default)',
				'content'  => '<p>Free shipping over $50.</p>',
				'slug'     => 'shipping',
				'has_rule' => false,
			),
			2 => array(
				'id'       => 2,
				'title'    => 'Care',
				'name'     => '',
				'content'  => 'Wash cold.',
				'slug'     => 'care',
				'has_rule' => false,
			),
		);
	}

	private static function keys(): array {
		return array(
			1 => 'shipping-default',
			2 => 'care',
		);
	}

	public function test_plain_tabs_become_custom_in_order(): void {
		$plan = Mapper::map_product(
			array( self::tab( 'Specs', 'S' ), self::tab( 'Manual', 'M' ) ),
			array(),
			self::saved(),
			self::keys()
		);
		$this->assertSame( array( 'custom', 'custom' ), array_column( $plan['items'], 'kind' ) );
		$this->assertSame( array( 'Specs', 'Manual' ), array_column( $plan['items'], 'title' ) );
		$this->assertSame( array(), $plan['attach'] );
		$this->assertSame( array(), $plan['hide'] );
		$this->assertSame( 2, $plan['counts']['custom'] );
		$this->assertSame( 2, $plan['counts']['tabs'] );
	}

	public function test_linked_copy_becomes_shared_reference_not_duplicate(): void {
		$plan = Mapper::map_product(
			array( self::tab( 'Specs', 'S' ), self::tab( 'Shipping', "<p>Free shipping over $50.</p>\r\n" ) ),
			array( 1 => 'shipping' ),
			self::saved(),
			self::keys()
		);
		$this->assertSame(
			array(
				array(
					'kind'    => 'custom',
					'title'   => 'Specs',
					'content' => 'S',
					'hidden'  => false,
				),
				array(
					'kind' => 'shared',
					'key'  => 'shipping-default',
				),
			),
			$plan['items']
		);
		$this->assertSame( array( 'shipping-default' ), $plan['attach'] );
		$this->assertSame( array(), $plan['hide'] );
		$this->assertSame( 1, $plan['counts']['shared'] );
		$this->assertSame( 0, $plan['counts']['drifted'] );
	}

	public function test_drifted_copy_keeps_product_text_and_hides_shared(): void {
		$plan = Mapper::map_product(
			array( self::tab( 'Shipping', '<p>Free shipping over $75 for this item.</p>' ) ),
			array( 1 => 'shipping' ),
			self::saved(),
			self::keys()
		);
		$this->assertSame( 'custom', $plan['items'][0]['kind'] );
		$this->assertSame( '<p>Free shipping over $75 for this item.</p>', $plan['items'][0]['content'] );
		$this->assertFalse( $plan['items'][0]['hidden'] );
		$this->assertSame( array( 'shipping-default' ), $plan['attach'] );
		$this->assertSame( array( 's:shipping-default' ), $plan['hide'] );
		$this->assertSame( 1, $plan['counts']['drifted'] );
		$this->assertContains( 'drifted', array_column( $plan['issues'], 'code' ) );
	}

	public function test_title_drift_counts_as_drift(): void {
		$plan = Mapper::map_product(
			array( self::tab( 'Delivery', '<p>Free shipping over $50.</p>', 'delivery' ) ),
			array( 1 => 'delivery' ),
			self::saved(),
			self::keys()
		);
		$this->assertSame( 'custom', $plan['items'][0]['kind'] );
		$this->assertSame( array( 's:shipping-default' ), $plan['hide'] );
	}

	public function test_unlinked_identical_copy_becomes_reference(): void {
		$plan = Mapper::map_product(
			array( self::tab( 'Care', 'Wash cold.' ) ),
			array(),
			self::saved(),
			self::keys()
		);
		$this->assertSame( 'shared', $plan['items'][0]['kind'] );
		$this->assertSame( array( 'care' ), $plan['attach'] );
		$this->assertSame( 1, $plan['counts']['matched_unlinked'] );
	}

	public function test_unlinked_different_copy_stays_custom(): void {
		$plan = Mapper::map_product(
			array( self::tab( 'Care', 'Dry clean only.' ) ),
			array(),
			self::saved(),
			self::keys()
		);
		$this->assertSame( 'custom', $plan['items'][0]['kind'] );
		$this->assertSame( array(), $plan['attach'] );
		$this->assertSame( array(), $plan['hide'] );
	}

	public function test_link_to_missing_saved_tab_is_custom_with_issue(): void {
		$plan = Mapper::map_product(
			array( self::tab( 'Old', 'text' ) ),
			array( 9 => 'old' ),
			self::saved(),
			self::keys()
		);
		$this->assertSame( 'custom', $plan['items'][0]['kind'] );
		$this->assertSame( array( 'saved_tab_missing' ), array_column( $plan['issues'], 'code' ) );
	}

	public function test_applied_without_copy_is_not_attached(): void {
		$plan = Mapper::map_product(
			array( self::tab( 'Specs', 'S' ) ),
			array( 1 => 'shipping' ),
			self::saved(),
			self::keys()
		);
		$this->assertSame( array(), $plan['attach'] );
		$this->assertSame( array( 'applied_without_copy' ), array_column( $plan['issues'], 'code' ) );
	}

	public function test_same_shared_tab_twice_is_referenced_once(): void {
		$plan = Mapper::map_product(
			array( self::tab( 'Care', 'Wash cold.' ), self::tab( 'Care', 'Wash cold.', 'care-2' ) ),
			array(),
			self::saved(),
			self::keys()
		);
		$this->assertSame( array( 'shared', 'custom' ), array_column( $plan['items'], 'kind' ) );
		$this->assertSame( array( 'care' ), $plan['attach'] );
	}

	public function test_duplicate_ids_only_last_is_visible(): void {
		$plan = Mapper::map_product(
			array( self::tab( 'Info', 'first' ), self::tab( 'Info', 'second' ) ),
			array(),
			self::saved(),
			self::keys()
		);
		$this->assertTrue( $plan['items'][0]['hidden'] );
		$this->assertSame( 'first', $plan['items'][0]['content'] );
		$this->assertFalse( $plan['items'][1]['hidden'] );
		$this->assertSame( 1, $plan['counts']['hidden'] );
		$this->assertSame( array( 'duplicate_hidden' ), array_column( $plan['issues'], 'code' ) );
	}

	public function test_empty_titles(): void {
		$plan = Mapper::map_product(
			array(
				self::tab( '', 'orphan text', '' ),
				self::tab( '0', 'zero title', '0' ),
				self::tab( '', '   ', '' ),
				self::tab( 'Real', 'R' ),
			),
			array(),
			self::saved(),
			self::keys()
		);
		$this->assertCount( 3, $plan['items'] );
		$this->assertTrue( $plan['items'][0]['hidden'] );
		$this->assertTrue( $plan['items'][1]['hidden'] );
		$this->assertFalse( $plan['items'][2]['hidden'] );
		$this->assertSame( 1, $plan['counts']['skipped_empty'] );
		$this->assertSame( 2, $plan['counts']['hidden'] );
	}

	public function test_tab_named_like_builtin_hides_builtin(): void {
		$plan = Mapper::map_product(
			array( self::tab( 'Reviews', 'Our own reviews text' ) ),
			array(),
			self::saved(),
			self::keys()
		);
		$this->assertSame( array( 'reviews' ), $plan['hide'] );
		$this->assertSame( 'custom', $plan['items'][0]['kind'] );
	}

	public function test_more_than_twenty_tabs_are_all_planned(): void {
		$tabs = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$tabs[] = self::tab( "Tab $i", "C$i" );
		}
		$plan = Mapper::map_product( $tabs, array(), self::saved(), self::keys() );
		$this->assertCount( 25, $plan['items'] );
		$this->assertSame( 25, $plan['counts']['custom'] );
	}

	public function test_source_hash_is_stable_and_sensitive(): void {
		$raw  = serialize( array( self::tab( 'Shipping', 'x' ) ) );
		$base = Mapper::source_hash( $raw, array( 1 => 'shipping' ), self::saved(), self::keys() );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $base );
		$this->assertSame( $base, Mapper::source_hash( $raw, array( 1 => 'shipping' ), self::saved(), self::keys() ) );

		$saved                 = self::saved();
		$saved[2]['content']   = 'changed';
		$this->assertSame( $base, Mapper::source_hash( $raw, array( 1 => 'shipping' ), $saved, self::keys() ), 'unlinked saved tab does not matter' );

		$saved[1]['content'] = 'changed';
		$this->assertNotSame( $base, Mapper::source_hash( $raw, array( 1 => 'shipping' ), $saved, self::keys() ) );
		$this->assertNotSame( $base, Mapper::source_hash( $raw . ' ', array( 1 => 'shipping' ), self::saved(), self::keys() ) );
		$this->assertNotSame( $base, Mapper::source_hash( $raw, array(), self::saved(), self::keys() ) );
		$this->assertNotSame( $base, Mapper::source_hash( $raw, array( 1 => 'other' ), self::saved(), self::keys() ) );
		$this->assertNotSame( $base, Mapper::source_hash( $raw, array( 1 => 'shipping' ), self::saved(), array( 1 => 'k2' ) ) );
	}

	public function test_use_the_content_mapping_is_inverted(): void {
		$this->assertSame( '0', Mapper::use_the_content( 'true' ) );
		$this->assertSame( '1', Mapper::use_the_content( 'false' ) );
		$this->assertSame( '1', Mapper::use_the_content( null ) );
	}

	public function test_key_source_prefers_name(): void {
		$saved = self::saved();
		$this->assertSame( 'Shipping (default)', Mapper::key_source( $saved[1] ) );
		$this->assertSame( 'Care', Mapper::key_source( $saved[2] ) );
	}
}
