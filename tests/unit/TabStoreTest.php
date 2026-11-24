<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use BulkTab\TabStore;

/**
 * @covers \BulkTab\TabStore
 */
final class TabStoreTest extends TestCase {

	private const PID = 101;

	/** @var InMemoryMetaStore */
	private $meta;

	/** @var TabStore */
	private $store;

	protected function setUp(): void {
		$this->meta  = new InMemoryMetaStore();
		$this->store = new TabStore( $this->meta );
	}

	private static function tab( string $title, string $content = '' ): array {
		return array(
			'title'   => $title,
			'content' => $content,
		);
	}

	private function seed( array $slots ): void {
		foreach ( $slots as $n ) {
			$this->meta->data[ self::PID ][ "_bulktab_{$n}_title" ]   = "T{$n}";
			$this->meta->data[ self::PID ][ "_bulktab_{$n}_content" ] = "C{$n}";
		}
	}

	private function sets_of( string $key ): int {
		$count = 0;
		foreach ( $this->meta->log as $entry ) {
			if ( 'set' === $entry[0] && $key === $entry[2] ) {
				++$count;
			}
		}
		return $count;
	}

	// ---- get_tabs ----------------------------------------------------------

	public function test_get_tabs_empty_product(): void {
		$this->assertSame( array(), $this->store->get_tabs( self::PID ) );
	}

	public function test_get_tabs_reads_slots_and_has_prev(): void {
		$this->meta->data[ self::PID ] = array(
			'_bulktab_2_title'         => 'Care',
			'_bulktab_2_content'       => 'Wash cold',
			'_bulktab_2_content_prev'  => 'Wash warm',
			'_bulktab_5_content'       => 'Only content',
			'_bulktab_7_title'         => 'Only title',
			'_bulktab_9_content_prev'  => 'orphan prev',
			'_bulktab_21_title'        => 'Out of range',
			'_bulktab_0_title'         => 'Out of range',
		);
		$this->meta->data[ self::PID + 1 ] = array( '_bulktab_1_title' => 'Other product' );

		$this->assertSame(
			array(
				2 => array(
					'slot'     => 2,
					'title'    => 'Care',
					'content'  => 'Wash cold',
					'has_prev' => true,
				),
				5 => array(
					'slot'     => 5,
					'title'    => '',
					'content'  => 'Only content',
					'has_prev' => false,
				),
				7 => array(
					'slot'     => 7,
					'title'    => 'Only title',
					'content'  => '',
					'has_prev' => false,
				),
			),
			$this->store->get_tabs( self::PID )
		);
	}

	// ---- save_tabs: slots --------------------------------------------------

	public function test_new_tab_takes_lowest_free_slot(): void {
		$this->seed( array( 1, 2, 4 ) );
		$this->assertSame( array(), $this->store->save_tabs( self::PID, array( 0 => self::tab( 'New', 'x' ) ) ) );
		$tabs = $this->store->get_tabs( self::PID );
		$this->assertSame( array( 1, 2, 3, 4 ), array_keys( $tabs ) );
		$this->assertSame( 'New', $tabs[3]['title'] );
		$this->assertFalse( $tabs[3]['has_prev'] );
	}

	public function test_several_new_tabs_fill_gaps_in_order(): void {
		$this->seed( array( 2, 4 ) );
		$errors = $this->store->save_tabs(
			self::PID,
			array(
				'new-a' => self::tab( 'A' ),
				-1      => self::tab( 'B' ),
				'new-c' => self::tab( 'C' ),
				99      => self::tab( 'D' ),
			)
		);
		$this->assertSame( array(), $errors );
		$titles = array_column( $this->store->get_tabs( self::PID ), 'title', 'slot' );
		$this->assertSame(
			array(
				1 => 'A',
				2 => 'T2',
				3 => 'B',
				4 => 'T4',
				5 => 'C',
				6 => 'D',
			),
			$titles
		);
	}

