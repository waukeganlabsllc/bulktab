<?php
/**
 * R-4: unreadable tab data is never shown, reused, or overwritten.
 *
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use BulkTab\TabStore;

final class TabStoreUnreadableTest extends TestCase {

	/** @var InMemoryMetaStore */
	private $meta;

	/** @var TabStore */
	private $store;

	protected function setUp(): void {
		$this->meta  = new InMemoryMetaStore();
		$this->store = new TabStore( $this->meta );
	}

	private function corrupt( int $id, string $key ): void {
		$this->meta->unreadable[ $id ][ $key ] = true;
	}

	public function test_unreadable_slot_is_reported_and_not_listed(): void {
		$this->store->save_tabs( 7, array( 1 => array( 'title' => 'A', 'content' => 'a' ) ) );
		$this->corrupt( 7, '_bulktab_2_content' );
		$this->assertSame( array( 2 ), $this->store->unreadable_slots( 7 ) );
		$this->assertSame( array( 1 ), array_keys( $this->store->get_tabs( 7 ) ) );
	}

	public function test_unreadable_title_also_counts(): void {
		$this->corrupt( 7, '_bulktab_5_title' );
		$this->assertSame( array( 5 ), $this->store->unreadable_slots( 7 ) );
	}

	public function test_new_tab_skips_unreadable_slot(): void {
		$this->corrupt( 7, '_bulktab_1_content' );
		$errors = $this->store->save_tabs( 7, array( 0 => array( 'title' => 'New', 'content' => 'x' ) ) );
		$this->assertSame( array(), $errors );
		$this->assertSame( 'New', $this->meta->get( 7, '_bulktab_2_title' ) );
		$this->assertNull( $this->meta->get( 7, '_bulktab_1_title' ) );
		$this->assertTrue( $this->meta->has( 7, '_bulktab_1_content' ), 'raw data kept' );
	}

	public function test_explicit_write_to_unreadable_slot_is_refused(): void {
		$this->corrupt( 7, '_bulktab_3_content' );
		$errors = $this->store->save_tabs( 7, array( 3 => array( 'title' => 'T', 'content' => 'c' ) ) );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( "can't read", $errors[0] );
		$this->assertTrue( isset( $this->meta->unreadable[7]['_bulktab_3_content'] ), 'raw data kept' );
		$this->assertNull( $this->meta->get( 7, '_bulktab_3_title' ) );
	}

	public function test_delete_tab_clears_unreadable_slot_then_it_is_reusable(): void {
		$this->corrupt( 7, '_bulktab_1_content' );
		$this->store->delete_tab( 7, 1 );
		$this->assertSame( array(), $this->store->unreadable_slots( 7 ) );
		$this->store->save_tabs( 7, array( 0 => array( 'title' => 'N', 'content' => 'n' ) ) );
		$this->assertSame( 'N', $this->meta->get( 7, '_bulktab_1_title' ) );
	}

	public function test_all_slots_unreadable_means_no_room(): void {
		for ( $n = 1; $n <= TabStore::MAX_TABS; $n++ ) {
			$this->corrupt( 7, "_bulktab_{$n}_content" );
		}
		$errors = $this->store->save_tabs( 7, array( 0 => array( 'title' => 'X', 'content' => 'x' ) ) );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'limit', $errors[0] );
	}
}
