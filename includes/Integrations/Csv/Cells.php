<?php
/**
 * CSV cell helpers. Pure PHP.
 *
 * @package BulkTab
 */

namespace BulkTab\Integrations\Csv;

/**
 * Cell-level conventions: the `[keep]` marker, exporter escaping, line endings.
 */
final class Cells {

	/** A cell holding exactly this (surrounding spaces ignored) leaves the field untouched. */
	const KEEP = '[keep]';

	/**
	 * Whether a cell asks to keep the stored value.
	 *
	 * @param string $value Cell value.
	 * @return bool
	 */
	public static function is_keep( string $value ): bool {
		return 0 === strcasecmp( self::trim( $value ), self::KEEP );
	}

	/**
	 * Undo the exporter's formula escaping. WooCommerce prefixes `'` to values that start
	 * with = + - @ tab or CR (CSV injection guard) and only strips it again for its own
	 * columns, so we do it for ours.
	 *
	 * @param string $value Raw cell value.
	 * @return string
	 */
	public static function unescape( string $value ): string {
		if ( strlen( $value ) >= 2 && "'" === $value[0] && false !== strpos( "=+-@\t\r", $value[1] ) ) {
			return substr( $value, 1 );
		}
		return $value;
	}

	/**
	 * Convert CRLF / CR line endings (as spreadsheets save them) to LF.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function normalize_newlines( string $value ): string {
		return str_replace( array( "\r\n", "\r" ), "\n", $value );
	}

	/**
	 * Whether two contents are the same text, ignoring line-ending style and
	 * surrounding whitespace (spreadsheets often change both).
	 *
	 * @param string $a Text.
	 * @param string $b Text.
	 * @return bool
	 */
	public static function same_text( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		return self::trim( self::normalize_newlines( $a ) ) === self::trim( self::normalize_newlines( $b ) );
	}

	/**
	 * Trim ASCII whitespace and non-breaking spaces (which spreadsheets like to add).
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function trim( string $value ): string {
		// Explicit byte classes (no \s) so a multibyte character ending in 0xA0, like "à", is never cut.
		return (string) preg_replace( '/^(?:[ \t\n\r\0\x0B]|\xC2\xA0)+|(?:[ \t\n\r\0\x0B]|\xC2\xA0)+$/', '', $value );
	}

	/**
	 * Whether a cell is blank (only whitespace).
	 *
	 * @param string $value Cell value.
	 * @return bool
	 */
	public static function is_blank( string $value ): bool {
		return '' === self::trim( $value );
	}
}
