<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use BulkTab\Rules;

/**
 * @covers \BulkTab\Rules
 */
final class RulesTest extends TestCase {

	public function test_empty_rules_shape(): void {
		$this->assertSame(
			array(
				'v'     => 2,
				'all'   => false,
				'tax'   => '',
				'terms' => array(),
			),
			Rules::empty_rules()
		);
	}

	/**
	 * @dataProvider provide_empty_inputs
	 * @param mixed $raw Input.
	 */
	public function test_empty_inputs_give_empty_rules_without_errors( $raw ): void {
		$errors = array();
		$this->assertSame( Rules::empty_rules(), Rules::parse( $raw, $errors ) );
		$this->assertSame( array(), $errors );
	}

	public function provide_empty_inputs(): array {
		return array(
			'null'         => array( null ),
			'empty string' => array( '' ),
			'empty array'  => array( array() ),
			'json {}'      => array( '{}' ),
			'json []'      => array( '[]' ),
		);
	}

	public function test_all_products(): void {
		$rules = Rules::parse( '{"v":2,"all":true,"tax":"product_cat","terms":[3]}' );
		$this->assertTrue( $rules['all'] );
		$this->assertSame( '', $rules['tax'] );
		$this->assertSame( array(), $rules['terms'] );
	}

	public function test_terms_rule_round_trips(): void {
		$rules  = Rules::for_terms( 'product_cat', array( 12, '15', 12, -1, 'x' ) );
		$errors = array();
		$this->assertSame( array( 12, 15 ), $rules['terms'] );
		$this->assertSame( $rules, Rules::parse( Rules::to_json( $rules ), $errors ) );
		$this->assertSame( array(), $errors );
		$this->assertFalse( Rules::is_manual_only( $rules ) );
	}

	/**
	 * @dataProvider provide_invalid
	 * @param mixed  $raw  Input.
	 * @param string $code Expected error.
	 */
	public function test_invalid_rules_fall_back_to_manual_only( $raw, string $code ): void {
		$errors = array();
		$rules  = Rules::parse( $raw, $errors );
		$this->assertSame( Rules::empty_rules(), $rules );
		$this->assertTrue( Rules::is_manual_only( $rules ) );
		$this->assertSame( array( $code ), $errors );
	}

	public function provide_invalid(): array {
		return array(
			'bad json'          => array( '{nope', 'invalid_json' ),
			'not array'         => array( 42, 'invalid_type' ),
			'other version'     => array( '{"v":1,"all":true}', 'unsupported_version' ),
			'unknown taxonomy'  => array( array( 'tax' => 'pa_color', 'terms' => array( 1 ) ), 'unknown_taxonomy' ),
			'taxonomy no terms' => array( array( 'tax' => 'product_cat', 'terms' => array() ), 'no_terms' ),
			'too many terms'    => array( array( 'tax' => 'product_tag', 'terms' => range( 1, Rules::MAX_TERMS + 1 ) ), 'too_many_terms' ),
		);
	}

	public function test_errors_are_appended(): void {
		$errors = array( 'earlier' );
		Rules::parse( 'nope', $errors );
		$this->assertSame( array( 'earlier', 'invalid_json' ), $errors );
	}
}
