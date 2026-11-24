<?php
/**
 * Safe reader for PHP-serialized values written by other plugins (YIKES).
 *
 * YIKES stores tabs as a serialized array and reads them with a suppressed
 * unserialize, so any search-replace, CSV round trip or slash stripping that
 * changes a string's byte length makes every tab silently disappear.
 *
 * This class:
 * - tokenizes the serialized text itself, accepting only arrays and scalars
 *   (objects, custom-serialized classes, enums and references are rejected
 *   before PHP's unserialize ever sees the data: no object injection);
 * - recounts each `s:N:"...";` length in bytes when N is wrong, choosing the
 *   end quote whose following text fits the structure (next key, value, `}`
 *   or end of input);
 * - unwraps up to MAX_LAYERS of serialized-inside-a-string (WooCommerce CSV
 *   imports of YIKES exports re-serialize the already serialized text);
 * - decodes the checked text with unserialize( allowed_classes => false ) and
 *   reports every failure instead of hiding it.
 *
 * Pure PHP, no WordPress calls.
 *
 * @package BulkTab
 */

namespace BulkTab\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Decodes and repairs serialized strings.
 */
final class Serialized {

	const STATUS_OK         = 'ok';
	const STATUS_REPAIRED   = 'repaired';
	const STATUS_EMPTY      = 'empty';
	const STATUS_UNREADABLE = 'unreadable';

	/** Serialized-in-a-string layers to unwrap (raw value + two re-serializations). */
	const MAX_LAYERS = 3;

	/** Deepest array nesting accepted (YIKES data needs 2). */
	const MAX_DEPTH = 32;

	/**
	 * Decode a raw stored value.
	 *
	 * @param string $raw Raw meta / option value as stored in the database.
	 * @return array{status:string,value:mixed,error:string,layers:int}
	 */
	public static function decode( string $raw ): array {
		$data = trim( $raw );
		if ( '' === $data ) {
			return self::result( self::STATUS_EMPTY, null, '', 0 );
		}

		$repaired = false;
		$layers   = 0;
		while ( true ) {
			$error = '';
			$fixed = self::repair( $data, $error );
			if ( null === $fixed ) {
				return self::result( self::STATUS_UNREADABLE, null, $error, $layers );
			}
			if ( $fixed !== $data ) {
				$repaired = true;
			}
			$ok    = false;
			$value = self::unserialize_checked( $fixed, $ok );
			if ( ! $ok ) {
				return self::result( self::STATUS_UNREADABLE, null, 'php_unserialize_failed', $layers );
			}
			++$layers;

			if ( is_string( $value ) && $layers < self::MAX_LAYERS && self::looks_serialized( trim( $value ) ) ) {
				$data = trim( $value );
				continue;
			}
			return self::result( $repaired ? self::STATUS_REPAIRED : self::STATUS_OK, $value, '', $layers );
		}
	}

	/**
	 * Does a string start like a serialized array or string?
	 *
	 * Only arrays and strings are unwrapped: a tab list is an array, and a
	 * re-serialized tab list is a string.
	 *
	 * @param string $data Trimmed text.
	 * @return bool
	 */
	public static function looks_serialized( string $data ): bool {
		return 1 === preg_match( '/^(?:a:\d+:\{|s:\d+:")/', $data );
	}

