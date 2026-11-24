<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Integrations;

use BulkTab\Integrations\Csv\Columns;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BulkTab\Integrations\Csv\Columns
 */
final class ColumnsTest extends TestCase {

	public function test_ids_and_headers(): void {
		$this->assertSame( 'bulktab:title:3', Columns::title_id( 3 ) );
		$this->assertSame( 'bulktab:content:12', Columns::content_id( 12 ) );
		$this->assertSame( 'BulkTab 3 title', Columns::header( 'bulktab:title:3' ) );
		$this->assertSame( 'BulkTab 12 content', Columns::header( 'bulktab:content:12' ) );
		$this->assertSame( 'BulkTab attached', Columns::header( Columns::ATTACHED ) );
		$this->assertSame( 'BulkTab hidden', Columns::header( Columns::HIDDEN ) );
		$this->assertSame( 'BulkTab order', Columns::header( Columns::ORDER ) );
		$this->assertSame( 'sku', Columns::header( 'sku' ) );
	}

	public function test_ids_stay_in_the_bulktab_export_group(): void {
		// WooCommerce's is_column_exporting() only looks at the part before the first colon.
		foreach ( array_keys( Columns::export_columns( array( 1, 20 ) ) ) as $id ) {
			$this->assertSame( Columns::GROUP, current( explode( ':', $id ) ), $id );
		}
	}

	public function test_parse_id(): void {
		$this->assertSame(
			array(
				'kind'     => 'slot',
				'field'    => 'title',
				'slot'     => 7,
				'raw_slot' => '7',
			),
			Columns::parse_id( 'bulktab:title:7' )
		);
		$this->assertSame( 'content', Columns::parse_id( 'bulktab:content:20' )['field'] );
		$this->assertSame( 20, Columns::parse_id( 'bulktab:content:20' )['slot'] );
		$this->assertSame( 'list', Columns::parse_id( Columns::ORDER )['kind'] );
		$this->assertSame( 'order', Columns::parse_id( Columns::ORDER )['field'] );
	}

	/**
	 * @dataProvider invalid_slots
	 */
	public function test_parse_id_invalid_slot_is_zero( string $raw ): void {
		$parsed = Columns::parse_id( 'bulktab:title:' . $raw );
		$this->assertNotNull( $parsed );
		$this->assertSame( 0, $parsed['slot'] );
		$this->assertSame( trim( $raw ), $parsed['raw_slot'] );
	}

	public function invalid_slots(): array {
		return array(
			'zero'     => array( '0' ),
			'too high' => array( '21' ),
			'word'     => array( 'two' ),
			'empty'    => array( '' ),
			'negative' => array( '-1' ),
			'decimal'  => array( '1.5' ),
			'huge'     => array( '99999999999999999999' ),
		);
	}

	public function test_parse_id_leading_zero_and_spaces(): void {
		$this->assertSame( 1, Columns::parse_id( 'bulktab:title:01' )['slot'] );
		$this->assertSame( 4, Columns::parse_id( 'bulktab:title: 4 ' )['slot'] );
	}

	/**
	 * @dataProvider not_ours
	 */
	public function test_parse_id_not_ours( string $id ): void {
		$this->assertNull( Columns::parse_id( $id ) );
		$this->assertFalse( Columns::is_ours( $id ) );
	}

	public function not_ours(): array {
		return array(
			array( 'bulktab' ),
			array( 'bulktab:foo' ),
			array( 'bulktab:titles:1' ),
			array( 'meta:_bulktab_1_title' ),
			array( 'description' ),
			array( 'BULKTAB:title:1' ),
			array( '' ),
		);
	}

	public function test_is_content(): void {
		$this->assertTrue( Columns::is_content( 'bulktab:content:1' ) );
		$this->assertFalse( Columns::is_content( 'bulktab:title:1' ) );
		$this->assertFalse( Columns::is_content( Columns::ATTACHED ) );
	}

	public function test_export_columns_sorted_pairs_then_lists(): void {
		$this->assertSame(
			array(
				'bulktab:title:1'   => 'BulkTab 1 title',
				'bulktab:content:1' => 'BulkTab 1 content',
				'bulktab:title:4'   => 'BulkTab 4 title',
				'bulktab:content:4' => 'BulkTab 4 content',
				'bulktab:attached'  => 'BulkTab attached',
				'bulktab:hidden'    => 'BulkTab hidden',
				'bulktab:order'     => 'BulkTab order',
			),
			Columns::export_columns( array( 4, 1, 4, 0, 21 ) )
		);
	}

	public function test_export_columns_without_slots_has_lists_only(): void {
		$this->assertSame( Columns::LIST_HEADERS, Columns::export_columns( array() ) );
	}

