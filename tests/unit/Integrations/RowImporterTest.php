<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Integrations;

use BulkTab\Integrations\Csv\Columns;
use BulkTab\Integrations\Csv\RowImporter;
use BulkTab\TabStore;
use BulkTab\Tests\Unit\InMemoryMetaStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BulkTab\Integrations\Csv\RowImporter
 */
final class RowImporterTest extends TestCase {

	private const PID = 42;

	/** @var InMemoryMetaStore */
	private $meta;

	/** @var TabStore */
	private $store;

	/** @var string[] Values the content sanitizer was called with. */
	private $sanitized = array();

	protected function setUp(): void {
		$this->meta  = new InMemoryMetaStore();
		$this->store = new TabStore( $this->meta );
	}

	private function importer(): RowImporter {
		return new RowImporter(
			$this->store,
			static function ( string $v ): string {
				return trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $v ) ) ); // Like sanitize_text_field().
			},
			function ( string $v ): string {
				$this->sanitized[] = $v;
				return (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $v ); // Stand-in for kses.
			}
		);
	}

	private function seed( int $slot, string $title, string $content ): void {
		$this->meta->data[ self::PID ][ "_bulktab_{$slot}_title" ]   = $title;
		$this->meta->data[ self::PID ][ "_bulktab_{$slot}_content" ] = $content;
	}

	private function meta( string $key ): ?string {
		return $this->meta->data[ self::PID ][ $key ] ?? null;
	}

	private function codes( array $result ): array {
		return array_map(
			static function ( $m ) {
				return $m['code'];
			},
			$result['messages']
		);
	}

	private function writes(): array {
		return array_values(
			array_filter(
				$this->meta->log,
				static function ( $entry ) {
					return 'set' === $entry[0] || 'delete' === $entry[0];
				}
			)
		);
	}

	// ---- no-op cases ------------------------------------------------------

	public function test_row_without_our_columns_changes_nothing(): void {
		$this->seed( 1, 'Specs', 'x' );
		$result = $this->importer()->import( self::PID, array( 'sku' => 'A1', 'name' => 'Shirt' ) );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( array(), $result['messages'] );
		$this->assertSame( array(), $this->meta->log );
		$this->assertFalse( RowImporter::has_columns( array( 'meta:_bulktab_1_title' => 'x' ) ) );
		$this->assertTrue( RowImporter::has_columns( array( Columns::ORDER => '' ) ) );
	}

	public function test_absent_columns_leave_fields_untouched(): void {
		$this->seed( 1, 'Specs', '<p>Old</p>' );
		$this->seed( 2, 'Care', '<p>Wash cold</p>' );
		$this->meta->data[ self::PID ]['_bulktab_order'] = 'c:2,c:1';

		$result = $this->importer()->import( self::PID, array( 'bulktab:title:1' => 'Specifications' ) );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'Specifications', $this->meta( '_bulktab_1_title' ) );
		$this->assertSame( '<p>Old</p>', $this->meta( '_bulktab_1_content' ), 'Content column absent.' );
		$this->assertNull( $this->meta( '_bulktab_1_content_prev' ), 'Content did not change, so no undo copy.' );
		$this->assertSame( 'Care', $this->meta( '_bulktab_2_title' ), 'Slot 2 columns absent.' );
		$this->assertSame( 'c:2,c:1', $this->meta( '_bulktab_order' ), 'Order column absent.' );
	}

	public function test_identical_row_writes_nothing(): void {
		$this->seed( 1, 'Specs', "<p>A</p>\n<p>B</p>" );
		$this->meta->data[ self::PID ]['_bulktab_attach'] = 'shipping,care';
		$this->meta->data[ self::PID ]['_bulktab_hide']   = 'reviews';

		$result = $this->importer()->import(
			self::PID,
			array(
				'bulktab:title:1'   => 'Specs',
				'bulktab:content:1' => "<p>A</p>\r\n<p>B</p>\r\n", // Spreadsheet line endings.
				'bulktab:title:2'   => '',
				'bulktab:content:2' => '',
				Columns::ATTACHED   => 'shipping, care',
				Columns::HIDDEN     => 'reviews',
				Columns::ORDER      => '',
			)
		);

		$this->assertFalse( $result['changed'] );
		$this->assertSame( array(), $this->writes() );
		$this->assertSame( array(), $this->sanitized, 'Unchanged content is not re-filtered.' );
	}

	// ---- [keep] ------------------------------------------------------------

	public function test_keep_leaves_each_field_untouched(): void {
		$this->seed( 1, 'Specs', '<p>Old</p>' );
		$this->meta->data[ self::PID ]['_bulktab_attach'] = 'care';
		$this->meta->data[ self::PID ]['_bulktab_hide']   = 'reviews';
		$this->meta->data[ self::PID ]['_bulktab_order']  = 'c:1';

		$result = $this->importer()->import(
			self::PID,
			array(
				'bulktab:title:1'   => '[keep]',
				'bulktab:content:1' => ' [keep] ',
				'bulktab:title:3'   => '[keep]',
				'bulktab:content:3' => '[KEEP]',
				Columns::ATTACHED   => '[keep]',
				Columns::HIDDEN     => '[keep]',
				Columns::ORDER      => '[keep]',
			)
		);

		$this->assertFalse( $result['changed'] );
		$this->assertSame( array(), $this->writes() );
		$this->assertSame( array(), $result['messages'] );
		$this->assertArrayNotHasKey( 3, $this->store->get_tabs( self::PID ), '[keep] on a missing slot creates nothing.' );
	}

	public function test_keep_title_with_new_content(): void {
		$this->seed( 1, 'Specs', '<p>Old</p>' );
		$this->importer()->import(
			self::PID,
			array(
				'bulktab:title:1'   => '[keep]',
				'bulktab:content:1' => '<p>New</p>',
			)
		);
		$this->assertSame( 'Specs', $this->meta( '_bulktab_1_title' ) );
		$this->assertSame( '<p>New</p>', $this->meta( '_bulktab_1_content' ) );
		$this->assertSame( '<p>Old</p>', $this->meta( '_bulktab_1_content_prev' ) );
	}

	// ---- empty cells clear ---------------------------------------------------

	public function test_empty_title_clears_title_only(): void {
		$this->seed( 1, 'Specs', '<p>Old</p>' );
		$this->importer()->import( self::PID, array( 'bulktab:title:1' => '' ) );
		$this->assertSame( '', $this->meta( '_bulktab_1_title' ) );
		$this->assertSame( '<p>Old</p>', $this->meta( '_bulktab_1_content' ) );
	}

	public function test_empty_content_clears_and_keeps_undo_copy(): void {
		$this->seed( 1, 'Specs', '<p>Old</p>' );
		$this->importer()->import( self::PID, array( 'bulktab:content:1' => '   ' ) );
		$this->assertSame( 'Specs', $this->meta( '_bulktab_1_title' ) );
		$this->assertSame( '', $this->meta( '_bulktab_1_content' ) );
		$this->assertSame( '<p>Old</p>', $this->meta( '_bulktab_1_content_prev' ) );
	}

	public function test_clearing_both_fields_keeps_slot_so_undo_works(): void {
		$this->seed( 1, 'Specs', '<p>Old</p>' );
		$this->meta->data[ self::PID ]['_bulktab_order'] = 'c:1,description';

		$this->importer()->import(
			self::PID,
			array(
				'bulktab:title:1'   => '',
				'bulktab:content:1' => '',
			)
		);

		$tabs = $this->store->get_tabs( self::PID );
		$this->assertSame( '', $tabs[1]['title'] );
		$this->assertSame( '', $tabs[1]['content'] );
		$this->assertTrue( $tabs[1]['has_prev'] );
		$this->assertTrue( $this->store->undo( self::PID, 1 ) );
		$this->assertSame( '<p>Old</p>', $this->meta( '_bulktab_1_content' ) );
		$this->assertSame( 'c:1,description', $this->meta( '_bulktab_order' ) );
	}

	public function test_empty_list_cell_clears_list(): void {
		$this->meta->data[ self::PID ]['_bulktab_attach'] = 'care';
		$this->meta->data[ self::PID ]['_bulktab_hide']   = 'reviews';
		$result = $this->importer()->import(
			self::PID,
			array(
				Columns::ATTACHED => '',
				Columns::HIDDEN   => ' , ',
			)
		);
		$this->assertTrue( $result['changed'] );
		$this->assertNull( $this->meta( '_bulktab_attach' ) );
		$this->assertNull( $this->meta( '_bulktab_hide' ) );
	}

	// ---- creating and changing tabs --------------------------------------------

	public function test_new_slot_is_created_at_its_number(): void {
		$this->meta->data[ self::PID ]['_bulktab_5_content_prev'] = 'orphan';
		$this->importer()->import(
			self::PID,
			array(
				'bulktab:title:5'   => 'Manual',
				'bulktab:content:5' => '<a href="https://example.com/m.pdf">PDF</a>',
			)
		);
		$tabs = $this->store->get_tabs( self::PID );
		$this->assertSame( array( 5 ), array_keys( $tabs ) );
		$this->assertSame( 'Manual', $tabs[5]['title'] );
		$this->assertSame( '<a href="https://example.com/m.pdf">PDF</a>', $tabs[5]['content'] );
		$this->assertNull( $this->meta( '_bulktab_5_content_prev' ), 'A new tab does not inherit an orphaned undo copy.' );
	}

	public function test_new_slot_with_title_only(): void {
		$this->importer()->import( self::PID, array( 'bulktab:title:1' => 'Coming soon' ) );
		$this->assertSame( 'Coming soon', $this->meta( '_bulktab_1_title' ) );
		$this->assertSame( '', $this->meta( '_bulktab_1_content' ) );
	}

	public function test_blank_cells_for_missing_slot_create_nothing(): void {
		$result = $this->importer()->import(
			self::PID,
			array(
				'bulktab:title:4'   => '',
				'bulktab:content:4' => " \n ",
			)
		);
		$this->assertFalse( $result['changed'] );
		$this->assertSame( array(), $this->store->get_tabs( self::PID ) );
	}

	public function test_changed_content_keeps_previous_in_undo_copy(): void {
		$this->seed( 1, 'Specs', '<p>Old</p>' );
		$this->importer()->import( self::PID, array( 'bulktab:content:1' => '<p>New</p>' ) );
		$this->assertSame( '<p>New</p>', $this->meta( '_bulktab_1_content' ) );
		$this->assertSame( '<p>Old</p>', $this->meta( '_bulktab_1_content_prev' ) );
	}

	public function test_title_is_sanitized(): void {
		$this->importer()->import( self::PID, array( 'bulktab:title:1' => "  <b>Size</b>\nguide " ) );
		$this->assertSame( 'Size guide', $this->meta( '_bulktab_1_title' ) );
	}

	public function test_changed_content_is_sanitized_with_warning(): void {
		$this->importer()->import( self::PID, array( 'bulktab:content:1' => '<p>Hi</p><script>alert(1)</script>' ) );
		$this->assertSame( '<p>Hi</p>', $this->meta( '_bulktab_1_content' ) );

		$result = $this->importer()->import( self::PID, array( 'bulktab:content:2' => '<p>x</p><script>y</script>' ) );
		$this->assertSame( array( 'content_filtered' ), $this->codes( $result ) );
		$this->assertSame( array( 2 ), $result['messages'][0]['args'] );
		$this->assertSame( 'warning', $result['messages'][0]['level'] );
	}

	public function test_unchanged_unfiltered_content_is_not_refiltered(): void {
		// Stored by an administrator with unfiltered_html; re-imported by someone without it.
		$this->seed( 1, 'Video', '<iframe src="https://example.com/v"></iframe><script>track()</script>' );
		$result = $this->importer()->import(
			self::PID,
			array( 'bulktab:content:1' => '<iframe src="https://example.com/v"></iframe><script>track()</script>' )
		);
		$this->assertFalse( $result['changed'] );
		$this->assertSame( '<iframe src="https://example.com/v"></iframe><script>track()</script>', $this->meta( '_bulktab_1_content' ) );
		$this->assertSame( array(), $this->sanitized );
	}

	public function test_content_equal_to_stored_after_sanitizing_is_not_rewritten(): void {
		$this->seed( 1, 'Specs', '<p>Hi</p>' );
		$result = $this->importer()->import( self::PID, array( 'bulktab:content:1' => '<p>Hi</p><script>x</script>' ) );
		$this->assertFalse( $result['changed'] );
		$this->assertNull( $this->meta( '_bulktab_1_content_prev' ) );
		$this->assertSame( array( 'content_filtered' ), $this->codes( $result ) );
	}

	public function test_default_sanitizers_are_identity(): void {
		$importer = new RowImporter( $this->store );
		$importer->import( self::PID, array( 'bulktab:title:1' => '<b>T</b>' ) );
		$this->assertSame( '<b>T</b>', $this->meta( '_bulktab_1_title' ) );
	}

	public function test_backslashes_and_slashes_survive(): void {
		$content = 'C:\\path\\to\\file and \\"quoted\\" and \\\\ double';
		$this->importer()->import( self::PID, array( 'bulktab:content:1' => $content ) );
		$this->assertSame( $content, $this->meta( '_bulktab_1_content' ) );
	}

	// ---- bad slots -------------------------------------------------------------

	public function test_out_of_range_slot_warns_and_is_ignored(): void {
		$result = $this->importer()->import(
			self::PID,
			array(
				'bulktab:title:21'  => 'Too many',
				'bulktab:content:0' => 'Nope',
				'bulktab:title:x'   => '',
				'bulktab:title:99'  => '[keep]',
			)
		);
		$this->assertSame( array( 'bad_slot', 'bad_slot' ), $this->codes( $result ) );
		$this->assertSame( array( 'BulkTab 21 title' ), $result['messages'][0]['args'] );
		$this->assertSame( array( 'BulkTab 0 content' ), $result['messages'][1]['args'] );
		$this->assertSame( array(), $this->store->get_tabs( self::PID ) );
	}

	public function test_non_scalar_values_are_ignored(): void {
		$this->seed( 1, 'Specs', 'x' );
		$result = $this->importer()->import( self::PID, array( 'bulktab:title:1' => array( 'a' ) ) );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( 'Specs', $this->meta( '_bulktab_1_title' ) );
	}

	// ---- lists -------------------------------------------------------------------

	public function test_attached_list_parsing(): void {
		$result = $this->importer()->import(
			self::PID,
			array( Columns::ATTACHED => " shipping ;s:care,\r\nshipping, bad key!, warranty" ),
			array( 'shipping', 'care' )
		);
		$this->assertSame( 'shipping,care,warranty', $this->meta( '_bulktab_attach' ) );
		$this->assertSame( array( 'bad_ref', 'unknown_shared' ), $this->codes( $result ) );
		$this->assertSame( array( 'BulkTab attached', 'bad key!' ), $result['messages'][0]['args'] );
		$this->assertSame( array( 'BulkTab attached', 'warranty' ), $result['messages'][1]['args'] );
	}

	public function test_unknown_shared_check_skipped_without_index(): void {
		$result = $this->importer()->import( self::PID, array( Columns::ATTACHED => 'anything' ) );
		$this->assertSame( array(), $result['messages'] );
		$this->assertSame( 'anything', $this->meta( '_bulktab_attach' ) );
	}

	public function test_hidden_and_order_refs(): void {
		$this->seed( 1, 'Specs', 'x' );
		$result = $this->importer()->import(
			self::PID,
			array(
				'bulktab:title:3'   => 'New',
				'bulktab:content:3' => 'y',
				Columns::HIDDEN     => 'reviews, s:old-promo, c:9',
				Columns::ORDER      => 'c:3, s:shipping, description, c:1, x:extra_tab, c:2',
			),
			array( 'shipping' )
		);

		$this->assertSame( 'reviews,s:old-promo', $this->meta( '_bulktab_hide' ) );
		$this->assertSame( 'c:3,s:shipping,description,c:1,x:extra_tab', $this->meta( '_bulktab_order' ) );
		$this->assertSame( array( 'unknown_shared', 'missing_slot_ref', 'missing_slot_ref' ), $this->codes( $result ) );
		$this->assertSame( array( 'BulkTab hidden', 'old-promo' ), $result['messages'][0]['args'] );
		$this->assertSame( array( 'BulkTab hidden', 'c:9' ), $result['messages'][1]['args'] );
		$this->assertSame( array( 'BulkTab order', 'c:2' ), $result['messages'][2]['args'] );
	}

	public function test_list_cell_with_only_invalid_items_leaves_list_untouched(): void {
		$this->meta->data[ self::PID ]['_bulktab_order']  = 'description,reviews';
		$this->meta->data[ self::PID ]['_bulktab_attach'] = 'care';
		$result = $this->importer()->import(
			self::PID,
			array(
				Columns::ORDER    => 'c:9',
				Columns::ATTACHED => 'not a key!',
			)
		);
		$this->assertFalse( $result['changed'] );
		$this->assertSame( 'description,reviews', $this->meta( '_bulktab_order' ) );
		$this->assertSame( 'care', $this->meta( '_bulktab_attach' ) );
		$this->assertSame( array( 'bad_ref', 'missing_slot_ref' ), $this->codes( $result ) );
	}

	public function test_unchanged_list_is_not_written(): void {
		$this->meta->data[ self::PID ]['_bulktab_order'] = 'description,reviews';
		$result = $this->importer()->import( self::PID, array( Columns::ORDER => 'description; reviews; description' ) );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( array(), $this->writes() );
	}

	// ---- errors from TabStore are reported -------------------------------------------

	public function test_store_errors_are_returned(): void {
		$this->seed( 1, 'Specs', '<p>Old</p>' );
		$this->meta->fail_when = static function ( $id, $key ) {
			return '_bulktab_1_content_prev' === $key || '_bulktab_hide' === $key;
		};

		$result = $this->importer()->import(
			self::PID,
			array(
				'bulktab:content:1' => '<p>New</p>',
				Columns::HIDDEN     => 'reviews',
			)
		);

		$this->assertSame( '<p>Old</p>', $this->meta( '_bulktab_1_content' ), 'Without an undo copy the content is not overwritten.' );
		$this->assertContains( 'store', $this->codes( $result ) );
		$this->assertContains( 'list_not_saved', $this->codes( $result ) );
		foreach ( $result['messages'] as $m ) {
			$this->assertSame( 'error', $m['level'] );
			if ( 'list_not_saved' === $m['code'] ) {
				$this->assertSame( array( 'BulkTab hidden' ), $m['args'] );
			} else {
				$this->assertIsString( $m['args'][0] );
				$this->assertNotSame( '', $m['args'][0] );
			}
		}
	}

	// ---- plan() is side-effect free -------------------------------------------------

	public function test_plan_does_not_write(): void {
		$plan = $this->importer()->plan(
			array(
				'bulktab:title:2'   => 'A',
				'bulktab:content:2' => 'B',
				Columns::ORDER      => 'c:2',
			),
			array(),
			array()
		);
		$this->assertSame(
			array(
				2 => array(
					'title'   => 'A',
					'content' => 'B',
				),
			),
			$plan['save']
		);
		$this->assertSame( array( '_bulktab_order' => array( 'c:2' ) ), $plan['lists'] );
		$this->assertSame( array(), $this->meta->log );
	}
}
