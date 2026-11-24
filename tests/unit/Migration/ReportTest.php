<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use BulkTab\Migration\Mapper;
use BulkTab\Migration\ProductWriter;
use BulkTab\Migration\Report;

/**
 * @covers \BulkTab\Migration\Report
 */
final class ReportTest extends TestCase {

	private static function plan(): array {
		return Mapper::map_product(
			array(
				array(
					'id'      => 'a',
					'title'   => 'A',
					'content' => 'x',
				),
				array(
					'id'      => 'a',
					'title'   => 'A',
					'content' => 'y',
				),
				array(
					'id'      => 'b',
					'title'   => 'B',
					'content' => 'z',
				),
			),
			array(),
			array(),
			array()
		);
	}

	public function test_add_product_counts_and_issues(): void {
		$report = new Report();
		$report->add_product(
			7,
			'Shirt',
			self::plan(),
			array(
				'status'   => ProductWriter::WRITTEN,
				'slots'    => array( 1, 2 ),
				'overflow' => array( 'B' ),
				'errors'   => array(),
			)
		);
		$this->assertSame( 1, $report->get( 'products_written' ) );
		$this->assertSame( 3, $report->get( 'tabs_found' ) );
		$this->assertSame( 2, $report->get( 'custom_tabs' ) );
		$this->assertSame( 1, $report->get( 'hidden_tabs' ) );
		$this->assertSame( 1, $report->get( 'overflow' ) );
		$this->assertSame( array( 'duplicate_hidden', 'overflow' ), array_column( $report->issues(), 'code' ) );
		$this->assertSame( 7, $report->issues()[0]['product_id'] );
	}

	public function test_unchanged_and_conflict_do_not_count_tabs(): void {
		$report = new Report();
		$none   = array(
			'slots'    => array(),
			'overflow' => array(),
			'errors'   => array(),
		);
		$report->add_product( 1, 'A', self::plan(), array( 'status' => ProductWriter::UNCHANGED ) + $none );
		$report->add_product( 2, 'B', self::plan(), array( 'status' => ProductWriter::CONFLICT ) + $none );
		$this->assertSame( 1, $report->get( 'products_unchanged' ) );
		$this->assertSame( 1, $report->get( 'products_conflict' ) );
		$this->assertSame( 0, $report->get( 'tabs_found' ) );
		$this->assertSame( array( 'conflict' ), array_column( $report->issues(), 'code' ) );
	}

	public function test_round_trip_and_unknown_counter(): void {
		$report = new Report();
		$report->count( 'repaired', 3 );
		$report->count( 'nope' );
		$report->issue( 0, '', 'option_repaired', 'yikes_woo_reusable_products_tabs' );

		$copy = Report::from_array( $report->to_array() );
		$this->assertSame( 3, $copy->get( 'repaired' ) );
		$this->assertSame( 0, $copy->get( 'nope' ) );
		$this->assertSame( $report->issues(), $copy->issues() );
		$this->assertSame( 0, Report::from_array( 'junk' )->get( 'repaired' ) );
	}

	public function test_issue_cap(): void {
		$report = new Report();
		for ( $i = 0; $i < Report::MAX_ISSUES + 5; $i++ ) {
			$report->issue( $i, 'P', 'repaired' );
		}
		$this->assertCount( Report::MAX_ISSUES, $report->issues() );
		$this->assertSame( 5, $report->dropped() );
	}

	public function test_excerpt_is_short_single_line_valid_utf8(): void {
		$long = str_repeat( "é\n", 200 );
		$ex   = Report::excerpt( $long );
		$this->assertLessThanOrEqual( 123, strlen( $ex ) );
		$this->assertStringNotContainsString( "\n", $ex );
		$this->assertTrue( mb_check_encoding( $ex, 'UTF-8' ) );
		$this->assertSame( 'short', Report::excerpt( "  short \n" ) );
	}
}
