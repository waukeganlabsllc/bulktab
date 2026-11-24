<?php
/**
 * Renaming a tab keeps WooCommerce's review count.
 *
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use BulkTab\Layout;

final class LayoutRenameTest extends TestCase {

	/**
	 * @dataProvider cases
	 */
	public function test_renamed_title( string $original, string $renamed, string $expected ): void {
		$this->assertSame( $expected, Layout::renamed_title( $original, $renamed ) );
	}

	public function cases(): array {
		return array(
			'keeps count'          => array( 'Reviews (3)', 'Customer reviews', 'Customer reviews (3)' ),
			'placeholder'          => array( 'Reviews (12)', '{count} reviews', '12 reviews' ),
			'placeholder no count' => array( 'Shipping', 'Ship {count}', 'Ship' ),
			'no count'             => array( 'Description', 'Details', 'Details' ),
			'already has count'    => array( 'Reviews (3)', 'Opinions (7)', 'Opinions (7)' ),
			'zero'                 => array( 'Reviews (0)', 'Ratings', 'Ratings (0)' ),
		);
	}

	public function test_apply_uses_it_for_reviews(): void {
		$tabs = array( 'reviews' => array( 'title' => 'Reviews (2)', 'priority' => 30 ) );
		$out  = Layout::apply( $tabs, array( 'reviews' => array( 'title' => 'Ratings' ) ), array(), array() );
		$this->assertSame( 'Ratings (2)', $out['reviews']['title'] );
	}
}
