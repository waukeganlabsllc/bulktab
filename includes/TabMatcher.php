<?php
/**
 * Decides which shared tabs show on a product. Pure PHP.
 *
 * Precedence (spec): per-product hide -> rule exclude -> attached / include / matched.
 *
 * Product context:
 *   array( 'id' => 123, 'terms' => array( 'product_cat' => [ids incl. ancestors], ... ) )
 *
 * @package BulkTab
 */

namespace BulkTab;

/**
 * Evaluates shared-tab rules against a product context.
 */
final class TabMatcher {

	/**
	 * Whether parsed rules select the product (exclude, then include, then all/groups).
	 *
	 * @param array $rules Parsed rules.
	 * @param array $ctx   Product context.
	 * @return bool
	 */
	public static function matches( array $rules, array $ctx ): bool {
		$id = (int) ( $ctx['id'] ?? 0 );
		if ( in_array( $id, $rules['exclude'], true ) ) {
			return false;
		}
		if ( in_array( $id, $rules['include'], true ) ) {
			return true;
		}
		if ( $rules['all'] ) {
			return true;
		}
		foreach ( $rules['groups'] as $group ) {
			if ( self::group_matches( $group, $ctx ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether every condition of an AND-group holds.
	 *
	 * @param array $group Conditions.
	 * @param array $ctx   Product context.
	 * @return bool
	 */
	private static function group_matches( array $group, array $ctx ): bool {
		if ( array() === $group ) {
			return false; // An empty AND is vacuously true; never let it match every product.
		}
		foreach ( $group as $cond ) {
			$product_terms = $ctx['terms'][ $cond['tax'] ] ?? array();
			$has_any       = (bool) array_intersect( $cond['terms'], $product_terms );
			if ( $has_any === $cond['not'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Shared-tab keys to show on a product.
	 *
	 * @param array    $index    Compiled index: key => array( 'rules' => parsed rules, ... ), in display order.
	 * @param array    $ctx      Product context.
	 * @param string[] $attached Shared-tab keys attached on this product.
	 * @param string[] $hidden   Refs hidden on this product, e.g. 's:size-guide'.
	 * @return string[] Keys to show, in index order.
	 */
	public static function resolve( array $index, array $ctx, array $attached, array $hidden ): array {
		$id   = (int) ( $ctx['id'] ?? 0 );
		$show = array();
		foreach ( $index as $key => $entry ) {
			$key = (string) $key;
			if ( in_array( 's:' . $key, $hidden, true ) ) {
				continue;
			}
			$rules = $entry['rules'];
			if ( in_array( $id, $rules['exclude'], true ) ) {
				continue;
			}
			if ( in_array( $key, $attached, true ) || self::matches( $rules, $ctx ) ) {
				$show[] = $key;
			}
		}
		return $show;
	}
}
