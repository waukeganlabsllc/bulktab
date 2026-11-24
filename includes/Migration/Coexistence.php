<?php
/**
 * Showing each tab once while YIKES data and BulkTab coexist.
 *
 * - Path B (YIKES still active): on products already migrated, the tabs
 *   YIKES adds are dropped, because BulkTab now renders the same tabs.
 *   YIKES keys its tabs by the stored `id` (sanitized title) and renders them
 *   with YIKES_Custom_Product_Tabs_Display::custom_product_tabs_panel_content.
 * - Path A (takeover, YIKES code gone): products not migrated yet still show
 *   their YIKES tabs, built from the stored data exactly as YIKES built them.
 *
 * Pure PHP, no WordPress calls.
 *
 * @package BulkTab
 */

namespace BulkTab\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for YIKES + BulkTab coexistence.
 */
final class Coexistence {

	const YIKES_DISPLAY_CLASS = 'YIKES_Custom_Product_Tabs_Display';

	/**
	 * Keys of the WooCommerce tabs that YIKES added.
	 *
	 * A tab is YIKES's when its callback object is the YIKES display class, or
	 * (for themes/plugins that wrap callbacks) when its key and title equal a
	 * tab in the product's YIKES data and it carries YIKES's raw `content`.
	 * BulkTab's own tabs (`bulktab_ref`) are never dropped.
	 *
	 * @param array  $tabs        WooCommerce tabs (key => tab).
	 * @param array  $yikes_tabs  YikesData::product_tabs() for the product (may be empty).
	 * @param string $yikes_class Display class name.
	 * @return string[] Keys to remove.
	 */
	public static function keys_to_drop( array $tabs, array $yikes_tabs, string $yikes_class = self::YIKES_DISPLAY_CLASS ): array {
		$titles = array();
		foreach ( self::legacy_tabs( $yikes_tabs ) as $key => $tab ) {
			$titles[ (string) $key ] = $tab['title'];
		}

		$drop = array();
		foreach ( $tabs as $key => $tab ) {
			if ( ! is_array( $tab ) || isset( $tab['bulktab_ref'] ) ) {
				continue;
			}
			$callback = $tab['callback'] ?? null;
			if ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) && is_a( $callback[0], $yikes_class ) ) {
				$drop[] = (string) $key;
				continue;
			}
			$key = (string) $key;
			if ( isset( $titles[ $key ] ) && array_key_exists( 'content', $tab ) && (string) ( $tab['title'] ?? '' ) === $titles[ $key ] ) {
				$drop[] = $key;
			}
		}
		return $drop;
	}

	/**
	 * The tabs YIKES would add for this data (see its add_custom_product_tabs):
	 * empty titles skipped, keyed by id (a later duplicate replaces an earlier
	 * one), priorities from 25 in stored order.
	 *
	 * @param array $yikes_tabs YikesData::product_tabs() result.
	 * @return array<string, array{title:string,priority:int,content:string}>
	 */
	public static function legacy_tabs( array $yikes_tabs ): array {
		$out = array();
		$i   = 25;
		foreach ( $yikes_tabs as $tab ) {
			if ( '' === $tab['title'] || '0' === $tab['title'] ) {
				continue;
			}
			$out[ $tab['id'] ] = array(
				'title'    => $tab['title'],
				'priority' => $i++,
				'content'  => $tab['content'],
			);
		}
		return $out;
	}
}