	public function test_new_tab_does_not_take_a_slot_explicitly_saved_in_same_call(): void {
		// Slot 1 is free but the same request saves slot 1 explicitly (listed after the new tab).
		$errors = $this->store->save_tabs(
			self::PID,
			array(
				0 => self::tab( 'New', 'new body' ),
				1 => self::tab( 'One', 'one body' ),
			)
		);
		$this->assertSame( array(), $errors );
		$tabs = $this->store->get_tabs( self::PID );
		$this->assertSame( 'One', $tabs[1]['title'] );
		$this->assertSame( 'one body', $tabs[1]['content'] );
		$this->assertFalse( $tabs[1]['has_prev'], 'no phantom prev from a colliding new tab' );
		$this->assertSame( 'New', $tabs[2]['title'] );
		$this->assertSame( 'new body', $tabs[2]['content'] );
	}

	public function test_explicit_slot_updates_only_that_slot(): void {
		$this->seed( array( 1, 2, 3 ) );
		$this->store->save_tabs( self::PID, array( 2 => self::tab( 'Two', 'C2' ) ) );
		$tabs = $this->store->get_tabs( self::PID );
		$this->assertSame( array( 'T1', 'Two', 'T3' ), array_column( $tabs, 'title' ) );
	}

	public function test_explicit_free_slot_is_used_as_is(): void {
		$this->store->save_tabs( self::PID, array( 7 => self::tab( 'Seven', 'x' ) ) );
		$this->assertSame( array( 7 ), array_keys( $this->store->get_tabs( self::PID ) ) );
	}

	public function test_missing_title_and_content_default_to_empty_strings(): void {
		$this->assertSame( array(), $this->store->save_tabs( self::PID, array( 0 => array() ) ) );
		$this->assertSame( '', $this->meta->get( self::PID, '_bulktab_1_title' ) );
		$this->assertSame( '', $this->meta->get( self::PID, '_bulktab_1_content' ) );
	}

