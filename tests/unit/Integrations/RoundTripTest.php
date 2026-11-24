<?php
/**
 * Export -> CSV bytes -> import, through the same escaping, quoting and header
 * mapping WooCommerce applies (FakeWcCsv).
 *
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Integrations;

use BulkTab\Integrations\Csv\Columns;
use BulkTab\Integrations\Csv\ExportRow;
use BulkTab\Integrations\Csv\RowImporter;
use BulkTab\TabStore;
use BulkTab\Tests\Unit\InMemoryMetaStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BulkTab\Integrations\Csv\Columns
 * @covers \BulkTab\Integrations\Csv\Cells
 * @covers \BulkTab\Integrations\Csv\ExportRow
 * @covers \BulkTab\Integrations\Csv\RowImporter
 */
final class RoundTripTest extends TestCase {

	/** @var InMemoryMetaStore */
	private $meta;

	/** @var TabStore */
	private $store;

	protected function setUp(): void {
		$this->meta  = new InMemoryMetaStore();
		$this->store = new TabStore( $this->meta );
	}

	private const TRICKY = array(
		1 => array(
			'Size "guide" & fit',
			"<table class=\"sizes\">\n<tr><th>Size</th><th>Chest (\"/cm)</th></tr>\r\n<tr><td>S</td><td>36\" / 91,4</td></tr>\n</table>\n\n<p>It's \"true\", 'quoted', a;b, c\\d \\\\ e</p>",
		),
		2 => array(
			'Ingrédients · 成分 · Состав',
			"<p>Crème brûlée, 日本語テキスト, emoji 🧵✂️, Ελληνικά, עברית, العربية</p>\n<ul>\n<li>– en dash, — em dash, “smart” ‘quotes’, non\xC2\xA0breaking</li>\n</ul>",
		),
		4 => array(
			'-50% sale',
			"- starts with a dash\n= then equals",
		),
		5 => array(
			'=HYPERLINK("http://evil")',
			"=SUM(A1:A2)\n[caption id=\"x\"]<img src=\"a.jpg\" alt=\"a, b\">[/caption]\n[product_page id=\"12\"]\n<!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph -->",
		),
		6 => array(
			'@mention',
			"\ttab-indented\n<script>console.log('kept for unfiltered users');</script>\na:2:{i:0;s:1:\"x\";} not unserialized",
		),
	);

	private function seed_product( int $id ): void {
		foreach ( self::TRICKY as $slot => $tab ) {
			$this->meta->data[ $id ][ "_bulktab_{$slot}_title" ]   = $tab[0];
			$this->meta->data[ $id ][ "_bulktab_{$slot}_content" ] = $tab[1];
		}
		$this->meta->data[ $id ]['_bulktab_attach'] = 'shipping,size-guide';
		$this->meta->data[ $id ]['_bulktab_hide']   = 'reviews,c:6';
		$this->meta->data[ $id ]['_bulktab_order']  = 'c:2,description,s:shipping,c:1';
	}

	private function export( array $ids ): string {
		$keys = array();
		foreach ( $ids as $id ) {
			$keys = array_merge( $keys, array_keys( $this->meta->data[ $id ] ?? array() ) );
		}
		$slots   = Columns::slots_from_meta_keys( $keys );
		$columns = Columns::expand(
			array(
				'id'      => 'ID',
				'sku'     => 'SKU',
				'bulktab' => 'BulkTab tabs',
				'name'    => 'Name',
			),
			$slots,
			true
		);
		$rows    = array();
		foreach ( $ids as $id ) {
			$lists = array();
			foreach ( Columns::LISTS as $key ) {
				$lists[ $key ] = $this->store->get_list( $id, $key );
			}
			$rows[] = array(
				'id'   => $id,
				'sku'  => 'SKU-' . $id,
				'name' => 'Product ' . $id,
			) + ExportRow::build( $this->store->get_tabs( $id ), $lists, $slots );
		}
		return FakeWcCsv::export( $columns, $rows );
	}

