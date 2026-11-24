<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use BulkTab\Migration\YikesData;

/**
 * @covers \BulkTab\Migration\YikesData
 */
final class YikesDataTest extends TestCase {

	public function test_product_tabs_valid_list(): void {
		$tabs = YikesData::product_tabs(
			array(
				3 => array(
					'title'   => 'A',
					'id'      => 'a',
					'content' => 'x',
					'name'    => 'admin label',
				),
				7 => array(
					'title'   => 'B',
					'content' => 5,
				),
			)
		);
		$this->assertSame(
			array(
				array(
					'id'      => 'a',
					'title'   => 'A',
					'content' => 'x',
				),
				array(
					'id'      => '',
					'title'   => 'B',
					'content' => '5',
				),
			),
			$tabs
		);
	}

	/**
	 * @dataProvider no_tabs
	 *
	 * @param mixed $value Value.
	 */
	public function test_product_tabs_empty( $value ): void {
		$this->assertSame( array(), YikesData::product_tabs( $value ) );
	}

	public function no_tabs(): array {
		return array(
			'null'        => array( null ),
			'false'       => array( false ),
			'empty str'   => array( '' ),
			'empty array' => array( array() ),
		);
	}

	/**
	 * @dataProvider bad_shapes
	 *
	 * @param mixed $value Value.
	 */
	public function test_product_tabs_rejects_bad_shapes( $value ): void {
		$this->assertNull( YikesData::product_tabs( $value ) );
	}

	public function bad_shapes(): array {
		return array(
			'string'           => array( 'a:1:{...' ),
			'int'              => array( 5 ),
			'list of strings'  => array( array( 'x', 'y' ) ),
			'no title/content' => array( array( array( 'id' => 'a' ) ) ),
			'array content'    => array(
				array(
					array(
						'title'   => 'A',
						'content' => array( 'x' ),
					),
				),
			),
			'one bad element'  => array(
				array(
					array(
						'title'   => 'A',
						'content' => 'ok',
					),
					'junk',
				),
			),
		);
	}

	public function test_saved_tabs_are_unslashed_and_keyed_by_id(): void {
		$issues = array();
		$saved  = YikesData::saved_tabs(
			array(
				1     => array(
					'tab_id'      => 1,
					'tab_title'   => 'Shipping',
					'tab_name'    => 'Shipping (all)',
					'tab_content' => '<a href=\\"x\\">It\\\'s free</a>',
					'tab_slug'    => 'shipping',
					'taxonomies'  => array(),
					'global_tab'  => false,
				),
				'2'   => array(
					'tab_title'   => 'Care',
					'tab_content' => 'Wash cold',
					'global_tab'  => true,
				),
				'bad' => 'junk',
				4     => array(
					'tab_id'      => 4,
					'tab_title'   => array( 'x' ),
					'tab_content' => '',
				),
			),
			$issues
		);
		$this->assertSame( array( 1, 2 ), array_keys( $saved ) );
		$this->assertSame( '<a href="x">It\'s free</a>', $saved[1]['content'] );
		$this->assertSame( 'Shipping (all)', $saved[1]['name'] );
		$this->assertFalse( $saved[1]['has_rule'] );
		$this->assertTrue( $saved[2]['has_rule'] );
		$this->assertSame( '', $saved[2]['slug'] );
		$this->assertSame( array( 'skipped:bad', 'skipped:4' ), $issues );
		$this->assertSame( array(), YikesData::saved_tabs( false ) );
	}

	public function test_saved_tab_with_taxonomies_has_rule(): void {
		$saved = YikesData::saved_tabs(
			array(
				5 => array(
					'tab_id'      => 5,
					'tab_title'   => 'T',
					'tab_content' => 'c',
					'taxonomies'  => array( 'product_cat' => array( 12 => 'shoes' ) ),
				),
			)
		);
		$this->assertTrue( $saved[5]['has_rule'] );
	}

	public function test_applied_map(): void {
		$map = YikesData::applied_map(
			array(
				101   => array(
					1 => array(
						'post_id'         => 101,
						'reusable_tab_id' => 1,
						'tab_id'          => 'shipping',
					),
					2 => array(),
					3 => 'junk',
				),
				'102' => array(
					'4' => array( 'tab_id' => 'care' ),
				),
				'x'   => array( 1 => array( 'tab_id' => 'a' ) ),
				103   => array(),
				0     => array( 1 => array( 'tab_id' => 'a' ) ),
			)
		);
		$this->assertSame(
			array(
				101 => array( 1 => 'shipping' ),
				102 => array( 4 => 'care' ),
			),
			$map
		);
		$this->assertSame( array(), YikesData::applied_map( '' ) );
	}

	public function test_normalize(): void {
		$this->assertSame( "a\nb\nc", YikesData::normalize( "  a\r\nb\rc \n" ) );
	}
}
