<?php
/**
 * Applies tab layout (rename / hide / reorder) to the WooCommerce tabs array.
 * Pure PHP.
 *
 * Refs: built-ins use their WooCommerce key ('description', 'additional_information',
 * 'reviews'); our tabs carry 'bulktab_ref' ('s:size-guide', 'c:1'); tabs from other
 * plugins are 'x:<key>'.
 *
 * Global layout (option `bulktab_layout`):
 *   array( 'description' => array( 'title' => 'Details', 'hide' => false, 'priority' => 10 ), ... )
 *
 * @package BulkTab
 */

namespace BulkTab;

/**
 * Rename / hide / reorder of the WooCommerce tabs array.
 */
final class Layout {

	const BUILT_IN = array( 'description', 'additional_information', 'reviews' );

	/**
	 * Stable ref of a tab: its bulktab_ref, a built-in key, or 'x:<key>'.
	 *
	 * @param string $key WooCommerce tab key.
	 * @param array  $tab Tab array.
	 * @return string
	 */
	public static function ref_for( string $key, array $tab ): string {
		if ( isset( $tab['bulktab_ref'] ) ) {
			return (string) $tab['bulktab_ref'];
		}
		if ( in_array( $key, self::BUILT_IN, true ) ) {
			return $key;
		}
		return 'x:' . $key;
	}

	/**
	 * Apply the global layout, then per-product hide and order.
	 *
	 * @param array    $tabs          WooCommerce tabs (key => tab array).
	 * @param array    $global_layout Global layout, keyed by ref.
	 * @param string[] $order         Per-product order (refs). Listed tabs come first in this order.
	 * @param string[] $hidden        Per-product hidden refs.
	 * @return array
	 */
	public static function apply( array $tabs, array $global_layout, array $order, array $hidden ): array {
		$out = array();
		foreach ( $tabs as $key => $tab ) {
			$ref = self::ref_for( (string) $key, (array) $tab );
			if ( in_array( $ref, $hidden, true ) ) {
				continue;
			}
			if ( isset( $global_layout[ $ref ] ) ) {
				$g = $global_layout[ $ref ];
				if ( ! empty( $g['hide'] ) ) {
					continue;
				}
				if ( isset( $g['title'] ) && '' !== trim( (string) $g['title'] ) ) {
					$tab['title'] = self::renamed_title( (string) ( $tab['title'] ?? '' ), (string) $g['title'] );
				}
				if ( isset( $g['priority'] ) && is_numeric( $g['priority'] ) ) {
					$tab['priority'] = (int) $g['priority'];
				}
			}
			$out[ $key ] = $tab;
		}

		if ( $order ) {
			$order    = array_values( $order );
			$position = array_flip( $order );
			$unlisted = array();
			$i        = 0;
			foreach ( $out as $key => $tab ) {
				$ref = self::ref_for( (string) $key, (array) $tab );
				if ( isset( $position[ $ref ] ) ) {
					$out[ $key ]['priority'] = ( $position[ $ref ] + 1 ) * 10;
				} else {
					$unlisted[] = array( (int) ( $tab['priority'] ?? 50 ), $i, $key );
				}
				++$i;
			}
			// Unlisted tabs (e.g. added later) go after every listed position, keeping their
			// relative order (by priority, then array order; usort is not stable on PHP 7.4).
			usort(
				$unlisted,
				static function ( $a, $b ) {
					return array( $a[0], $a[1] ) <=> array( $b[0], $b[1] );
				}
			);
			$base = count( $order ) * 10;
			foreach ( $unlisted as $n => $row ) {
				$out[ $row[2] ]['priority'] = $base + ( $n + 1 ) * 10;
			}
		}

		return $out;
	}

	/**
	 * Keep a trailing count like "Reviews (3)" when a tab is renamed.
	 * "{count}" in the new title places the number explicitly.
	 *
	 * @param string $original Title WooCommerce generated.
	 * @param string $renamed  Title chosen by the store.
	 * @return string
	 */
	public static function renamed_title( string $original, string $renamed ): string {
		$count = preg_match( '/\((\d+)\)\s*$/', $original, $m ) ? $m[1] : null;
		if ( false !== strpos( $renamed, '{count}' ) ) {
			return trim( str_replace( '{count}', null === $count ? '' : $count, $renamed ) );
		}
		if ( null !== $count && ! preg_match( '/\(\d+\)\s*$/', $renamed ) ) {
			return $renamed . ' (' . $count . ')';
		}
		return $renamed;
	}

	/**
	 * Parse a stored comma list ("description,s:size-guide,c:1").
	 *
	 * @param mixed $value Comma list or array.
	 * @return string[]
	 */
	public static function parse_list( $value ): array {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$parts = explode( ',', (string) $value );
		}
		$out = array();
		foreach ( $parts as $p ) {
			if ( ! is_scalar( $p ) ) {
				continue;
			}
			$p = trim( (string) $p );
			if ( '' !== $p && preg_match( '/^[a-z0-9_:\-]+$/i', $p ) && ! in_array( $p, $out, true ) ) {
				$out[] = $p; // Not array keys: numeric refs would come back as ints.
			}
		}
		return $out;
	}
}
