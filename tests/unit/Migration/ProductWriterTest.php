<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use BulkTab\Migration\Mapper;
use BulkTab\Migration\ProductWriter;
use BulkTab\Migration\Serialized;
use BulkTab\Migration\YikesData;
use BulkTab\TabStore;
use BulkTab\Tests\Unit\InMemoryMetaStore;

/**
 * @covers \BulkTab\Migration\ProductWriter
 */
final class ProductWriterTest extends TestCase {

	private const PID = 501;
	private const NOW = 1764000000;

	/** @var InMemoryMetaStore */
	private $meta;

	/** @var ProductWriter */
	private $writer;

	protected function setUp(): void {
		$this->meta   = new InMemoryMetaStore();
		$this->writer = new ProductWriter( $this->meta );
	}

	private static function saved(): array {
		return array(
			1 => array(
				'id'       => 1,
				'title'    => 'Shipping',
				'name'     => '',
				'content'  => 'Free shipping.',
				'slug'     => 'shipping',
				'has_rule' => false,
			),
		);
	}

	private static function yikes( array $titles_contents ): array {
		$out = array();
		foreach ( $titles_contents as $title => $content ) {
			$out[] = array(
				'title'   => (string) $title,
				'id'      => strtolower( (string) $title ),
				'content' => $content,
			);
		}
		return $out;
	}

	/** Full pipeline for one product, like Migrator::process_product(). */
	private function import( string $raw, array $applied = array(), bool $dry = false, int $now = self::NOW ): array {
		$tabs = YikesData::product_tabs( Serialized::decode( $raw )['value'] );
		$plan = Mapper::map_product( $tabs, $applied, self::saved(), array( 1 => 'shipping' ) );
		return $this->writer->apply( self::PID, $plan, Mapper::source_hash( $raw, $applied, self::saved(), array( 1 => 'shipping' ) ), $now, $dry );
	}

	private function get( string $key ): ?string {
		return $this->meta->get( self::PID, $key );
	}

	private function writes(): int {
		return count(
			array_filter(
				$this->meta->log,
				static function ( $e ) {
					return 'set' === $e[0];
				}
			)
		);
	}

	public function test_writes_slots_order_attach_and_marker(): void {
		$raw = serialize( self::yikes( array( 'Specs' => '<b>S</b>', 'Shipping' => 'Free shipping.', 'Manual' => 'M' ) ) );
		$r   = $this->import( $raw, array( 1 => 'shipping' ) );

		$this->assertSame( ProductWriter::WRITTEN, $r['status'] );
		$this->assertSame( array( 1, 2 ), $r['slots'] );
		$this->assertSame( 'Specs', $this->get( '_bulktab_1_title' ) );
		$this->assertSame( '<b>S</b>', $this->get( '_bulktab_1_content' ) );
		$this->assertSame( 'Manual', $this->get( '_bulktab_2_title' ) );
		$this->assertNull( $this->get( '_bulktab_3_title' ), 'shared copy is not duplicated as a product tab' );
		$this->assertSame( 'shipping', $this->get( '_bulktab_attach' ) );
		$this->assertNull( $this->get( '_bulktab_hide' ) );
		$this->assertSame( 'description,additional_information,c:1,s:shipping,c:2,reviews', $this->get( '_bulktab_order' ) );

		$marker = ProductWriter::parse_marker( $this->get( ProductWriter::MARKER ) );
		$this->assertSame( self::NOW, $marker['time'] );
		$this->assertSame( Mapper::source_hash( $raw, array( 1 => 'shipping' ), self::saved(), array( 1 => 'shipping' ) ), $marker['source'] );
		$this->assertSame( array( 's:shipping', 'c:1', 'c:2' ), $marker['refs'] );
	}

	public function test_drifted_copy_written_as_own_tab_with_shared_hidden(): void {
		$raw = serialize( self::yikes( array( 'Shipping' => 'Ships from our EU warehouse.' ) ) );
		$this->import( $raw, array( 1 => 'shipping' ) );

		$this->assertSame( 'Ships from our EU warehouse.', $this->get( '_bulktab_1_content' ) );
		$this->assertSame( 'shipping', $this->get( '_bulktab_attach' ) );
		$this->assertSame( 's:shipping', $this->get( '_bulktab_hide' ) );
		$this->assertSame( 'description,additional_information,c:1,reviews', $this->get( '_bulktab_order' ) );
	}

