<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Integrations;

use BulkTab\Integrations\Csv\Columns;
use BulkTab\Integrations\Csv\ExportRow;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BulkTab\Integrations\Csv\ExportRow
 */
final class ExportRowTest extends TestCase {

	public function test_build_fills_every_column_in_header_order(): void {
		$tabs  = array(
			2 => array(
				'slot'     => 2,
				'title'    => 'Size guide',
				'content'  => "<table>\n<tr><td>S</td></tr>\n</table>",
				'has_prev' => false,
			),
		);
		$lists = array(
			'_bulktab_attach' => array( 'shipping', 'care' ),
			'_bulktab_hide'   => array( 'reviews' ),
			'_bulktab_order'  => array( 'c:2', 'description', 's:shipping' ),
		);

		$row = ExportRow::build( $tabs, $lists, array( 1, 2 ) );

		$this->assertSame( array_keys( Columns::export_columns( array( 1, 2 ) ) ), array_keys( $row ) );
		$this->assertSame( '', $row['bulktab:title:1'] );
		$this->assertSame( '', $row['bulktab:content:1'] );
		$this->assertSame( 'Size guide', $row['bulktab:title:2'] );
		$this->assertSame( "<table>\n<tr><td>S</td></tr>\n</table>", $row['bulktab:content:2'] );
		$this->assertSame( 'shipping, care', $row[ Columns::ATTACHED ] );
		$this->assertSame( 'reviews', $row[ Columns::HIDDEN ] );
		$this->assertSame( 'c:2, description, s:shipping', $row[ Columns::ORDER ] );
	}

	public function test_values_are_plain_strings_never_serialized(): void {
		$tabs = array(
			1 => array(
				'title'   => 'Spec "A" & <b>B</b>',
				'content' => 'a:1:{i:0;s:3:"foo";}',
			),
		);
		$row  = ExportRow::build( $tabs, array(), array( 1 ) );
		foreach ( $row as $value ) {
			$this->assertIsString( $value );
		}
		$this->assertSame( 'Spec "A" & <b>B</b>', $row['bulktab:title:1'] );
		$this->assertSame( 'a:1:{i:0;s:3:"foo";}', $row['bulktab:content:1'], 'Content is passed through untouched.' );
	}

	public function test_empty_product(): void {
		$this->assertSame(
			array(
				'bulktab:attached' => '',
				'bulktab:hidden'   => '',
				'bulktab:order'    => '',
			),
			ExportRow::build( array(), array(), array() )
		);
	}
}
