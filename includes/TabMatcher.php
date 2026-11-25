<?php
/**
 * Decides which shared tabs show on a product. Pure PHP.
 *
 * Precedence: per-product hide -> attached on the product -> the tab's rule.
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
	 * Whether a parsed rule selects the product.
	 *
	 * @param array $rules Parsed rule (see Rules).
	 * @param array $ctx   Product context.
	 * @return bool
	 */
	public static function matches( array $rules, array $ctx ): bool {
		if ( ! empty( $rules['all'] ) ) {
			return true;
		}
		if ( empty( $rules['terms'] ) || empty( $rules['tax'] ) ) {
			return false;
		}
		$product_terms = $ctx['terms'][ $rules['tax'] ] ?? array();
		return (bool) array_intersect( $rules['terms'], $product_terms );
	}

	/**
	 * Whether one shared tab shows on the product.
	 *
	 * @param string        $key      Shared-tab key.
	 * @param array         $entry    Index entry (array( 'rules' => parsed rule, ... )).
	 * @param array         $ctx      Product context.
	 * @param bool          $attached Attached on this product.
	 * @param callable|null $decide   Optional callback( $key, $entry, $ctx, $attached ): ?bool from add-ons;
	 *                                null keeps the default decision.
	 * @return bool
	 */
	public static function shows( string $key, array $entry, array $ctx, bool $attached, ?callable $decide = null ): bool {
		if ( null !== $decide ) {
			$result = $decide( $key, $entry, $ctx, $attached );
			if ( is_bool( $result ) ) {
				return $result;
			}
		}
		return $attached || ( isset( $entry['rules'] ) && is_array( $entry['rules'] ) && self::matches( $entry['rules'], $ctx ) );
	}

	/**
	 * Shared-tab keys to show on a product.
	 *
	 * @param array         $index    Compiled index: key => array( 'rules' => parsed rule, ... ), in display order.
	 * @param array         $ctx      Product context.
	 * @param string[]      $attached Shared-tab keys attached on this product.
	 * @param string[]      $hidden   Refs hidden on this product, e.g. 's:size-guide'.
	 * @param callable|null $decide   See shows().
	 * @return string[] Keys to show, in index order.
	 */
	public static function resolve( array $index, array $ctx, array $attached, array $hidden, ?callable $decide = null ): array {
		$show = array();
		foreach ( $index as $key => $entry ) {
			$key = (string) $key;
			if ( in_array( 's:' . $key, $hidden, true ) ) {
				continue;
			}
			if ( self::shows( $key, is_array( $entry ) ? $entry : array(), $ctx, in_array( $key, $attached, true ), $decide ) ) {
				$show[] = $key;
			}
		}
		return $show;
	}
}
