<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use BulkTab\Migration\Serialized;

/**
 * Object-injection probe: must never be instantiated by the reader.
 */
final class EvilWakeup {
	/** @var bool */
	public static $woke = false;

	public function __wakeup() {
		self::$woke = true;
	}

	public function __destruct() {
		self::$woke = true;
	}
}

/**
 * @covers \BulkTab\Migration\Serialized
 */
final class SerializedTest extends TestCase {

	private static function tabs(): array {
		return array(
			array(
				'title'   => 'Shipping',
				'id'      => 'shipping',
				'content' => '<p>Ships in 2 days.</p>',
			),
			array(
				'title'   => 'Size guide',
				'id'      => 'size-guide',
				'content' => "<table>\n<tr><td>S</td></tr>\n</table>",
			),
		);
	}

	/** Replace every s:N: length with a wrong one computed by $fn( content ). */
	private static function corrupt_lengths( string $s, callable $fn ): string {
		return (string) preg_replace_callback(
			'/s:(\d+):"/',
			static function ( $m ) use ( $fn ) {
				return 's:' . $fn( (int) $m[1] ) . ':"';
			},
			$s
		);
	}

	public function test_valid_data_decodes_unchanged(): void {
		$r = Serialized::decode( serialize( self::tabs() ) );
		$this->assertSame( Serialized::STATUS_OK, $r['status'] );
		$this->assertSame( self::tabs(), $r['value'] );
		$this->assertSame( 1, $r['layers'] );
	}

	public function test_surrounding_whitespace_is_ignored(): void {
		$r = Serialized::decode( "  \n" . serialize( self::tabs() ) . "\n" );
		$this->assertSame( Serialized::STATUS_OK, $r['status'] );
		$this->assertSame( self::tabs(), $r['value'] );
	}

	/**
	 * @dataProvider empty_values
	 */
	public function test_empty_values( string $raw ): void {
		$r = Serialized::decode( $raw );
		$this->assertSame( Serialized::STATUS_EMPTY, $r['status'] );
		$this->assertNull( $r['value'] );
	}

	public function empty_values(): array {
		return array(
			'empty'      => array( '' ),
			'whitespace' => array( " \n\t" ),
		);
	}

	public function test_empty_array_is_ok(): void {
		$r = Serialized::decode( 'a:0:{}' );
		$this->assertSame( Serialized::STATUS_OK, $r['status'] );
		$this->assertSame( array(), $r['value'] );
	}

	public function test_false_value_is_ok_not_failure(): void {
		$r = Serialized::decode( 'b:0;' );
		$this->assertSame( Serialized::STATUS_OK, $r['status'] );
		$this->assertFalse( $r['value'] );
	}

	/**
	 * @dataProvider wrong_lengths
	 */
	public function test_wrong_lengths_are_repaired( callable $fn ): void {
		$bad = self::corrupt_lengths( serialize( self::tabs() ), $fn );
		$this->assertFalse( @unserialize( $bad ), 'fixture must be broken for PHP' ); // phpcs:ignore
		$r = Serialized::decode( $bad );
		$this->assertSame( Serialized::STATUS_REPAIRED, $r['status'] );
		$this->assertSame( self::tabs(), $r['value'] );
	}

	public function wrong_lengths(): array {
		return array(
			'too short' => array(
				static function ( $n ) {
					return max( 0, $n - 3 );
				},
			),
			'too long'  => array(
				static function ( $n ) {
					return $n + 7;
				},
			),
			'zero'      => array(
				static function () {
					return 0;
				},
			),
			'huge'      => array(
				static function () {
					return 999999;
				},
			),
		);
	}

	public function test_search_replace_of_url_without_length_fix(): void {
		$tabs                 = self::tabs();
		$tabs[0]['content']   = '<a href="http://old.test/manual.pdf">Manual</a>';
		$moved                = str_replace( 'http://old.test', 'https://www.new-shop.example', serialize( $tabs ) );
		$expected             = $tabs;
		$expected[0]['content'] = '<a href="https://www.new-shop.example/manual.pdf">Manual</a>';

		$r = Serialized::decode( $moved );
		$this->assertSame( Serialized::STATUS_REPAIRED, $r['status'] );
		$this->assertSame( $expected, $r['value'] );
	}

	public function test_multibyte_counted_as_characters_is_repaired(): void {
		$tabs = array(
			array(
				'title'   => 'Größentabelle',
				'id'      => 'grossentabelle',
				'content' => '<p>Maße: 30 € — 日本語 ✓ 🚚</p>',
			),
		);
		// A tool that counted characters (mb_strlen) instead of bytes.
		$bad = (string) preg_replace_callback(
			'/s:(\d+):"(.*?)";/su',
			static function ( $m ) {
				return 's:' . mb_strlen( $m[2], 'UTF-8' ) . ':"' . $m[2] . '";';
			},
			serialize( $tabs )
		);
		$this->assertNotSame( serialize( $tabs ), $bad );

		$r = Serialized::decode( $bad );
		$this->assertSame( Serialized::STATUS_REPAIRED, $r['status'] );
		$this->assertSame( $tabs, $r['value'] );
	}