	public function test_hidden_items_and_builtin_replacement(): void {
		$raw = serialize(
			array(
				array(
					'title'   => '',
					'id'      => '',
					'content' => 'no title',
				),
				array(
					'title'   => 'Reviews',
					'id'      => 'reviews',
					'content' => 'custom reviews',
				),
			)
		);
		$this->import( $raw );
		$this->assertSame( 'reviews,c:1', $this->get( '_bulktab_hide' ) );
		$this->assertSame( array( 'c:1', 'c:2', 'reviews' ), ProductWriter::parse_marker( $this->get( ProductWriter::MARKER ) )['refs'] );

		// A re-import after a YIKES change un-hides the built-in it no longer replaces.
		$this->import( serialize( self::yikes( array( 'Specs' => 'S' ) ) ), array(), false, self::NOW + 1 );
		$this->assertNull( $this->get( '_bulktab_hide' ) );
	}

	public function test_dry_run_writes_nothing_but_reports(): void {
		$tabs = array();
		for ( $i = 1; $i <= 22; $i++ ) {
			$tabs[ "T$i" ] = "C$i";
		}
		$r = $this->import( serialize( self::yikes( $tabs ) ), array(), true );

		$this->assertSame( ProductWriter::WRITTEN, $r['status'] );
		$this->assertCount( 20, $r['slots'] );
		$this->assertSame( array( 'T21', 'T22' ), $r['overflow'] );
		$this->assertSame( array(), $this->meta->log );
	}

	public function test_more_than_twenty_tabs_overflow_is_reported(): void {
		$tabs = array();
		for ( $i = 1; $i <= 23; $i++ ) {
			$tabs[ "T$i" ] = "C$i";
		}
		$r = $this->import( serialize( self::yikes( $tabs ) ) );

		$this->assertSame( ProductWriter::WRITTEN, $r['status'] );
		$this->assertSame( array( 'T21', 'T22', 'T23' ), $r['overflow'] );
		$this->assertSame( 'T20', $this->get( '_bulktab_20_title' ) );
		$this->assertNull( $this->get( '_bulktab_21_title' ) );
	}

	public function test_native_tabs_are_kept_and_new_tabs_use_free_slots(): void {
		$store = new TabStore( $this->meta );
		$store->save_tabs(
			self::PID,
			array(
				1 => array(
					'title'   => 'Native',
					'content' => 'N',
				),
			)
		);
		$store->set_list( self::PID, '_bulktab_order', array( 'c:1', 'description' ) );

		$this->import( serialize( self::yikes( array( 'Specs' => 'S' ) ) ) );

		$this->assertSame( 'Native', $this->get( '_bulktab_1_title' ) );
		$this->assertSame( 'Specs', $this->get( '_bulktab_2_title' ) );
		$this->assertSame( 'description,additional_information,c:2,reviews,c:1', $this->get( '_bulktab_order' ) );
	}

	public function test_rerun_unchanged_is_skipped_without_writes(): void {
		$raw = serialize( self::yikes( array( 'Specs' => 'S', 'Shipping' => 'Free shipping.' ) ) );
		$this->import( $raw, array( 1 => 'shipping' ) );
		$before = $this->meta->data;
		$this->meta->log = array();

		$r = $this->import( $raw, array( 1 => 'shipping' ), false, self::NOW + 100 );

		$this->assertSame( ProductWriter::UNCHANGED, $r['status'] );
		$this->assertSame( 0, $this->writes() );
		$this->assertSame( $before, $this->meta->data );
	}

