<?php
/**
 * Builds one product's BulkTab cells for the WooCommerce CSV export. Pure PHP.
 *
 * Values are plain strings, never serialized; content HTML is passed through
 * untouched (WooCommerce quotes it and guards formula-like values).
 *
 * @package BulkTab
 */

namespace BulkTab\Integrations\Csv;

/**
 * Export row builder.
 */
final class ExportRow {

	/** Separator for list cells. Import accepts any commas and spaces. */
	const LIST_SEPARATOR = ', ';

	/**
	 * Cells for one product.
	 *
	 * @param array $tabs  TabStore::get_tabs() result (slot => array( 'title', 'content' )).
	 * @param array $lists Meta key => string[] (`_bulktab_attach`, `_bulktab_hide`, `_bulktab_order`).
	 * @param int[] $slots Slot columns in this export (a product's slot outside it cannot occur:
	 *                     the slot set covers every slot used in the catalog).
	 * @return array<string, string> Column id => value.
	 */
	public static function build( array $tabs, array $lists, array $slots ): array {
		$row = array();
		foreach ( Columns::normalize_slots( $slots ) as $slot ) {
			$tab = isset( $tabs[ $slot ] ) ? (array) $tabs[ $slot ] : array();

			$row[ Columns::title_id( $slot ) ]   = (string) ( $tab['title'] ?? '' );
			$row[ Columns::content_id( $slot ) ] = (string) ( $tab['content'] ?? '' );
		}
		foreach ( Columns::LISTS as $id => $meta_key ) {
			$values     = isset( $lists[ $meta_key ] ) ? (array) $lists[ $meta_key ] : array();
			$row[ $id ] = implode( self::LIST_SEPARATOR, array_map( 'strval', $values ) );
		}
		return $row;
	}
}