	public function test_expand_replaces_placeholder_in_place(): void {
		$in  = array(
			'id'      => 'ID',
			'bulktab' => 'BulkTab tabs',
			'name'    => 'Name',
		);
		$out = Columns::expand( $in, array( 2 ), true );
		$this->assertSame(
			array( 'id', 'bulktab:title:2', 'bulktab:content:2', 'bulktab:attached', 'bulktab:hidden', 'bulktab:order', 'name' ),
			array_keys( $out )
		);
	}

	public function test_expand_is_idempotent(): void {
		$in   = array(
			'id'      => 'ID',
			'bulktab' => 'BulkTab tabs',
		);
		$once = Columns::expand( $in, array( 1, 2 ), true );
		$this->assertSame( $once, Columns::expand( $once, array( 1, 2 ), true ) );
	}

	public function test_expand_appends_when_no_placeholder(): void {
		$out = Columns::expand( array( 'id' => 'ID' ), array( 1 ), true );
		$this->assertSame( array( 'id', 'bulktab:title:1', 'bulktab:content:1', 'bulktab:attached', 'bulktab:hidden', 'bulktab:order' ), array_keys( $out ) );
	}

	public function test_expand_not_exporting_removes_all_of_ours(): void {
		$in = array(
			'id'              => 'ID',
			'bulktab'         => 'BulkTab tabs',
			'bulktab:title:1' => 'BulkTab 1 title',
			'meta:x'          => 'Meta: x',
		);
		$this->assertSame(
			array(
				'id'     => 'ID',
				'meta:x' => 'Meta: x',
			),
			Columns::expand( $in, array( 1 ), false )
		);
	}

	public function test_slots_from_meta_keys(): void {
		$keys = array(
			'_bulktab_3_title',
			'_bulktab_1_content',
			'_bulktab_3_content',
			'_bulktab_5_content_prev', // Undo copy only: not a tab.
			'_bulktab_attach',
			'_bulktab_order',
			'_bulktab_hide',
			'_bulktab_21_title',
			'_bulktab_0_title',
			'_bulktab_key',
			'_bulktab_rules',
			'_bulktab_x_title',
			'_bulktab_10_title',
		);
		$this->assertSame( array( 1, 3, 10 ), Columns::slots_from_meta_keys( $keys ) );
		$this->assertSame( array(), Columns::slots_from_meta_keys( array() ) );
	}

	public function test_default_mapping_keys_are_lowercase_headers(): void {
		$this->assertSame(
			array(
				'bulktab attached' => Columns::ATTACHED,
				'bulktab hidden'   => Columns::HIDDEN,
				'bulktab order'    => Columns::ORDER,
			),
			Columns::default_mapping()
		);
	}

	public function test_woocommerce_auto_mapping_of_exported_headers(): void {
		$headers = array_merge( array( 'SKU' ), array_values( Columns::export_columns( array( 1, 2, 20 ) ) ) );
		$this->assertSame(
			array_merge( array( 'sku' ), array_keys( Columns::export_columns( array( 1, 2, 20 ) ) ) ),
			FakeWcCsv::auto_map( $headers )
		);
	}

	public function test_auto_mapping_is_case_insensitive(): void {
		$this->assertSame(
			array( 'bulktab:title:3', 'bulktab:content:3', Columns::ATTACHED ),
			FakeWcCsv::auto_map( array( 'bulktab 3 TITLE', 'BULKTAB 3 Content', 'bulktab ATTACHED' ) )
		);
	}

	public function test_auto_mapped_bad_number_parses_as_invalid_slot(): void {
		$mapped = FakeWcCsv::auto_map( array( 'BulkTab 25 title', 'BulkTab x content' ) );
		$this->assertSame( array( 'bulktab:title:25', 'bulktab:content:x' ), $mapped );
		$this->assertSame( 0, Columns::parse_id( $mapped[0] )['slot'] );
		$this->assertSame( 0, Columns::parse_id( $mapped[1] )['slot'] );
	}

	public function test_mapping_slots(): void {
		$this->assertSame( array( 3 ), Columns::mapping_slots( 'BulkTab 3 content' ) );
		$this->assertSame( array( 7 ), Columns::mapping_slots( 'bulktab:title:7' ) );
		$this->assertSame( range( 1, 20 ), Columns::mapping_slots( 'Size guide' ) );
		$this->assertSame( range( 1, 20 ), Columns::mapping_slots( 'Tab 0' ) );
		$this->assertSame( range( 1, 20 ), Columns::mapping_slots( 'Tab 99' ) );
	}

	public function test_normalize_slots(): void {
		$this->assertSame( array( 1, 2, 20 ), Columns::normalize_slots( array( '20', 2, 1, 2, 0, -3, 21, 'x' ) ) );
	}
}