	public function test_rerun_after_yikes_change_replaces_only_imported_tabs(): void {
		$this->import( serialize( self::yikes( array( 'Specs' => 'S', 'Manual' => 'M' ) ) ) );
		// A tab added in BulkTab after the import.
		( new TabStore( $this->meta ) )->save_tabs(
			self::PID,
			array(
				5 => array(
					'title'   => 'Added later',
					'content' => 'L',
				),
			)
		);

		$r = $this->import( serialize( self::yikes( array( 'Specs' => 'S2' ) ) ), array(), false, self::NOW + 100 );

		$this->assertSame( ProductWriter::WRITTEN, $r['status'] );
		$this->assertSame( 'S2', $this->get( '_bulktab_1_content' ) );
		$this->assertNull( $this->get( '_bulktab_2_title' ), 'tab removed in YIKES is removed' );
		$this->assertSame( 'Added later', $this->get( '_bulktab_5_title' ) );
		$this->assertSame( 1, preg_match_all( '/c:1/', (string) $this->get( '_bulktab_order' ) ) );
		$this->assertSame( self::NOW + 100, ProductWriter::parse_marker( $this->get( ProductWriter::MARKER ) )['time'] );
	}

	public function test_rerun_after_shared_link_removed_detaches(): void {
		$this->import( serialize( self::yikes( array( 'Shipping' => 'Free shipping.' ) ) ), array( 1 => 'shipping' ) );
		$this->assertSame( 'shipping', $this->get( '_bulktab_attach' ) );

		$this->import( serialize( self::yikes( array( 'Specs' => 'S' ) ) ), array(), false, self::NOW + 1 );

		$this->assertNull( $this->get( '_bulktab_attach' ) );
		$this->assertSame( 'description,additional_information,c:1,reviews', $this->get( '_bulktab_order' ) );
	}

	public function test_yikes_change_does_not_overwrite_bulktab_edits(): void {
		$this->import( serialize( self::yikes( array( 'Specs' => 'S' ) ) ) );
		( new TabStore( $this->meta ) )->save_tabs(
			self::PID,
			array(
				1 => array(
					'title'   => 'Specs',
					'content' => 'Edited in BulkTab',
				),
			)
		);

		$r = $this->import( serialize( self::yikes( array( 'Specs' => 'Edited in YIKES' ) ) ), array(), false, self::NOW + 1 );

		$this->assertSame( ProductWriter::CONFLICT, $r['status'] );
		$this->assertSame( 'Edited in BulkTab', $this->get( '_bulktab_1_content' ) );
	}

	public function test_product_without_tabs_writes_nothing(): void {
		$r = $this->writer->apply(
			self::PID,
			Mapper::map_product( array(), array(), array(), array() ),
			str_repeat( 'a', 40 ),
			self::NOW,
			false
		);
		$this->assertSame( ProductWriter::NOTHING, $r['status'] );
		$this->assertSame( array(), $this->meta->log );
	}

	public function test_write_failure_is_reported_and_not_marked(): void {
		$this->meta->fail_when = static function ( $id, $key ) {
			return '_bulktab_1_content' === $key;
		};
		$r = $this->import( serialize( self::yikes( array( 'Specs' => 'S' ) ) ) );

		$this->assertSame( ProductWriter::ERROR, $r['status'] );
		$this->assertNotEmpty( $r['errors'] );
		$this->assertNull( $this->get( ProductWriter::MARKER ) );
	}

	public function test_corrupted_source_imports_repaired_text(): void {
		$raw = str_replace( 'old.test', 'new-shop.example', serialize( self::yikes( array( 'Manual' => '<a href="https://old.test/m.pdf">PDF</a>' ) ) ) );
		$this->import( $raw );
		$this->assertSame( '<a href="https://new-shop.example/m.pdf">PDF</a>', $this->get( '_bulktab_1_content' ) );
	}

	public function test_slashes_are_stored_byte_for_byte(): void {
		$content = 'C:\\path and <span data-x=\\"1\\">';
		$this->import( serialize( self::yikes( array( 'Specs' => $content ) ) ) );
		$this->assertSame( $content, $this->get( '_bulktab_1_content' ) );
	}

	/**
	 * @dataProvider bad_markers
	 */
	public function test_parse_marker_rejects_garbage( ?string $value ): void {
		$this->assertNull( ProductWriter::parse_marker( $value ) );
	}

	public function bad_markers(): array {
		return array(
			'null'      => array( null ),
			'empty'     => array( '' ),
			'timestamp' => array( '1764000000' ),
			'bad hash'  => array( '1764000000:xyz:abc:c:1' ),
			'bad time'  => array( 'abc:' . str_repeat( 'a', 40 ) . ':x:c:1' ),
		);
	}
}
