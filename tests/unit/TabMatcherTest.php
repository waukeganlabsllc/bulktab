<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use BulkTab\Rules;
use BulkTab\TabMatcher;

/**
 * @covers \BulkTab\TabMatcher
 */
final class TabMatcherTest extends TestCase {

	private const CAT  = 'product_cat';
	private const TAG  = 'product_tag';
	private const BRND = 'product_brand';

	private static function cond( string $tax, array $terms, bool $not = false ): array {
		return array(
			'tax'   => $tax,
			'terms' => $terms,
			'not'   => $not,
		);
	}

	/** Build rules through the real parser so tests use the stored shape. */
	private static function rules( array $raw ): array {
		$errors = array();
		$rules  = Rules::parse( $raw, $errors );
		if ( $errors ) {
			throw new \LogicException( 'Bad fixture: ' . implode( ',', $errors ) );
		}
		return $rules;
	}

	private static function ctx( int $id, array $terms = array() ): array {
		return array(
			'id'    => $id,
			'terms' => $terms,
		);
	}

	/**
	 * @dataProvider provide_matches
	 */
	public function test_matches( array $raw_rules, array $ctx, bool $expected ): void {
		$this->assertSame( $expected, TabMatcher::matches( self::rules( $raw_rules ), $ctx ) );
	}

	public function provide_matches(): array {
		$shirts       = array( 'groups' => array( array( self::cond( self::CAT, array( 10 ) ) ) ) );
		$cats_or      = array( 'groups' => array( array( self::cond( self::CAT, array( 10, 11, 12 ) ) ) ) );
		$shirts_and   = array(
			'groups' => array(
				array(
					self::cond( self::CAT, array( 10 ) ),
					self::cond( self::TAG, array( 7 ) ),
				),
			),
		);
		$groups_or    = array(
			'groups' => array(
				array( self::cond( self::CAT, array( 10 ) ) ),
				array( self::cond( self::BRND, array( 99 ) ) ),
			),
		);
		$not_sale     = array( 'groups' => array( array( self::cond( self::TAG, array( 7 ) , true ) ) ) );
		$cat_and_not  = array(
			'groups' => array(
				array(
					self::cond( self::CAT, array( 10 ) ),
					self::cond( self::TAG, array( 7 ), true ),
				),
			),
		);
		$include_only = array( 'include' => array( 5 ) );

		return array(
			'all matches anything'               => array( array( 'all' => true ), self::ctx( 1 ), true ),
			'all matches with no ctx id'         => array( array( 'all' => true ), array(), true ),
			'empty rules match nothing'          => array( array(), self::ctx( 1, array( self::CAT => array( 10 ) ) ), false ),
			'single cat hit'                     => array( $shirts, self::ctx( 1, array( self::CAT => array( 10 ) ) ), true ),
			'single cat hit among many'          => array( $shirts, self::ctx( 1, array( self::CAT => array( 3, 10, 4 ) ) ), true ),
			'single cat miss'                    => array( $shirts, self::ctx( 1, array( self::CAT => array( 11 ) ) ), false ),
			'single cat, term in other tax'      => array( $shirts, self::ctx( 1, array( self::TAG => array( 10 ) ) ), false ),
			'ancestor id in ctx matches'         => array( $shirts, self::ctx( 1, array( self::CAT => array( 55, 10 ) ) ), true ),
			'ctx terms as strings'               => array( $shirts, self::ctx( 1, array( self::CAT => array( '10' ) ) ), true ),
			'multiple terms are OR (first)'      => array( $cats_or, self::ctx( 1, array( self::CAT => array( 10 ) ) ), true ),
			'multiple terms are OR (last)'       => array( $cats_or, self::ctx( 1, array( self::CAT => array( 12 ) ) ), true ),
			'multiple terms none'                => array( $cats_or, self::ctx( 1, array( self::CAT => array( 13 ) ) ), false ),
			'AND both'                           => array( $shirts_and, self::ctx( 1, array( self::CAT => array( 10 ), self::TAG => array( 7 ) ) ), true ),
			'AND only first'                     => array( $shirts_and, self::ctx( 1, array( self::CAT => array( 10 ) ) ), false ),
			'AND only second'                    => array( $shirts_and, self::ctx( 1, array( self::TAG => array( 7 ) ) ), false ),
			'OR groups first'                    => array( $groups_or, self::ctx( 1, array( self::CAT => array( 10 ) ) ), true ),
			'OR groups second'                   => array( $groups_or, self::ctx( 1, array( self::BRND => array( 99 ) ) ), true ),
			'OR groups neither'                  => array( $groups_or, self::ctx( 1, array( self::CAT => array( 1 ), self::BRND => array( 2 ) ) ), false ),
			'NOT: product lacks term'            => array( $not_sale, self::ctx( 1, array( self::TAG => array( 8 ) ) ), true ),
			'NOT: product has term'              => array( $not_sale, self::ctx( 1, array( self::TAG => array( 7, 8 ) ) ), false ),
			'NOT: product has no terms of tax'   => array( $not_sale, self::ctx( 1 ), true ),
			'cat AND NOT tag: ok'                => array( $cat_and_not, self::ctx( 1, array( self::CAT => array( 10 ) ) ), true ),
			'cat AND NOT tag: blocked'           => array( $cat_and_not, self::ctx( 1, array( self::CAT => array( 10 ), self::TAG => array( 7 ) ) ), false ),
			'missing terms key in ctx'           => array( $shirts, array( 'id' => 1 ), false ),
			'missing tax in ctx terms'           => array( $shirts, self::ctx( 1, array( self::TAG => array( 1 ) ) ), false ),
			'null tax terms in ctx'              => array( $shirts, self::ctx( 1, array( self::CAT => null ) ), false ),
			'include beats non-match'            => array( array_merge( $shirts, $include_only ), self::ctx( 5, array( self::CAT => array( 99 ) ) ), true ),
			'include alone'                      => array( $include_only, self::ctx( 5 ), true ),
			'include other product'              => array( $include_only, self::ctx( 6 ), false ),
			'include with string ctx id'         => array( $include_only, array( 'id' => '5' ), true ),
			'exclude beats all'                  => array( array( 'all' => true, 'exclude' => array( 5 ) ), self::ctx( 5 ), false ),
			'exclude beats include'              => array( array( 'include' => array( 5 ), 'exclude' => array( 5 ) ), self::ctx( 5 ), false ),
			'exclude beats group match'          => array( array_merge( $shirts, array( 'exclude' => array( 5 ) ) ), self::ctx( 5, array( self::CAT => array( 10 ) ) ), false ),
			'exclude other product does nothing' => array( array( 'all' => true, 'exclude' => array( 6 ) ), self::ctx( 5 ), true ),
		);
	}

