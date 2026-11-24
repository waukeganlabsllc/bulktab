<?php
/**
 * Shape checks for decoded YIKES Custom Product Tabs (v1.8.6) data.
 *
 * - Product meta `yikes_woo_products_tabs`: list of
 *   array( 'title' => ..., 'id' => sanitize_title( title ), 'content' => ... [, 'name' => ...] ).
 *   Content is stored unslashed (update_post_meta unslashes it).
 * - Option `yikes_woo_reusable_products_tabs`: saved_id => array( tab_id, tab_title,
 *   tab_name, tab_content, tab_slug, taxonomies, global_tab ). tab_content and
 *   tab_name come straight from $_POST, so they are stored slashed.
 * - Option `yikes_woo_reusable_products_tabs_applied`: product_id => saved_id =>
 *   array( post_id, reusable_tab_id, tab_id ), where tab_id is the `id` of the
 *   product's copy.
 *
 * Pure PHP, no WordPress calls.
 *
 * @package BulkTab
 */

namespace BulkTab\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes decoded YIKES values; rejects anything that is not the expected shape.
 */
final class YikesData {

	const META_TABS      = 'yikes_woo_products_tabs';
	const OPTION_SAVED   = 'yikes_woo_reusable_products_tabs';
	const OPTION_APPLIED = 'yikes_woo_reusable_products_tabs_applied';
	const OPTION_CONTENT = 'yikes_cpt_use_the_content';

	/**
	 * A product's tab list.
	 *
	 * @param mixed $value Decoded meta value.
	 * @return array<int, array{id:string,title:string,content:string}>|null Null when the shape is wrong.
	 */
	public static function product_tabs( $value ): ?array {
		if ( null === $value || false === $value || '' === $value || array() === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$out = array();
		foreach ( $value as $tab ) {
			if ( ! is_array( $tab ) || ( ! array_key_exists( 'title', $tab ) && ! array_key_exists( 'content', $tab ) ) ) {
				return null;
			}
			$fields = array();
			foreach ( array( 'id', 'title', 'content' ) as $field ) {
				$v = $tab[ $field ] ?? '';
				if ( null !== $v && ! is_scalar( $v ) ) {
					return null;
				}
				$fields[ $field ] = (string) $v;
			}
			$out[] = $fields;
		}
		return $out;
	}

	/**
	 * Saved (reusable) tabs, unslashed.
	 *
	 * @param mixed    $value  Decoded option value.
	 * @param string[] $issues Appended: saved IDs that were skipped, as "skipped:<id>".
	 * @return array<int, array{id:int,title:string,name:string,content:string,slug:string,has_rule:bool}>
	 */
	public static function saved_tabs( $value, array &$issues = array() ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $key => $tab ) {
			$id = is_array( $tab ) && isset( $tab['tab_id'] ) && is_numeric( $tab['tab_id'] ) ? (int) $tab['tab_id'] : (int) $key;
			if ( ! is_array( $tab ) || $id <= 0 || ! self::scalars( $tab, array( 'tab_title', 'tab_name', 'tab_content', 'tab_slug' ) ) ) {
				$issues[] = 'skipped:' . (string) $key;
				continue;
			}
			$out[ $id ] = array(
				'id'       => $id,
				'title'    => stripslashes( (string) ( $tab['tab_title'] ?? '' ) ),
				'name'     => stripslashes( (string) ( $tab['tab_name'] ?? '' ) ),
				'content'  => stripslashes( (string) ( $tab['tab_content'] ?? '' ) ),
				'slug'     => (string) ( $tab['tab_slug'] ?? '' ),
				'has_rule' => ! empty( $tab['global_tab'] ) || ! empty( $tab['taxonomies'] ),
			);
		}
		return $out;
	}

	/**
	 * Applied map: which saved tab each product's copy belongs to.
	 *
	 * @param mixed $value Decoded option value.
	 * @return array<int, array<int, string>> product_id => saved_id => tab_id (the copy's `id`).
	 */
	public static function applied_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $product_id => $links ) {
			if ( ! is_numeric( $product_id ) || (int) $product_id <= 0 || ! is_array( $links ) ) {
				continue;
			}
			foreach ( $links as $saved_id => $link ) {
				if ( ! is_numeric( $saved_id ) || ! is_array( $link ) || ! isset( $link['tab_id'] ) || ! is_scalar( $link['tab_id'] ) ) {
					continue;
				}
				$out[ (int) $product_id ][ (int) $saved_id ] = (string) $link['tab_id'];
			}
		}
		return $out;
	}

	/**
	 * Normalize text for "is this the same tab?" comparisons.
	 *
	 * @param string $text Title or content.
	 * @return string
	 */
	public static function normalize( string $text ): string {
		return trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
	}

	/**
	 * Are the listed fields (when present) scalars or null?
	 *
	 * @param array    $row    Row.
	 * @param string[] $fields Field names.
	 * @return bool
	 */
	private static function scalars( array $row, array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( isset( $row[ $field ] ) && ! is_scalar( $row[ $field ] ) ) {
				return false;
			}
		}
		return true;
	}
}