	public function test_valid_multibyte_is_untouched(): void {
		$tabs = array(
			array(
				'title'   => 'Ürün',
				'id'      => 'urun',
				'content' => '日本語のテキスト',
			),
		);
		$r    = Serialized::decode( serialize( $tabs ) );
		$this->assertSame( Serialized::STATUS_OK, $r['status'] );
		$this->assertSame( $tabs, $r['value'] );
	}

	public function test_backslashes_stripped_by_unslash_are_repaired(): void {
		$tabs = array(
			array(
				'title'   => 'Specs',
				'id'      => 'specs',
				'content' => 'Path C:\\manuals\\a.pdf and <span class=\\"x\\">',
			),
		);
		// update_post_meta() unslashes the CSV cell: backslashes vanish, lengths stay.
		$bad = stripslashes( serialize( $tabs ) );

		$r = Serialized::decode( $bad );
		$this->assertSame( Serialized::STATUS_REPAIRED, $r['status'] );
		$this->assertSame( 'Path C:manualsa.pdf and <span class="x">', $r['value'][0]['content'] );
	}

	public function test_valid_backslashes_are_kept(): void {
		$tabs = array(
			array(
				'title'   => 'A\\B',
				'id'      => 'ab',
				'content' => 'C:\\dir\\file and \\"quoted\\"',
			),
		);
		$r    = Serialized::decode( serialize( $tabs ) );
		$this->assertSame( Serialized::STATUS_OK, $r['status'] );
		$this->assertSame( $tabs, $r['value'] );
	}

	public function test_crlf_from_spreadsheet_is_repaired(): void {
		$bad = str_replace( "\n", "\r\n", serialize( self::tabs() ) );
		$r   = Serialized::decode( $bad );
		$this->assertSame( Serialized::STATUS_REPAIRED, $r['status'] );
		$this->assertSame( "<table>\r\n<tr><td>S</td></tr>\r\n</table>", $r['value'][1]['content'] );
	}

	public function test_content_containing_structure_like_text(): void {
		$tabs = array(
			array(
				'title'   => 'Script',
				'id'      => 'script',
				'content' => '<script>var a = "x";} if(b){c = "y";s:3:"zz</script>',
			),
			array(
				'title'   => 'B',
				'id'      => 'b',
				'content' => 'ends with quote-semicolon";',
			),
		);
		$ok = Serialized::decode( serialize( $tabs ) );
		$this->assertSame( Serialized::STATUS_OK, $ok['status'] );
		$this->assertSame( $tabs, $ok['value'] );

		$bad = self::corrupt_lengths(
			serialize( $tabs ),
			static function ( $n ) {
				return $n + 2;
			}
		);
		// The corruption helper also rewrote the "s:3:" text inside the content.
		$tabs[0]['content'] = str_replace( 's:3:"', 's:5:"', $tabs[0]['content'] );

		$r = Serialized::decode( $bad );
		$this->assertSame( Serialized::STATUS_REPAIRED, $r['status'] );
		$this->assertSame( $tabs, $r['value'] );
	}

	public function test_double_serialized_from_csv_import_is_unwrapped(): void {
		$inner = serialize( self::tabs() );
		$r     = Serialized::decode( serialize( $inner ) );
		$this->assertSame( Serialized::STATUS_OK, $r['status'] );
		$this->assertSame( 2, $r['layers'] );
		$this->assertSame( self::tabs(), $r['value'] );
	}

	public function test_double_serialized_with_both_layers_corrupted(): void {
		$tabs               = self::tabs();
		$inner              = str_replace( 'Ships', 'Dispatches', serialize( $tabs ) ); // Inner length now wrong.
		$outer              = str_replace( 'Size guide', 'Size & fit guide', serialize( $inner ) ); // Outer and inner wrong.
		$tabs[0]['content'] = '<p>Dispatches in 2 days.</p>';
		$tabs[1]['title']   = 'Size & fit guide';

		$r = Serialized::decode( $outer );
		$this->assertSame( Serialized::STATUS_REPAIRED, $r['status'] );
		$this->assertSame( 2, $r['layers'] );
		$this->assertSame( $tabs, $r['value'] );
	}

	public function test_unwrapping_stops_after_max_layers(): void {
		$v = serialize( serialize( serialize( serialize( array( 1 ) ) ) ) );
		$r = Serialized::decode( $v );
		$this->assertSame( Serialized::MAX_LAYERS, $r['layers'] );
		$this->assertIsString( $r['value'] );
	}