	private function tabs_and_lists( int $id ): array {
		$out = array( 'tabs' => array() );
		foreach ( $this->store->get_tabs( $id ) as $slot => $tab ) {
			$out['tabs'][ $slot ] = array( $tab['title'], $tab['content'] );
		}
		foreach ( Columns::LISTS as $key ) {
			$out[ $key ] = $this->store->get_list( $id, $key );
		}
		return $out;
	}

	public function test_export_never_contains_serialized_or_mangled_values(): void {
		$this->seed_product( 1 );
		$csv = $this->export( array( 1 ) );

		$this->assertStringStartsWith( "\xEF\xBB\xBFID,SKU,\"BulkTab 1 title\",\"BulkTab 1 content\",\"BulkTab 2 title\"", $csv );
		$this->assertStringContainsString( '"BulkTab attached","BulkTab hidden","BulkTab order",Name', $csv );
		$this->assertStringContainsString( '"shipping, size-guide"', $csv );
		$this->assertStringContainsString( '<table class=""sizes"">', $csv, 'HTML intact, quotes doubled by CSV quoting only.' );
		$this->assertStringNotContainsString( 'BulkTab 3', $csv, 'Unused slots get no columns.' );
		$this->assertStringContainsString( "\"'- starts with a dash", $csv, 'WooCommerce formula guard applied.' );
	}

	public function test_round_trip_into_a_fresh_product_is_exact(): void {
		$this->seed_product( 1 );
		$rows = FakeWcCsv::import( $this->export( array( 1 ) ) );
		$this->assertCount( 1, $rows );

		$importer = new RowImporter( $this->store );
		$result   = $importer->import( 2, $rows[0], array( 'shipping', 'size-guide' ) );

		$this->assertSame( array(), $result['messages'] );
		$this->assertSame( $this->tabs_and_lists( 1 ), $this->tabs_and_lists( 2 ) );
		foreach ( self::TRICKY as $slot => $tab ) {
			$this->assertSame( $tab[1], $this->meta->data[2][ "_bulktab_{$slot}_content" ], "Slot $slot byte-exact" );
		}
	}

	public function test_reimporting_own_export_changes_nothing(): void {
		$this->seed_product( 1 );
		$this->meta->data[1]['_bulktab_1_content_prev'] = 'undo copy';
		$rows      = FakeWcCsv::import( $this->export( array( 1 ) ) );
		$this->meta->log = array();

		$result = ( new RowImporter( $this->store ) )->import( 1, $rows[0], array( 'shipping', 'size-guide' ) );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( array(), $this->meta->log );
		$this->assertSame( 'undo copy', $this->meta->data[1]['_bulktab_1_content_prev'] );
	}

	public function test_products_without_tabs_round_trip_as_no_op(): void {
		$this->seed_product( 1 );
		$this->meta->data[3] = array();
		$rows = FakeWcCsv::import( $this->export( array( 1, 3 ) ) );
		$this->assertCount( 2, $rows );
		$result = ( new RowImporter( $this->store ) )->import( 3, $rows[1] );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( array(), $this->store->get_tabs( 3 ) );
	}

