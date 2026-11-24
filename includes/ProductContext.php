<?php
/**
 * Builds the TabMatcher context for a product: term IDs per taxonomy,
 * including ancestors, mapped to the default language under WPML/Polylang
 * (rules store original-language IDs).
 *
 * @package BulkTab
 */

namespace BulkTab;

defined( 'ABSPATH' ) || exit;

final class ProductContext {

	/** @var array<int, array> */
	private static $cache = array();

	public static function for_product( int $product_id ): array {
		if ( isset( self::$cache[ $product_id ] ) ) {
			return self::$cache[ $product_id ];
		}
		$ctx = array(
			'id'    => I18n::original_post_id( $product_id, 'product' ),
			'terms' => array(),
		);
		foreach ( Rules::TAXONOMIES as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$ids = wp_get_post_terms( $product_id, $tax, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $ids ) ) {
				continue;
			}
			$all = array();
			foreach ( $ids as $term_id ) {
				$all[] = (int) $term_id;
				if ( in_array( $tax, Rules::HIERARCHICAL, true ) ) {
					foreach ( get_ancestors( (int) $term_id, $tax, 'taxonomy' ) as $parent ) {
						$all[] = (int) $parent;
					}
				}
			}
			$ctx['terms'][ $tax ] = array_values( array_unique( array_map( array( I18n::class, 'original_term_id' ), $all, array_fill( 0, count( $all ), $tax ) ) ) );
		}
		self::$cache[ $product_id ] = $ctx;
		return $ctx;
	}

	public static function flush(): void {
		self::$cache = array();
	}
}
