<?php
/**
 * Shared-tab rule (stored as JSON in `_bulktab_rules`).
 *
 *   {"v":2,"all":false,"tax":"product_cat","terms":[12,15]}
 *
 * A shared tab shows on every product ("all"), on products in any of the
 * chosen terms of one taxonomy, or only where it is attached by hand.
 * Pure PHP, no WordPress calls.
 *
 * @package BulkTab
 */

namespace BulkTab;

/**
 * Parses, validates and serializes shared-tab rules.
 */
final class Rules {

	const VERSION = 2;

	const TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

	/** Taxonomies where a parent term also matches its children. */
	const HIERARCHICAL = array( 'product_cat', 'product_brand' );

	const MAX_TERMS = 200;

	/**
	 * A rule that matches nothing (manual attach only).
	 *
	 * @return array
	 */
	public static function empty_rules(): array {
		return array(
			'v'     => self::VERSION,
			'all'   => false,
			'tax'   => '',
			'terms' => array(),
		);
	}

	/**
	 * Parse a stored or posted rule. Never throws; anything unusable is
	 * reported in $errors and the rule falls back to manual attach only.
	 *
	 * @param mixed    $raw    JSON string or array.
	 * @param string[] $errors Error codes, appended.
	 * @return array
	 */
	public static function parse( $raw, array &$errors = array() ): array {
		if ( null === $raw || '' === $raw || array() === $raw ) {
			return self::empty_rules();
		}
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
			if ( ! is_array( $raw ) ) {
				$errors[] = 'invalid_json';
				return self::empty_rules();
			}
		}
		if ( ! is_array( $raw ) ) {
			$errors[] = 'invalid_type';
			return self::empty_rules();
		}
		if ( self::VERSION !== (int) ( $raw['v'] ?? self::VERSION ) ) {
			$errors[] = 'unsupported_version';
			return self::empty_rules();
		}

		$rules = self::empty_rules();
		if ( ! empty( $raw['all'] ) ) {
			$rules['all'] = true;
			return $rules;
		}

		$tax   = isset( $raw['tax'] ) ? (string) $raw['tax'] : '';
		$terms = self::id_list( $raw['terms'] ?? array() );
		if ( '' === $tax && array() === $terms ) {
			return $rules;
		}
		if ( ! in_array( $tax, self::TAXONOMIES, true ) ) {
			$errors[] = 'unknown_taxonomy';
			return $rules;
		}
		if ( array() === $terms ) {
			$errors[] = 'no_terms';
			return $rules;
		}
		if ( count( $terms ) > self::MAX_TERMS ) {
			$errors[] = 'too_many_terms';
			return $rules;
		}
		$rules['tax']   = $tax;
		$rules['terms'] = $terms;
		return $rules;
	}

	/**
	 * Rule for "products in any of these terms".
	 *
	 * @param string $tax   Taxonomy.
	 * @param int[]  $terms Term IDs.
	 * @return array
	 */
	public static function for_terms( string $tax, array $terms ): array {
		$rules          = self::empty_rules();
		$rules['tax']   = $tax;
		$rules['terms'] = self::id_list( $terms );
		return $rules;
	}

	/**
	 * Serialize a parsed rule for storage.
	 *
	 * @param array $rules Parsed rules.
	 * @return string
	 */
	public static function to_json( array $rules ): string {
		// Pure class (no WordPress loaded in tests); rules hold only ints, bools and known ASCII strings.
		return (string) json_encode( $rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * True when the rule never matches (tab is manual-attach only).
	 *
	 * @param array $rules Parsed rules.
	 * @return bool
	 */
	public static function is_manual_only( array $rules ): bool {
		return empty( $rules['all'] ) && empty( $rules['terms'] );
	}

	/**
	 * Positive, unique integer IDs from a raw list.
	 *
	 * @param mixed $value Raw list.
	 * @return int[]
	 */
	private static function id_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( $value as $v ) {
			if ( is_numeric( $v ) && (int) $v > 0 ) {
				$ids[ (int) $v ] = true;
			}
		}
		return array_keys( $ids );
	}
}