	/**
	 * What Excel / LibreOffice typically do on "Save as CSV": CRLF line endings for rows
	 * and inside cells, trailing spaces, values re-quoted, and the edits a user made.
	 */
	public function test_spreadsheet_edited_file(): void {
		$this->seed_product( 1 );
		$this->meta->data[1]['_bulktab_2_content_prev'] = 'older';
		$rows    = FakeWcCsv::import( $this->export( array( 1 ) ) );
		$headers = array_keys( $rows[0] );

		// Rebuild the file the way a spreadsheet writes it, applying user edits.
		$edited                        = $rows[0];
		$edited['bulktab:title:1']     = 'Size guide ';                       // Edited, trailing space.
		$edited['bulktab:content:2']  .= "\n<p>Nouveau : « été » ✓</p>";      // Edited content.
		$edited['bulktab:content:4']   = '[keep]';                            // Keep.
		$edited['bulktab:title:5']     = '';                                  // Cleared title.
		$edited['bulktab:content:6']   = '';                                  // Cleared content.
		$edited[ Columns::ATTACHED ]   = 'shipping; s:care';                  // Semicolons, ref form.
		$edited[ Columns::ORDER ]      = "c:1\nc:2\ndescription";             // One per line.

		$handle = fopen( 'php://memory', 'w+' );
		fwrite( $handle, "\xEF\xBB\xBF" );
		fwrite( $handle, implode( ',', array_map( array( $this, 'excel_quote' ), array_map( array( Columns::class, 'header' ), $headers ) ) ) . "\r\n" );
		fwrite( $handle, implode( ',', array_map( array( $this, 'excel_quote' ), array_map( array( FakeWcCsv::class, 'escape' ), array_values( $edited ) ) ) ) . "\r\n" );
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		$this->assertStringContainsString( "\r\n", $csv );
		$reimported = FakeWcCsv::import( $csv );
		$this->assertCount( 1, $reimported );

		$result = ( new RowImporter( $this->store ) )->import( 1, $reimported[0], array( 'shipping', 'size-guide', 'care' ) );
		$this->assertSame( array(), $result['messages'] );

		$m = $this->meta->data[1];
		$this->assertSame( 'Size guide', $m['_bulktab_1_title'] );
		$this->assertSame( self::TRICKY[1][1], $m['_bulktab_1_content'], 'CRLF-only difference: stored content kept as is.' );
		$this->assertArrayNotHasKey( '_bulktab_1_content_prev', $m, 'Unchanged content: no undo copy written.' );

		// Edited cells are stored as the spreadsheet wrote them (CRLF line endings).
		$this->assertSame( str_replace( "\n", "\r\n", self::TRICKY[2][1] . "\n<p>Nouveau : « été » ✓</p>" ), $m['_bulktab_2_content'] );
		$this->assertSame( self::TRICKY[2][1], $m['_bulktab_2_content_prev'], 'Edited content: previous version kept for undo.' );

		$this->assertSame( self::TRICKY[4][1], $m['_bulktab_4_content'] );
		$this->assertSame( '', $m['_bulktab_5_title'] );
		$this->assertSame( self::TRICKY[5][1], $m['_bulktab_5_content'] );
		$this->assertSame( '', $m['_bulktab_6_content'] );
		$this->assertSame( self::TRICKY[6][1], $m['_bulktab_6_content_prev'] );
		$this->assertSame( 'shipping,care', $m['_bulktab_attach'] );
		$this->assertSame( 'c:1,c:2,description', $m['_bulktab_order'] );
		$this->assertSame( 'reviews,c:6', $m['_bulktab_hide'] );
	}

	/**
	 * A user adds a new tab by typing new header cells into the spreadsheet.
	 */
	public function test_hand_written_columns_add_a_tab(): void {
		$csv  = "SKU,BulkTab 3 Title,bulktab 3 content,BulkTab order\r\n";
		$csv .= "A1,Care,\"<p>Wash at 30°, \"\"gentle\"\"</p>\r\n<p>Line two</p>\",\"c:3, description\"\r\n";
		$rows = FakeWcCsv::import( $csv );

		( new RowImporter( $this->store ) )->import( 9, $rows[0] );

		$this->assertSame( 'Care', $this->meta->data[9]['_bulktab_3_title'] );
		$this->assertSame( "<p>Wash at 30°, \"gentle\"</p>\r\n<p>Line two</p>", $this->meta->data[9]['_bulktab_3_content'] );
		$this->assertSame( 'c:3,description', $this->meta->data[9]['_bulktab_order'] );
	}

	/**
	 * Spreadsheet-style quoting: always quote, double inner quotes.
	 *
	 * @param mixed $value Cell.
	 */
	public function excel_quote( $value ): string {
		return '"' . str_replace( '"', '""', str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", (string) $value ) ) ) . '"';
	}
}