	public function test_empty_group_never_matches(): void {
		// Unparsed/legacy rules with an empty AND group must not match everything.
		$rules = array_merge( Rules::empty_rules(), array( 'groups' => array( array() ) ) );
		$this->assertFalse( TabMatcher::matches( $rules, self::ctx( 1 ) ) );
	}

	// ---- resolve -----------------------------------------------------------

	private static function index(): array {
		return array(
			'size-guide' => array( 'rules' => self::rules( array( 'all' => true ) ) ),
			'shirts'     => array( 'rules' => self::rules( array( 'groups' => array( array( self::cond( self::CAT, array( 10 ) ) ) ) ) ) ),
			'manual'     => array( 'rules' => Rules::empty_rules() ),
			'vip'        => array( 'rules' => self::rules( array( 'include' => array( 5 ) ) ) ),
			'no-5'       => array(
				'rules' => self::rules(
					array(
						'all'     => true,
						'exclude' => array( 5 ),
					)
				),
			),
		);
	}

	/**
	 * @dataProvider provide_resolve
	 */
	public function test_resolve( array $ctx, array $attached, array $hidden, array $expected ): void {
		$this->assertSame( $expected, TabMatcher::resolve( self::index(), $ctx, $attached, $hidden ) );
	}

	public function provide_resolve(): array {
		$shirt5 = self::ctx( 5, array( self::CAT => array( 10 ) ) );
		$plain6 = self::ctx( 6 );
		return array(
			'rules only (5)'                    => array( $shirt5, array(), array(), array( 'size-guide', 'shirts', 'vip' ) ),
			'rules only (6)'                    => array( $plain6, array(), array(), array( 'size-guide', 'no-5' ) ),
			'attached but not matched shows'    => array( $plain6, array( 'manual', 'shirts' ), array(), array( 'size-guide', 'shirts', 'manual', 'no-5' ) ),
			'order follows index not attach'    => array( $plain6, array( 'no-5', 'manual' ), array(), array( 'size-guide', 'manual', 'no-5' ) ),
			'exclude beats attach'              => array( $shirt5, array( 'no-5' ), array(), array( 'size-guide', 'shirts', 'vip' ) ),
			'hide beats all'                    => array( $plain6, array(), array( 's:size-guide' ), array( 'no-5' ) ),
			'hide beats include'                => array( $shirt5, array(), array( 's:vip' ), array( 'size-guide', 'shirts' ) ),
			'hide beats attach'                 => array( $plain6, array( 'manual' ), array( 's:manual' ), array( 'size-guide', 'no-5' ) ),
			'hide beats match'                  => array( $shirt5, array(), array( 's:shirts' ), array( 'size-guide', 'vip' ) ),
			'hide needs s: prefix'              => array( $plain6, array(), array( 'size-guide', 'c:1', 'x:size-guide' ), array( 'size-guide', 'no-5' ) ),
			'unknown attached keys ignored'     => array( $plain6, array( 'gone' ), array(), array( 'size-guide', 'no-5' ) ),
			'shared tab appears at most once'   => array( $shirt5, array( 'shirts', 'shirts', 'size-guide' ), array(), array( 'size-guide', 'shirts', 'vip' ) ),
			'missing terms in ctx'              => array( array( 'id' => 6 ), array(), array(), array( 'size-guide', 'no-5' ) ),
			'empty ctx'                         => array( array(), array(), array(), array( 'size-guide', 'no-5' ) ),
		);
	}

	public function test_resolve_with_numeric_keys_returns_strings(): void {
		$index = array(
			'42' => array( 'rules' => self::rules( array( 'all' => true ) ) ),
			'7'  => array( 'rules' => self::rules( array( 'all' => true ) ) ),
		);
		$this->assertSame( array( '42', '7' ), TabMatcher::resolve( $index, self::ctx( 1 ), array(), array() ) );
		$this->assertSame( array( '7' ), TabMatcher::resolve( $index, self::ctx( 1 ), array(), array( 's:42' ) ) );
		$this->assertSame( array( '42', '7' ), TabMatcher::resolve( array( '42' => $index['42'], '7' => array( 'rules' => Rules::empty_rules() ) ), self::ctx( 1 ), array( '7' ), array() ) );
	}

	public function test_resolve_empty_index(): void {
		$this->assertSame( array(), TabMatcher::resolve( array(), self::ctx( 1 ), array( 'a' ), array() ) );
	}
}