	/**
	 * @dataProvider injection_payloads
	 */
	public function test_objects_and_references_are_rejected( string $payload ): void {
		EvilWakeup::$woke = false;
		$r                = Serialized::decode( $payload );
		$this->assertSame( Serialized::STATUS_UNREADABLE, $r['status'] );
		$this->assertNull( $r['value'] );
		$this->assertFalse( EvilWakeup::$woke );
	}

	public function injection_payloads(): array {
		$class = EvilWakeup::class;
		$obj   = 'O:' . strlen( $class ) . ':"' . $class . '":0:{}';
		return array(
			'top-level object'        => array( $obj ),
			'object in tab list'      => array( 'a:1:{i:0;' . $obj . '}' ),
			'object as tab content'   => array( 'a:1:{i:0;a:1:{s:7:"content";' . $obj . '}}' ),
			'object, corrupt lengths' => array( 'a:1:{i:0;a:2:{s:9:"title";s:1:"T";s:7:"content";' . $obj . '}}' ),
			'custom serialized'       => array( 'a:1:{i:0;C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}}' ),
			'enum'                    => array( 'a:1:{i:0;E:7:"Foo:Bar";}' ),
			'reference'               => array( 'a:2:{i:0;s:1:"x";i:1;R:2;}' ),
			'object reference'        => array( 'a:2:{i:0;a:0:{}i:1;r:2;}' ),
			'object in second layer'  => array( serialize( 'a:1:{i:0;' . $obj . '}' ) ),
		);
	}

	public function test_object_text_inside_a_string_is_just_text(): void {
		$tabs = array(
			array(
				'title'   => 'T',
				'id'      => 't',
				'content' => 'O:8:"stdClass":0:{}',
			),
		);
		$r    = Serialized::decode( serialize( $tabs ) );
		$this->assertSame( Serialized::STATUS_OK, $r['status'] );
		$this->assertSame( $tabs, $r['value'] );
	}

	/**
	 * @dataProvider unreadable_values
	 */
	public function test_unreadable_values( string $raw ): void {
		$r = Serialized::decode( $raw );
		$this->assertSame( Serialized::STATUS_UNREADABLE, $r['status'] );
		$this->assertNotSame( '', $r['error'] );
	}

	public function unreadable_values(): array {
		$valid = serialize( self::tabs() );
		return array(
			'not serialized'          => array( 'Shipping info' ),
			'truncated (Excel limit)' => array( substr( $valid, 0, 60 ) ),
			'trailing data'           => array( $valid . 'garbage' ),
			'two values'              => array( 'i:1;i:2;' ),
			'missing closing brace'   => array( substr( $valid, 0, -1 ) ),
			'bad count'               => array( 'a:3:{i:0;i:1;}' ),
			'array as key'            => array( 'a:1:{a:0:{}i:1;}' ),
			'bool as key'             => array( 'a:1:{b:1;i:1;}' ),
			'huge integer'            => array( 'i:99999999999999999999999;' ),
			'no closing quote'        => array( 'a:1:{i:0;s:5:"abc}' ),
			'json'                    => array( '[{"title":"x"}]' ),
		);
	}

	public function test_too_deep_nesting_is_rejected(): void {
		$v = 'i:1;';
		for ( $i = 0; $i < Serialized::MAX_DEPTH + 1; $i++ ) {
			$v = 'a:1:{i:0;' . $v . '}';
		}
		$r = Serialized::decode( $v );
		$this->assertSame( Serialized::STATUS_UNREADABLE, $r['status'] );
		$this->assertSame( 'too_deep', $r['error'] );
	}

	public function test_scalars_round_trip(): void {
		$value = array(
			'i'   => -42,
			'f'   => 1.5,
			'e'   => 1.0E+25,
			'b'   => true,
			'n'   => null,
			7     => 'seven',
			'sub' => array( 'x' => '' ),
		);
		$r     = Serialized::decode( serialize( $value ) );
		$this->assertSame( Serialized::STATUS_OK, $r['status'] );
		$this->assertSame( $value, $r['value'] );
	}

	public function test_repair_returns_canonical_text(): void {
		$this->assertSame( 's:3:"abc";', Serialized::repair( 's:9:"abc";' ) );
		$this->assertSame( 'a:1:{i:0;s:2:"é";}', Serialized::repair( 'a:1:{i:0;s:1:"é";}' ) );
		$error = '';
		$this->assertNull( Serialized::repair( 'x', $error ) );
		$this->assertSame( 'invalid_token', $error );
	}

	public function test_looks_serialized(): void {
		$this->assertTrue( Serialized::looks_serialized( 'a:1:{' ) );
		$this->assertTrue( Serialized::looks_serialized( 's:3:"abc";' ) );
		$this->assertFalse( Serialized::looks_serialized( 'O:8:"stdClass":0:{}' ) );
		$this->assertFalse( Serialized::looks_serialized( 'hello' ) );
	}
}