	/**
	 * Validate serialized text and return it with every string length
	 * recounted in bytes. Null when the text cannot be read safely.
	 *
	 * @param string $s     Serialized text (one value, nothing after it).
	 * @param string $error Set to an error code on failure.
	 * @return string|null
	 */
	public static function repair( string $s, string &$error = '' ): ?string {
		$len   = strlen( $s );
		$pos   = 0;
		$out   = '';
		$stack = array(); // Tokens (keys + values) left in each open array, innermost last.
		$done  = false;

		while ( true ) {
			$top = count( $stack ) - 1;

			if ( $top >= 0 && 0 === $stack[ $top ] ) {
				if ( $pos >= $len || '}' !== $s[ $pos ] ) {
					$error = 'expected_array_end';
					return null;
				}
				$out .= '}';
				++$pos;
				array_pop( $stack );
				if ( ! $stack ) {
					$done = true;
				}
				continue;
			}
			if ( $done ) {
				break;
			}
			if ( $pos >= $len ) {
				$error = 'truncated';
				return null;
			}

			$is_key = $top >= 0 && 0 === $stack[ $top ] % 2;
			$char   = $s[ $pos ];
			$m      = array();

			if ( in_array( $char, array( 'O', 'C', 'E', 'r', 'R' ), true ) ) {
				$error = 'object_or_reference';
				return null;
			}

			if ( 'a' === $char ) {
				if ( $is_key || ! preg_match( '/\Ga:(\d{1,9}):\{/', $s, $m, 0, $pos ) ) {
					$error = 'invalid_array';
					return null;
				}
				if ( count( $stack ) >= self::MAX_DEPTH ) {
					$error = 'too_deep';
					return null;
				}
				if ( $top >= 0 ) {
					--$stack[ $top ];
				}
				$stack[] = 2 * (int) $m[1];
				$out    .= 'a:' . (int) $m[1] . ':{';
				$pos    += strlen( $m[0] );
				continue;
			}

			if ( 's' === $char ) {
				if ( ! preg_match( '/\Gs:(\d{1,10}):"/', $s, $m, 0, $pos ) ) {
					$error = 'invalid_string';
					return null;
				}
				$start = $pos + strlen( $m[0] );
				$after = $stack;
				if ( $top >= 0 ) {
					--$after[ $top ];
				}
				$end = self::string_end( $s, $start, (int) $m[1], $after );
				if ( null === $end ) {
					$error = 'string_end_not_found';
					return null;
				}
				$content = (string) substr( $s, $start, $end - $start );
				$out    .= 's:' . strlen( $content ) . ':"' . $content . '";';
				$pos     = $end + 2;
				$stack   = $after;
				$done    = ! $stack;
				continue;
			}

			$scalar = $is_key
				? '/\G(?:i:[+-]?\d{1,18};)/'
				: '/\G(?:i:[+-]?\d{1,18};|b:[01];|N;|d:(?:NAN|-?INF|[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?);)/';
			if ( ! preg_match( $scalar, $s, $m, 0, $pos ) ) {
				$error = 'invalid_token';
				return null;
			}
			$out .= $m[0];
			$pos += strlen( $m[0] );
			if ( $top >= 0 ) {
				--$stack[ $top ];
			}
			$done = ! $stack;
		}

		if ( $pos !== $len ) {
			$error = 'trailing_data';
			return null;
		}
		return $out;
	}

	/**
	 * Find the offset of the closing `";` of a string value.
	 *
	 * The declared length wins when it lands on `";` and the text after it
	 * fits the structure; otherwise the first `";` that fits is used.
	 *
	 * @param string $s        Serialized text.
	 * @param int    $start    Offset of the first content byte.
	 * @param int    $declared Declared byte length.
	 * @param int[]  $after    Array token counters once this string is read.
	 * @return int|null Offset of the closing quote.
	 */
	private static function string_end( string $s, int $start, int $declared, array $after ): ?int {
		$end = $start + $declared;
		if ( '";' === substr( $s, $end, 2 ) && self::follows( $s, $end + 2, $after ) ) {
			return $end;
		}
		$p = strpos( $s, '";', $start );
		while ( false !== $p ) {
			if ( self::follows( $s, $p + 2, $after ) ) {
				return $p;
			}
			$p = strpos( $s, '";', $p + 1 );
		}
		return null;
	}

	/**
	 * Does the text at $pos continue the structure described by $lefts?
	 *
	 * @param string $s     Serialized text.
	 * @param int    $pos   Offset after a completed value.
	 * @param int[]  $lefts Tokens left in each open array, innermost last.
	 * @return bool
	 */
	private static function follows( string $s, int $pos, array $lefts ): bool {
		$len = strlen( $s );
		$i   = count( $lefts ) - 1;
		while ( $i >= 0 && 0 === $lefts[ $i ] ) {
			if ( $pos >= $len || '}' !== $s[ $pos ] ) {
				return false;
			}
			++$pos;
			--$i;
		}
		if ( $i < 0 ) {
			return $pos === $len;
		}
		$pattern = 0 === $lefts[ $i ] % 2
			? '/\G(?:i:[+-]?\d+;|s:\d+:")/'
			: '/\G(?:i:[+-]?\d+;|s:\d+:"|a:\d+:\{|b:[01];|N;|d:[^;"]{1,40};)/';
		return 1 === preg_match( $pattern, $s, $m, 0, $pos );
	}

	/**
	 * Unserialize text that repair() has already validated.
	 *
	 * Objects are never allowed. Failure is reported through $ok (the value
	 * `false` itself is legitimate only for "b:0;").
	 *
	 * @param string $fixed Validated serialized text.
	 * @param bool   $ok    Set to true on success.
	 * @return mixed
	 */
	private static function unserialize_checked( string $fixed, bool &$ok ) {
		// Validated by repair(): arrays and scalars only, so this cannot build objects or warn.
		$value = unserialize( $fixed, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$ok    = false !== $value || 'b:0;' === $fixed;
		return $value;
	}

	/**
	 * Build a decode() result.
	 *
	 * @param string $status Status constant.
	 * @param mixed  $value  Decoded value.
	 * @param string $error  Error code.
	 * @param int    $layers Layers decoded.
	 * @return array{status:string,value:mixed,error:string,layers:int}
	 */
	private static function result( string $status, $value, string $error, int $layers ): array {
		return array(
			'status' => $status,
			'value'  => $value,
			'error'  => $error,
			'layers' => $layers,
		);
	}
}
