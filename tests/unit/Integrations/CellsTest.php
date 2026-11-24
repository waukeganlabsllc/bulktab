<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Integrations;

use BulkTab\Integrations\Csv\Cells;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BulkTab\Integrations\Csv\Cells
 */
final class CellsTest extends TestCase {

	/**
	 * @dataProvider keep_values
	 */
	public function test_is_keep( string $value, bool $expected ): void {
		$this->assertSame( $expected, Cells::is_keep( $value ) );
	}

	public function keep_values(): array {
		return array(
			'exact'           => array( '[keep]', true ),
			'spaces'          => array( '  [keep] ', true ),
			'newline (Excel)' => array( "[keep]\r\n", true ),
			'nbsp'            => array( "\xC2\xA0[keep]", true ),
			'upper'           => array( '[KEEP]', true ),
			'no brackets'     => array( 'keep', false ),
			'inside text'     => array( 'please [keep] this', false ),
			'empty'           => array( '', false ),
			'quoted'          => array( '"[keep]"', false ),
		);
	}

	/**
	 * @dataProvider escaped_values
	 */
	public function test_unescape( string $in, string $out ): void {
		$this->assertSame( $out, Cells::unescape( $in ) );
	}

	public function escaped_values(): array {
		return array(
			'equals'      => array( "'=SUM(A1)", '=SUM(A1)' ),
			'plus'        => array( "'+1", '+1' ),
			'minus'       => array( "'- item one", '- item one' ),
			'at'          => array( "'@home", '@home' ),
			'tab'         => array( "'\tindented", "\tindented" ),
			'cr'          => array( "'\rline", "\rline" ),
			'apostrophe'  => array( "'tis the season", "'tis the season" ),
			'lone quote'  => array( "'", "'" ),
			'plain'       => array( '<p>Hi</p>', '<p>Hi</p>' ),
			'empty'       => array( '', '' ),
			'only once'   => array( "''=x", "''=x" ),
			'not leading' => array( "a'=b", "a'=b" ),
		);
	}

	public function test_normalize_newlines(): void {
		$this->assertSame( "a\nb\nc\n\nd", Cells::normalize_newlines( "a\r\nb\rc\n\r\nd" ) );
	}

	public function test_same_text(): void {
		$this->assertTrue( Cells::same_text( "<p>a</p>\n<p>b</p>", "<p>a</p>\r\n<p>b</p>\r\n" ) );
		$this->assertTrue( Cells::same_text( 'x', ' x ' ) );
		$this->assertFalse( Cells::same_text( '<p>a</p>', '<p>A</p>' ) );
		$this->assertFalse( Cells::same_text( "a\n\nb", "a\nb" ) );
	}

	public function test_trim_keeps_multibyte_characters_ending_in_a0(): void {
		// "à" is C3 A0: a byte-level \s or 0xA0 class would cut it in half.
		$this->assertSame( 'voilà', Cells::trim( " voilà\xC2\xA0 " ) );
		$this->assertSame( 'à', Cells::trim( 'à' ) );
		$this->assertSame( '日本語', Cells::trim( "\t日本語\n" ) );
	}

	public function test_is_blank(): void {
		$this->assertTrue( Cells::is_blank( '' ) );
		$this->assertTrue( Cells::is_blank( " \r\n\t\xC2\xA0" ) );
		$this->assertFalse( Cells::is_blank( '0' ) );
		$this->assertFalse( Cells::is_blank( '<br>' ) );
	}
}
