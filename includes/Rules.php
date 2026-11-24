<?php
/**
 * Rule schema v1 for shared tabs (stored as JSON in `_bulktab_rules`).
 *
 *   {"v":1,"all":false,
 *    "groups":[[{"tax":"product_cat","terms":[12],"not":false}, ...], ...],
 *    "include":[501],"exclude":[880]}
 *
 * groups = OR of AND-groups. Pure PHP, no WordPress calls.
 *
 * Free vs Pro: limits only apply when SAVING from the editor. Stored rules
 * are always evaluated, so a lapsed Pro licence never changes what shoppers see.
 *
 * @package BulkTab
 */

namespace BulkTab;

/**
 * Parses, validates and serializes shared-tab rules.
 */
final class Rules {

	const VERSION = 1;

	const TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

	/** Taxonomies where a parent term also matches its children. */
	const HIERARCHICAL = array( 'product_cat', 'product_brand' );

	const MAX_TERMS = 200;

	/**
	 * Rules that match nothing (manual attach only).
	 *
	 * @return array
	 */
	public static function empty_rules(): array {
		return array(
			'v'       => self::VERSION,
			'all'     => false,
			'groups'  => array(),
			'include' => array(),
			'exclude' => array(),
		);
	}

	/**
	 * Free limits: "all products" OR one condition on one taxonomy (any number
	 * of terms). Pro raises these via the `bulktab_rule_limits` filter.
	 */
	public static function free_limits(): array {
		return array(
			'groups'          => 1,
			'conditions'      => 1,
			'not'             => false,
			'include_exclude' => false,
			'taxonomies'      => self::TAXONOMIES,
		);
	}

	/**
	 * Parse stored/posted rules. Never throws; anything unusable is reported
	 * in $errors. A broken group is dropped as a whole (dropping one AND
	 * condition would widen the match and show a tab where it shouldn't).
	 *
	 * @param mixed    $raw    JSON string or array.
	 * @param string[] $errors Error codes, appended.
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

		$version = isset( $raw['v'] ) ? (int) $raw['v'] : self::VERSION;
		if ( $version > self::VERSION ) {
			$errors[] = 'unsupported_version';
			return self::empty_rules();
		}

		$rules        = self::empty_rules();
		$rules['all'] = ! empty( $raw['all'] );

		$groups = isset( $raw['groups'] ) && is_array( $raw['groups'] ) ? $raw['groups'] : array();
		foreach ( $groups as $group ) {
			$clean = self::parse_group( $group, $errors );
			if ( null !== $clean ) {
				$rules['groups'][] = $clean;
			}
		}

		$rules['include'] = self::id_list( $raw['include'] ?? array() );
		$rules['exclude'] = self::id_list( $raw['exclude'] ?? array() );

		return $rules;
	}

	/**
	 * Clean one AND-group.
	 *
	 * @param mixed    $group  Raw group (list of conditions).
	 * @param string[] $errors Error codes, appended.
	 * @return array|null Clean group or null when unusable.
	 */
	private static function parse_group( $group, array &$errors ): ?array {
		if ( ! is_array( $group ) || array() === $group ) {
			$errors[] = 'empty_group';
			return null;
		}
		$out = array();
		foreach ( $group as $cond ) {
			if ( ! is_array( $cond ) || ! isset( $cond['tax'] ) || ! in_array( $cond['tax'], self::TAXONOMIES, true ) ) {
				$errors[] = 'unknown_taxonomy';
				return null;
			}
			$terms = self::id_list( $cond['terms'] ?? array() );
			if ( array() === $terms ) {
				$errors[] = 'no_terms';
				return null;
			}
			if ( count( $terms ) > self::MAX_TERMS ) {
				$errors[] = 'too_many_terms';
				return null;
			}
			$out[] = array(
				'tax'   => $cond['tax'],
				'terms' => $terms,
				'not'   => ! empty( $cond['not'] ),
			);
		}
		return $out;
	}

	/**
	 * Check parsed rules against a limits array.
	 *
	 * @param array $rules  Parsed rules.
	 * @param array $limits Limits (see free_limits()); missing keys fall back to Free.
	 * @return string[] Error codes.
	 */
	public static function check_limits( array $rules, array $limits ): array {
		// A filter that returns only some keys falls back to Free for the rest.
		$limits = array_merge( self::free_limits(), $limits );
		$errors = array();
		if ( count( $rules['groups'] ) > (int) $limits['groups'] ) {
			$errors[] = 'limit_groups';
		}
		foreach ( $rules['groups'] as $group ) {
			if ( count( $group ) > (int) $limits['conditions'] ) {
				$errors[] = 'limit_conditions';
			}
			foreach ( $group as $cond ) {
				if ( $cond['not'] && empty( $limits['not'] ) ) {
					$errors[] = 'limit_not';
				}
				if ( ! in_array( $cond['tax'], (array) $limits['taxonomies'], true ) ) {
					$errors[] = 'limit_taxonomy';
				}
			}
		}
		if ( empty( $limits['include_exclude'] ) && ( $rules['include'] || $rules['exclude'] ) ) {
			$errors[] = 'limit_include_exclude';
		}
		if ( $rules['all'] && $rules['groups'] ) {
			$errors[] = 'all_with_groups';
		}
		return array_values( array_unique( $errors ) );
	}

	/**
	 * Serialize parsed rules for storage.
	 *
	 * @param array $rules Parsed rules.
	 * @return string
	 */
	public static function to_json( array $rules ): string {
		// Pure class (no WordPress loaded in tests); rules hold only ints, bools and known ASCII strings.
		return (string) json_encode( $rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * True when nothing would ever match (tab is manual-attach only).
	 *
	 * @param array $rules Parsed rules.
	 * @return bool
	 */
	public static function is_manual_only( array $rules ): bool {
		return ! $rules['all'] && ! $rules['groups'] && ! $rules['include'];
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
