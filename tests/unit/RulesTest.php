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

	private static function cond( string $tax, array $terms, bool $not = false ): array {
		return array(
			'tax'   => $tax,
			'terms' => $terms,
			'not'   => $not,
		);
	}

	private static function rules( array $overrides = array() ): array {
		return array_merge( Rules::empty_rules(), $overrides );
	}

	private static function pro_limits(): array {
		return array(
			'groups'          => 5,
			'conditions'      => 5,
			'not'             => true,
			'include_exclude' => true,
			'taxonomies'      => Rules::TAXONOMIES,
		);
	}

	public function test_empty_rules_shape(): void {
		$this->assertSame(
			array(
				'v'       => 1,
				'all'     => false,
				'groups'  => array(),
				'include' => array(),
				'exclude' => array(),
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

	public function test_parse_valid_json(): void {
		$json   = '{"v":1,"all":false,"groups":[[{"tax":"product_cat","terms":[12],"not":false},{"tax":"product_tag","terms":[7],"not":true}]],"include":[501],"exclude":[880]}';
		$errors = array();
		$rules  = Rules::parse( $json, $errors );

		$this->assertSame( array(), $errors );
		$this->assertSame(
			array(
				'v'       => 1,
				'all'     => false,
				'groups'  => array(
					array(
						self::cond( 'product_cat', array( 12 ) ),
						self::cond( 'product_tag', array( 7 ), true ),
					),
				),
				'include' => array( 501 ),
				'exclude' => array( 880 ),
			),
			$rules
		);
	}

	public function test_parse_valid_array_equals_json(): void {
		$array = array(
			'v'       => 1,
			'all'     => true,
			'groups'  => array(),
			'include' => array( 3 ),
			'exclude' => array( 4 ),
		);
		$this->assertSame( Rules::parse( $array ), Rules::parse( json_encode( $array ) ) );
		$this->assertTrue( Rules::parse( $array )['all'] );
	}

	public function test_missing_version_defaults_to_current(): void {
		$errors = array();
		$rules  = Rules::parse( array( 'all' => true ), $errors );
		$this->assertSame( array(), $errors );
		$this->assertSame( Rules::VERSION, $rules['v'] );
		$this->assertTrue( $rules['all'] );
	}

	/**
	 * @dataProvider provide_invalid_json
	 */
	public function test_invalid_json( string $raw ): void {
		$errors = array();
		$this->assertSame( Rules::empty_rules(), Rules::parse( $raw, $errors ) );
		$this->assertSame( array( 'invalid_json' ), $errors );
	}

	public function provide_invalid_json(): array {
		return array(
			'garbage'      => array( 'not json' ),
			'truncated'    => array( '{"v":1,"all":' ),
			'json scalar'  => array( '42' ),
			'json string'  => array( '"x"' ),
			'json null'    => array( 'null' ),
			'json true'    => array( 'true' ),
			'single quote' => array( "{'v':1}" ),
		);
	}

	/**
	 * @dataProvider provide_invalid_types
	 * @param mixed $raw Input.
	 */
	public function test_invalid_type( $raw ): void {
		$errors = array();
		$this->assertSame( Rules::empty_rules(), Rules::parse( $raw, $errors ) );
		$this->assertSame( array( 'invalid_type' ), $errors );
	}

	public function provide_invalid_types(): array {
		return array(
			'int'    => array( 5 ),
			'true'   => array( true ),
			'object' => array( new \stdClass() ),
		);
	}

	public function test_future_version_is_rejected_whole(): void {
		$errors = array();
		$rules  = Rules::parse(
			array(
				'v'   => 2,
				'all' => true,
			),
			$errors
		);
		$this->assertSame( Rules::empty_rules(), $rules );
		$this->assertSame( array( 'unsupported_version' ), $errors );
		$this->assertTrue( Rules::is_manual_only( $rules ) );
	}

	public function test_unknown_taxonomy_drops_whole_group_but_keeps_others(): void {
		$errors = array();
		$rules  = Rules::parse(
			array(
				'groups' => array(
					array(
						self::cond( 'product_cat', array( 1 ) ),
						self::cond( 'pa_color', array( 2 ) ),
					),
					array( self::cond( 'product_tag', array( 3 ) ) ),
				),
			),
			$errors
		);
		$this->assertSame( array( 'unknown_taxonomy' ), $errors );
		$this->assertSame( array( array( self::cond( 'product_tag', array( 3 ) ) ) ), $rules['groups'] );
	}

	/**
	 * @dataProvider provide_broken_groups
	 * @param mixed $group Group.
	 */
	public function test_broken_groups_are_dropped( $group, string $code ): void {
		$errors = array();
		$rules  = Rules::parse( array( 'groups' => array( $group ) ), $errors );
		$this->assertSame( array(), $rules['groups'] );
		$this->assertSame( array( $code ), $errors );
	}

	public function provide_broken_groups(): array {
		$many = range( 1, Rules::MAX_TERMS + 1 );
		return array(
			'empty group'         => array( array(), 'empty_group' ),
			'group is string'     => array( 'product_cat', 'empty_group' ),
			'cond is string'      => array( array( 'product_cat' ), 'unknown_taxonomy' ),
			'cond without tax'    => array( array( array( 'terms' => array( 1 ) ) ), 'unknown_taxonomy' ),
			'tax not a string'    => array( array( array( 'tax' => array( 'product_cat' ), 'terms' => array( 1 ) ) ), 'unknown_taxonomy' ),
			'tax case mismatch'   => array( array( self::cond( 'Product_Cat', array( 1 ) ) ), 'unknown_taxonomy' ),
			'condition not group' => array( self::cond( 'product_cat', array( 1 ) ), 'unknown_taxonomy' ),
			'empty terms'         => array( array( self::cond( 'product_cat', array() ) ), 'no_terms' ),
			'missing terms'       => array( array( array( 'tax' => 'product_cat' ) ), 'no_terms' ),
			'terms not array'     => array( array( array( 'tax' => 'product_cat', 'terms' => 5 ) ), 'no_terms' ),
			'only invalid terms'  => array( array( self::cond( 'product_cat', array( 0, -1, 'abc', null ) ) ), 'no_terms' ),
			'too many terms'      => array( array( self::cond( 'product_cat', $many ) ), 'too_many_terms' ),
			'one bad cond in AND' => array(
				array(
					self::cond( 'product_cat', array( 1 ) ),
					self::cond( 'product_tag', array() ),
				),
				'no_terms',
			),
		);
	}

	public function test_max_terms_is_allowed(): void {
		$errors = array();
		$rules  = Rules::parse( array( 'groups' => array( array( self::cond( 'product_cat', range( 1, Rules::MAX_TERMS ) ) ) ) ), $errors );
		$this->assertSame( array(), $errors );
		$this->assertCount( Rules::MAX_TERMS, $rules['groups'][0][0]['terms'] );
	}

	public function test_terms_are_cast_deduped_and_cleaned(): void {
		$rules = Rules::parse(
			array(
				'groups' => array(
					array(
						array(
							'tax'   => 'product_brand',
							'terms' => array( '12', 12, 7, '7', 0, -3, 'x', null, true, array( 9 ), 5.0, ' 8' ),
							'not'   => 'yes',
						),
					),
				),
			)
		);
		$cond = $rules['groups'][0][0];
		$this->assertSame( array( 12, 7, 5, 8 ), $cond['terms'] );
		$this->assertTrue( $cond['not'] );
	}

	/**
	 * @dataProvider provide_not_values
	 * @param mixed $not Raw not.
	 */
	public function test_not_is_cast_to_bool( $not, bool $expected ): void {
		$cond = array(
			'tax'   => 'product_cat',
			'terms' => array( 1 ),
		);
		if ( '__missing__' !== $not ) {
			$cond['not'] = $not;
		}
		$rules = Rules::parse( array( 'groups' => array( array( $cond ) ) ) );
		$this->assertSame( $expected, $rules['groups'][0][0]['not'] );
	}

	public function provide_not_values(): array {
		return array(
			'missing' => array( '__missing__', false ),
			'false'   => array( false, false ),
			'0'       => array( 0, false ),
			'"0"'     => array( '0', false ),
			'true'    => array( true, true ),
			'1'       => array( 1, true ),
		);
	}

	public function test_extra_keys_are_dropped(): void {
		$rules = Rules::parse(
			array(
				'v'      => 1,
				'scope'  => 'terms',
				'groups' => array(
					array(
						array(
							'tax'   => 'product_cat',
							'terms' => array( 1 ),
							'extra' => 'x',
						),
					),
				),
			)
		);
		$this->assertSame( array( 'v', 'all', 'groups', 'include', 'exclude' ), array_keys( $rules ) );
		$this->assertSame( array( 'tax', 'terms', 'not' ), array_keys( $rules['groups'][0][0] ) );
	}

	/**
	 * @dataProvider provide_id_lists
	 * @param mixed $raw Raw list.
	 */
	public function test_include_exclude_cleaning( $raw, array $expected ): void {
		$rules = Rules::parse(
			array(
				'include' => $raw,
				'exclude' => $raw,
			)
		);
		$this->assertSame( $expected, $rules['include'] );
		$this->assertSame( $expected, $rules['exclude'] );
	}

	public function provide_id_lists(): array {
		return array(
			'ints'          => array( array( 1, 2, 3 ), array( 1, 2, 3 ) ),
			'numeric str'   => array( array( '10', '20' ), array( 10, 20 ) ),
			'dupes'         => array( array( 5, '5', 5, 6 ), array( 5, 6 ) ),
			'zero/negative' => array( array( 0, -1, '-2', 4 ), array( 4 ) ),
			'junk'          => array( array( 'a', null, false, array( 1 ), '', 7 ), array( 7 ) ),
			'not array'     => array( '1,2,3', array() ),
			'int scalar'    => array( 5, array() ),
			'null'          => array( null, array() ),
			'assoc keys'    => array( array( 'a' => 9, 'b' => 8 ), array( 9, 8 ) ),
		);
	}

	public function test_all_flag_cast(): void {
		$this->assertTrue( Rules::parse( array( 'all' => 1 ) )['all'] );
		$this->assertTrue( Rules::parse( array( 'all' => 'true' ) )['all'] );
		$this->assertFalse( Rules::parse( array( 'all' => 0 ) )['all'] );
		$this->assertFalse( Rules::parse( array( 'all' => '' ) )['all'] );
	}

	public function test_groups_not_array_is_ignored(): void {
		$errors = array();
		$rules  = Rules::parse( array( 'groups' => 'product_cat:1' ), $errors );
		$this->assertSame( array(), $rules['groups'] );
		$this->assertSame( array(), $errors );
	}

	public function test_errors_are_appended_not_replaced(): void {
		$errors = array( 'earlier' );
		Rules::parse( 'nope', $errors );
		$this->assertSame( array( 'earlier', 'invalid_json' ), $errors );
	}

	// ---- check_limits ------------------------------------------------------

	/**
	 * @dataProvider provide_limit_cases
	 */
	public function test_check_limits( array $rules, array $free_expected, array $pro_expected ): void {
		$rules = self::rules( $rules );
		$this->assertSame( $free_expected, Rules::check_limits( $rules, Rules::free_limits() ), 'free' );
		$this->assertSame( $pro_expected, Rules::check_limits( $rules, self::pro_limits() ), 'pro' );
	}

	public function provide_limit_cases(): array {
		$c       = self::cond( 'product_cat', array( 1, 2, 3 ) );
		$not     = self::cond( 'product_tag', array( 4 ), true );
		$five    = array_fill( 0, 5, $c );
		$six     = array_fill( 0, 6, $c );
		$g5x5    = array_fill( 0, 5, $five );
		$g6      = array_fill( 0, 6, array( $c ) );
		$g_by_6c = array( $six );
		return array(
			'empty (manual)'          => array( array(), array(), array() ),
			'all products'            => array( array( 'all' => true ), array(), array() ),
			'one cond many terms'     => array( array( 'groups' => array( array( $c ) ) ), array(), array() ),
			'brand cond'              => array( array( 'groups' => array( array( self::cond( 'product_brand', array( 9 ) ) ) ) ), array(), array() ),
			'two groups'              => array( array( 'groups' => array( array( $c ), array( $c ) ) ), array( 'limit_groups' ), array() ),
			'two conds in a group'    => array( array( 'groups' => array( array( $c, $c ) ) ), array( 'limit_conditions' ), array() ),
			'not'                     => array( array( 'groups' => array( array( $not ) ) ), array( 'limit_not' ), array() ),
			'include'                 => array( array( 'include' => array( 1 ) ), array( 'limit_include_exclude' ), array() ),
			'exclude'                 => array( array( 'exclude' => array( 1 ) ), array( 'limit_include_exclude' ), array() ),
			'all + exclude'           => array(
				array(
					'all'     => true,
					'exclude' => array( 1 ),
				),
				array( 'limit_include_exclude' ),
				array(),
			),
			'5x5'                     => array( array( 'groups' => $g5x5 ), array( 'limit_groups', 'limit_conditions' ), array() ),
			'6 groups'                => array( array( 'groups' => $g6 ), array( 'limit_groups' ), array( 'limit_groups' ) ),
			'6 conds'                 => array( array( 'groups' => $g_by_6c ), array( 'limit_conditions' ), array( 'limit_conditions' ) ),
			'all with groups'         => array(
				array(
					'all'    => true,
					'groups' => array( array( $c ) ),
				),
				array( 'all_with_groups' ),
				array( 'all_with_groups' ),
			),
			'everything wrong (free)' => array(
				array(
					'all'     => true,
					'groups'  => array( array( $c, $not ), array( $not ) ),
					'include' => array( 2 ),
				),
				array( 'limit_groups', 'limit_conditions', 'limit_not', 'limit_include_exclude', 'all_with_groups' ),
				array( 'all_with_groups' ),
			),
		);
	}

	public function test_check_limits_taxonomy_restriction(): void {
		$limits               = self::pro_limits();
		$limits['taxonomies'] = array( 'product_cat' );
		$rules                = self::rules( array( 'groups' => array( array( self::cond( 'product_brand', array( 1 ) ) ) ) ) );
		$this->assertSame( array( 'limit_taxonomy' ), Rules::check_limits( $rules, $limits ) );
	}

	public function test_check_limits_tolerates_partial_limits(): void {
		$rules = self::rules( array( 'groups' => array( array( self::cond( 'product_cat', array( 1 ) ) ) ) ) );
		// A filter that only bumps one key must not cause notices; missing keys fall back to Free.
		$this->assertSame( array(), Rules::check_limits( $rules, array( 'groups' => 5 ) ) );
		$rules['groups'][] = array( self::cond( 'product_cat', array( 1 ) ), self::cond( 'product_tag', array( 2 ), true ) );
		$this->assertSame( array( 'limit_conditions', 'limit_not' ), Rules::check_limits( $rules, array( 'groups' => 5 ) ) );
	}

	// ---- is_manual_only ----------------------------------------------------

	/**
	 * @dataProvider provide_manual_only
	 */
	public function test_is_manual_only( array $rules, bool $expected ): void {
		$this->assertSame( $expected, Rules::is_manual_only( self::rules( $rules ) ) );
	}

	public function provide_manual_only(): array {
		return array(
			'empty'        => array( array(), true ),
			'exclude only' => array( array( 'exclude' => array( 1 ) ), true ),
			'all'          => array( array( 'all' => true ), false ),
			'include'      => array( array( 'include' => array( 1 ) ), false ),
			'groups'       => array( array( 'groups' => array( array( self::cond( 'product_cat', array( 1 ) ) ) ) ), false ),
		);
	}

	// ---- to_json -----------------------------------------------------------

	/**
	 * @dataProvider provide_round_trip
	 */
	public function test_to_json_round_trip( array $rules ): void {
		$rules = Rules::parse( $rules );
		$json  = Rules::to_json( $rules );
		$this->assertJson( $json );
		$errors = array();
		$this->assertSame( $rules, Rules::parse( $json, $errors ) );
		$this->assertSame( array(), $errors );
	}

	public function provide_round_trip(): array {
		return array(
			'empty'   => array( Rules::empty_rules() ),
			'all'     => array( array( 'all' => true ) ),
			'complex' => array(
				array(
					'groups'  => array(
						array( self::cond( 'product_cat', array( 12, 13 ) ), self::cond( 'product_tag', array( 7 ), true ) ),
						array( self::cond( 'product_brand', array( 3 ) ) ),
					),
					'include' => array( 501, 502 ),
					'exclude' => array( 880 ),
				),
			),
		);
	}

	public function test_to_json_format(): void {
		$this->assertSame( '{"v":1,"all":false,"groups":[],"include":[],"exclude":[]}', Rules::to_json( Rules::empty_rules() ) );
		$rules = Rules::parse( array( 'groups' => array( array( self::cond( 'product_cat', array( 12 ) ) ) ) ) );
		$this->assertSame(
			'{"v":1,"all":false,"groups":[[{"tax":"product_cat","terms":[12],"not":false}]],"include":[],"exclude":[]}',
			Rules::to_json( $rules )
		);
	}
}