	public function test_limit_of_20_tabs(): void {
		$this->seed( range( 1, TabStore::MAX_TABS ) );
		$before = $this->meta->data;

		$errors = $this->store->save_tabs( self::PID, array( 0 => self::tab( 'Too many', 'x' ) ) );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'Too many', $errors[0] );
		$this->assertStringContainsString( '20', $errors[0] );
		$this->assertSame( $before, $this->meta->data, 'nothing written' );
	}

	public function test_limit_reached_within_one_call(): void {
		$this->seed( range( 1, 19 ) );
		$errors = $this->store->save_tabs(
			self::PID,
			array(
				'n1' => self::tab( 'Fits' ),
				'n2' => self::tab( 'Over 1' ),
				'n3' => self::tab( 'Over 2' ),
			)
		);
		$this->assertCount( 2, $errors );
		$this->assertStringContainsString( 'Over 1', $errors[0] );
		$this->assertStringContainsString( 'Over 2', $errors[1] );
		$this->assertCount( 20, $this->store->get_tabs( self::PID ) );
		$this->assertSame( 'Fits', $this->store->get_tabs( self::PID )[20]['title'] );
	}

	public function test_existing_slot_can_be_saved_at_limit(): void {
		$this->seed( range( 1, TabStore::MAX_TABS ) );
		$this->assertSame( array(), $this->store->save_tabs( self::PID, array( 20 => self::tab( 'Edited', 'C20' ) ) ) );
		$this->assertSame( 'Edited', $this->store->get_tabs( self::PID )[20]['title'] );
	}

	// ---- prev / undo -------------------------------------------------------

	public function test_prev_saved_only_on_content_change(): void {
		$this->seed( array( 1 ) );

		// Title-only change: no prev.
		$this->store->save_tabs( self::PID, array( 1 => self::tab( 'Renamed', 'C1' ) ) );
		$this->assertNull( $this->meta->get( self::PID, '_bulktab_1_content_prev' ) );
		$this->assertSame( 0, $this->sets_of( '_bulktab_1_content_prev' ) );
		$this->assertFalse( $this->store->get_tabs( self::PID )[1]['has_prev'] );

		// Content change: prev = old content.
		$this->store->save_tabs( self::PID, array( 1 => self::tab( 'Renamed', 'C1 v2' ) ) );
		$this->assertSame( 'C1', $this->meta->get( self::PID, '_bulktab_1_content_prev' ) );
		$this->assertTrue( $this->store->get_tabs( self::PID )[1]['has_prev'] );

		// Same content again: prev untouched.
		$this->store->save_tabs( self::PID, array( 1 => self::tab( 'Renamed again', 'C1 v2' ) ) );
		$this->assertSame( 'C1', $this->meta->get( self::PID, '_bulktab_1_content_prev' ) );
		$this->assertSame( 1, $this->sets_of( '_bulktab_1_content_prev' ) );
	}

	public function test_prev_not_written_for_new_tab(): void {
		$this->store->save_tabs( self::PID, array( 0 => self::tab( 'New', 'body' ) ) );
		$this->assertNull( $this->meta->get( self::PID, '_bulktab_1_content_prev' ) );
	}

	public function test_prev_compares_byte_exact(): void {
		$this->meta->data[ self::PID ] = array(
			'_bulktab_1_title'   => 'T',
			'_bulktab_1_content' => 'a\\b',
		);
		$this->store->save_tabs( self::PID, array( 1 => self::tab( 'T', 'ab' ) ) );
		$this->assertSame( 'a\\b', $this->meta->get( self::PID, '_bulktab_1_content_prev' ) );
	}

	public function test_undo_swaps_and_can_be_undone(): void {
		$this->seed( array( 1 ) );
		$this->store->save_tabs( self::PID, array( 1 => self::tab( 'T1', 'new' ) ) );

		$this->assertTrue( $this->store->undo( self::PID, 1 ) );
		$this->assertSame( 'C1', $this->store->get_tabs( self::PID )[1]['content'] );
		$this->assertSame( 'new', $this->meta->get( self::PID, '_bulktab_1_content_prev' ) );

		$this->assertTrue( $this->store->undo( self::PID, 1 ) );
		$this->assertSame( 'new', $this->store->get_tabs( self::PID )[1]['content'] );
		$this->assertSame( 'C1', $this->meta->get( self::PID, '_bulktab_1_content_prev' ) );
	}

	public function test_undo_without_prev_returns_false_and_changes_nothing(): void {
		$this->seed( array( 1 ) );
		$before = $this->meta->data;
		$this->assertFalse( $this->store->undo( self::PID, 1 ) );
		$this->assertFalse( $this->store->undo( self::PID, 5 ) );
		$this->assertSame( $before, $this->meta->data );
	}

	public function test_undo_keeps_title(): void {
		$this->seed( array( 3 ) );
		$this->store->save_tabs( self::PID, array( 3 => self::tab( 'Renamed', 'changed' ) ) );
		$this->store->undo( self::PID, 3 );
		$this->assertSame( 'Renamed', $this->store->get_tabs( self::PID )[3]['title'] );
	}

	// ---- delete_tab --------------------------------------------------------

	public function test_delete_tab_removes_meta_and_refs(): void {
		$this->seed( array( 1, 2 ) );
		$this->store->save_tabs( self::PID, array( 1 => self::tab( 'T1', 'changed' ) ) );
		$this->meta->data[ self::PID ]['_bulktab_order']  = 'c:1,description,c:2,c:10,s:size';
		$this->meta->data[ self::PID ]['_bulktab_hide']   = 'reviews,c:1';
		$this->meta->data[ self::PID ]['_bulktab_attach'] = 'size';

		$this->store->delete_tab( self::PID, 1 );

		$this->assertSame( array( 2 ), array_keys( $this->store->get_tabs( self::PID ) ) );
		$this->assertNull( $this->meta->get( self::PID, '_bulktab_1_title' ) );
		$this->assertNull( $this->meta->get( self::PID, '_bulktab_1_content' ) );
		$this->assertNull( $this->meta->get( self::PID, '_bulktab_1_content_prev' ) );
		$this->assertSame( 'description,c:2,c:10,s:size', $this->meta->get( self::PID, '_bulktab_order' ) );
		$this->assertSame( 'reviews', $this->meta->get( self::PID, '_bulktab_hide' ) );
		$this->assertSame( 'size', $this->meta->get( self::PID, '_bulktab_attach' ) );
	}

	public function test_delete_tab_deletes_list_that_becomes_empty(): void {
		$this->seed( array( 4 ) );
		$this->meta->data[ self::PID ]['_bulktab_hide'] = 'c:4';
		$this->store->delete_tab( self::PID, 4 );
		$this->assertNull( $this->meta->get( self::PID, '_bulktab_hide' ) );
		$this->assertSame( array(), $this->meta->data[ self::PID ] );
	}

	public function test_delete_tab_leaves_lists_alone_when_not_referenced(): void {
		$this->seed( array( 1 ) );
		$this->meta->data[ self::PID ]['_bulktab_order'] = 'reviews,c:11';
		$this->store->delete_tab( self::PID, 1 );
		$this->assertSame( 'reviews,c:11', $this->meta->get( self::PID, '_bulktab_order' ) );
		$this->assertSame( 0, $this->sets_of( '_bulktab_order' ) );
	}

	public function test_deleted_slot_is_reused_by_next_new_tab(): void {
		$this->seed( array( 1, 2, 3 ) );
		$this->store->delete_tab( self::PID, 2 );
		$this->store->save_tabs( self::PID, array( 0 => self::tab( 'Reuse', 'r' ) ) );
		$tabs = $this->store->get_tabs( self::PID );
		$this->assertSame( 'Reuse', $tabs[2]['title'] );
		$this->assertFalse( $tabs[2]['has_prev'], 'old prev must not leak into the new tab' );
	}

	public function test_orphaned_prev_is_cleared_when_slot_is_reused(): void {
		$this->meta->data[ self::PID ]['_bulktab_3_content_prev'] = 'stale';
		$this->store->save_tabs( self::PID, array( 3 => self::tab( 'Fresh', 'f' ) ) );
		$this->assertFalse( $this->store->get_tabs( self::PID )[3]['has_prev'] );
		$this->assertFalse( $this->store->undo( self::PID, 3 ) );
	}

	// ---- lists -------------------------------------------------------------

	public function test_lists_round_trip_and_cleaning(): void {
		$this->assertSame( array(), $this->store->get_list( self::PID, '_bulktab_order' ) );
		$this->assertTrue( $this->store->set_list( self::PID, '_bulktab_order', array( 'reviews', ' c:1 ', 'bad ref', 'reviews', 's:size-guide' ) ) );
		$this->assertSame( 'reviews,c:1,s:size-guide', $this->meta->get( self::PID, '_bulktab_order' ) );
		$this->assertSame( array( 'reviews', 'c:1', 's:size-guide' ), $this->store->get_list( self::PID, '_bulktab_order' ) );

		$this->assertTrue( $this->store->set_list( self::PID, '_bulktab_order', array( '', 'bad ref' ) ) );
		$this->assertNull( $this->meta->get( self::PID, '_bulktab_order' ) );
	}

	// ---- content fidelity --------------------------------------------------

	/**
	 * @dataProvider provide_content
	 */
	public function test_content_round_trips_byte_exact( string $content ): void {
		$title = 'Title ' . $content;
		$this->assertSame( array(), $this->store->save_tabs( self::PID, array( 0 => self::tab( $title, $content ) ) ) );
		$tab = $this->store->get_tabs( self::PID )[1];
		$this->assertSame( $content, $tab['content'] );
		$this->assertSame( $title, $tab['title'] );

		// And through prev/undo.
		$this->store->save_tabs( self::PID, array( 1 => self::tab( $title, 'other' ) ) );
		$this->store->undo( self::PID, 1 );
		$this->assertSame( $content, $this->store->get_tabs( self::PID )[1]['content'] );
	}

	public function provide_content(): array {
		return array(
			'backslashes'      => array( 'C:\\Users\\shop\\new \\n not a newline \\\\ double' ),
			'quotes'           => array( 'He said "hi" & it\'s <b>bold</b> \\"escaped\\"' ),
			'unicode'          => array( 'Größe · 尺码 · размер · مقاس' ),
			'emoji'            => array( "Fits \u{1F455}\u{1F3FD} family \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} flag \u{1F1FA}\u{1F1E6}" ),
			'json-like'        => array( '{"a":"b\\"c","d":[1,2]}' ),
			'shortcode/html'   => array( "[product_table id=\"3\"]\n<table><tr><td>S</td></tr></table>\r\n\t" ),
			'whitespace edges' => array( "  \n leading and trailing \n  " ),
			'nul and binary'   => array( "a\0b\x7f\xc3\xa9" ),
			'slashed already'  => array( addslashes( 'O\'Reilly \\ "x"' ) ),
			'percent'          => array( '100% cotton, %s %d %1$s' ),
		);
	}

	// ---- failing storage ---------------------------------------------------

	public function test_failing_content_write_reports_error(): void {
		$this->meta->fail_when = static function ( $id, $key ) {
			return '_bulktab_1_content' === $key;
		};
		$errors = $this->store->save_tabs( self::PID, array( 0 => self::tab( 'T', 'body' ) ) );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'content was not saved', $errors[0] );
	}

	public function test_failing_title_write_reports_error(): void {
		$this->meta->fail_when = static function ( $id, $key ) {
			return '_bulktab_1_title' === $key;
		};
		$errors = $this->store->save_tabs( self::PID, array( 0 => self::tab( 'T', 'body' ) ) );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'title was not saved', $errors[0] );
	}

	public function test_failing_prev_write_reports_error_and_keeps_current_content(): void {
		$this->seed( array( 1 ) );
		$this->meta->fail_when = static function ( $id, $key ) {
			return '_bulktab_1_content_prev' === $key;
		};
		$errors = $this->store->save_tabs( self::PID, array( 1 => self::tab( 'T1', 'replacement' ) ) );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'previous version', $errors[0] );
		// Without a backup the old content must not be overwritten (it would be lost for good).
		$this->assertSame( 'C1', $this->meta->get( self::PID, '_bulktab_1_content' ) );
	}

	public function test_everything_failing_reports_every_tab(): void {
		$this->seed( array( 1 ) );
		$this->meta->fail_when = static function () {
			return true;
		};
		$errors = $this->store->save_tabs(
			self::PID,
			array(
				1     => self::tab( 'T1', 'changed' ),
				'new' => self::tab( 'N', 'n' ),
			)
		);
		$this->assertGreaterThanOrEqual( 4, count( $errors ) );
		$joined = implode( "\n", $errors );
		$this->assertStringContainsString( 'tab 1', $joined );
		$this->assertStringContainsString( 'Tab 2', $joined );
		$this->assertSame( 'C1', $this->meta->get( self::PID, '_bulktab_1_content' ) );
	}

	public function test_failing_store_makes_undo_and_set_list_fail(): void {
		$this->seed( array( 1 ) );
		$this->meta->data[ self::PID ]['_bulktab_1_content_prev'] = 'old';
		$this->meta->fail_when                                   = static function () {
			return true;
		};
		$this->assertFalse( $this->store->undo( self::PID, 1 ) );
		$this->assertSame( 'C1', $this->meta->get( self::PID, '_bulktab_1_content' ) );
		$this->assertSame( 'old', $this->meta->get( self::PID, '_bulktab_1_content_prev' ) );
		$this->assertFalse( $this->store->set_list( self::PID, '_bulktab_order', array( 'reviews' ) ) );
	}

	public function test_undo_partial_failure_does_not_lose_content(): void {
		$this->seed( array( 1 ) );
		$this->meta->data[ self::PID ]['_bulktab_1_content_prev'] = 'old';
		$this->meta->fail_when                                   = static function ( $id, $key ) {
			return '_bulktab_1_content_prev' === $key;
		};
		$this->assertFalse( $this->store->undo( self::PID, 1 ) );
		// Both versions must still exist somewhere.
		$values = array( $this->meta->get( self::PID, '_bulktab_1_content' ), $this->meta->get( self::PID, '_bulktab_1_content_prev' ) );
		sort( $values );
		$this->assertSame( array( 'C1', 'old' ), $values );
	}

	public function test_products_are_isolated(): void {
		$this->store->save_tabs( 1, array( 0 => self::tab( 'A', 'a' ) ) );
		$this->store->save_tabs( 2, array( 0 => self::tab( 'B', 'b' ) ) );
		$this->assertSame( 'A', $this->store->get_tabs( 1 )[1]['title'] );
		$this->assertSame( 'B', $this->store->get_tabs( 2 )[1]['title'] );
		$this->store->delete_tab( 1, 1 );
		$this->assertSame( array(), $this->store->get_tabs( 1 ) );
		$this->assertCount( 1, $this->store->get_tabs( 2 ) );
	}
}
