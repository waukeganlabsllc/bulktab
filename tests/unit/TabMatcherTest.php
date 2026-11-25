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

	private static function ctx( int $id, array $cats = array(), array $tags = array() ): array {
		return array(
			'id'    => $id,
			'terms' => array(
				'product_cat' => $cats,
				'product_tag' => $tags,
			),
		);
	}

	public function test_matches(): void {
		$all   = array_merge( Rules::empty_rules(), array( 'all' => true ) );
		$shoes = Rules::for_terms( 'product_cat', array( 10 ) );
		$this->assertTrue( TabMatcher::matches( $all, self::ctx( 1 ) ) );
		$this->assertTrue( TabMatcher::matches( $shoes, self::ctx( 1, array( 11, 10 ) ) ), 'ancestor in context' );
		$this->assertFalse( TabMatcher::matches( $shoes, self::ctx( 1, array( 12 ) ) ) );
		$this->assertFalse( TabMatcher::matches( $shoes, self::ctx( 1, array(), array( 10 ) ) ), 'other taxonomy' );
		$this->assertFalse( TabMatcher::matches( Rules::empty_rules(), self::ctx( 1, array( 10 ) ) ), 'manual only' );
	}

	public function test_resolve_order_hide_and_attach(): void {
		$index = array(
			'shipping' => array( 'rules' => array_merge( Rules::empty_rules(), array( 'all' => true ) ) ),
			'size'     => array( 'rules' => Rules::for_terms( 'product_cat', array( 10 ) ) ),
			'gift'     => array( 'rules' => Rules::empty_rules() ),
		);
		$ctx   = self::ctx( 7, array( 10 ) );
		$this->assertSame( array( 'shipping', 'size' ), TabMatcher::resolve( $index, $ctx, array(), array() ) );
		$this->assertSame( array( 'shipping', 'size', 'gift' ), TabMatcher::resolve( $index, $ctx, array( 'gift' ), array() ) );
		$this->assertSame( array( 'size' ), TabMatcher::resolve( $index, $ctx, array(), array( 's:shipping' ) ) );
		$this->assertSame( array(), TabMatcher::resolve( $index, $ctx, array( 'gift' ), array( 's:shipping', 's:size', 's:gift' ) ), 'hide wins over attach' );
	}

	public function test_decide_callback(): void {
		$index  = array(
			'a' => array( 'rules' => array_merge( Rules::empty_rules(), array( 'all' => true ) ) ),
			'b' => array( 'rules' => Rules::empty_rules() ),
		);
		$ctx    = self::ctx( 1 );
		$decide = static function ( string $key ) {
			return 'a' === $key ? null : true; // Keep the default for "a", force "b".
		};
		$this->assertSame( array( 'a', 'b' ), TabMatcher::resolve( $index, $ctx, array(), array(), $decide ) );
		$never = static function () {
			return false;
		};
		$this->assertSame( array(), TabMatcher::resolve( $index, $ctx, array( 'a' ), array(), $never ) );
		$this->assertSame( array(), TabMatcher::resolve( $index, $ctx, array(), array( 's:a', 's:b' ), static fn() => true ), 'hide is applied before the callback' );
	}
}
