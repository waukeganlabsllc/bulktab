<?php
/**
 * Column ids and header names for the WooCommerce product CSV. Pure PHP.
 *
 * Export uses one selectable column, `bulktab`, which expands to
 * `bulktab:title:{n}`, `bulktab:content:{n}` (one pair per slot in use),
 * `bulktab:attached`, `bulktab:hidden` and `bulktab:order`. The colon matters:
 * WooCommerce's `is_column_exporting()` only looks at the part before it, so the
 * single "BulkTab tabs" checkbox covers every expanded column.
 *
 * Headers are deliberately not translated, so a CSV exported on one site maps
 * automatically on any other, whatever its language.
 *
 * @package BulkTab
 */

namespace BulkTab\Integrations\Csv;

use BulkTab\TabStore;

/**
 * Column naming, parsing and import auto-mapping.
 */
final class Columns {

	/** Export checkbox id; also the prefix of every column id. */
	const GROUP = 'bulktab';

	const ATTACHED = 'bulktab:attached';
	const HIDDEN   = 'bulktab:hidden';
	const ORDER    = 'bulktab:order';

	/** List column id => product meta key. */
	const LISTS = array(
		self::ATTACHED => '_bulktab_attach',
		self::HIDDEN   => '_bulktab_hide',
		self::ORDER    => '_bulktab_order',
	);

	/** List column id => header. */
	const LIST_HEADERS = array(
		self::ATTACHED => 'BulkTab attached',
		self::HIDDEN   => 'BulkTab hidden',
		self::ORDER    => 'BulkTab order',
	);

	/**
	 * Column id of a slot's title.
	 *
	 * @param int $slot Slot number.
	 * @return string
	 */
	public static function title_id( int $slot ): string {
		return self::GROUP . ':title:' . $slot;
	}

	/**
	 * Column id of a slot's content.
	 *
	 * @param int $slot Slot number.
	 * @return string
	 */
	public static function content_id( int $slot ): string {
		return self::GROUP . ':content:' . $slot;
	}

	/**
	 * Header of a column id ("BulkTab 3 title"), or the id itself when it is not ours.
	 *
	 * @param string $id Column id.
	 * @return string
	 */
	public static function header( string $id ): string {
		if ( isset( self::LIST_HEADERS[ $id ] ) ) {
			return self::LIST_HEADERS[ $id ];
		}
		$parsed = self::parse_id( $id );
		if ( null !== $parsed && 'slot' === $parsed['kind'] ) {
			return sprintf( 'BulkTab %s %s', $parsed['raw_slot'], $parsed['field'] );
		}
		return $id;
	}

	/**
	 * Parse a column id.
	 *
	 * @param string $id Column id (export id or import mapped key).
	 * @return array{kind:string,field:string,slot:int,raw_slot:string}|null Null when not ours.
	 *         kind 'slot' (field title|content; slot 0 when the number is invalid) or 'list'.
	 */
	public static function parse_id( string $id ): ?array {
		if ( isset( self::LISTS[ $id ] ) ) {
			return array(
				'kind'     => 'list',
				'field'    => substr( $id, strlen( self::GROUP ) + 1 ),
				'slot'     => 0,
				'raw_slot' => '',
			);
		}
		if ( ! preg_match( '/^' . self::GROUP . ':(title|content):(.*)$/s', $id, $m ) ) {
			return null;
		}
		$raw  = trim( $m[2] );
		$slot = preg_match( '/^[0-9]{1,3}$/', $raw ) ? (int) $raw : 0;
		if ( $slot < 1 || $slot > TabStore::MAX_TABS ) {
			$slot = 0;
		}
		return array(
			'kind'     => 'slot',
			'field'    => $m[1],
			'slot'     => $slot,
			'raw_slot' => $raw,
		);
	}

	/**
	 * Whether a column id or mapped key is one of ours.
	 *
	 * @param string $id Column id.
	 * @return bool
	 */
	public static function is_ours( string $id ): bool {
		return null !== self::parse_id( $id );
	}

	/**
	 * Whether a column id holds tab content (HTML).
	 *
	 * @param string $id Column id.
	 * @return bool
	 */
	public static function is_content( string $id ): bool {
		return 0 === strpos( $id, self::GROUP . ':content:' );
	}

	/**
	 * Export columns for the given slots, in a stable order: slot pairs ascending, then lists.
	 *
	 * @param int[] $slots Slots in use.
	 * @return array<string, string> Column id => header.
	 */
	public static function export_columns( array $slots ): array {
		$out = array();
		foreach ( self::normalize_slots( $slots ) as $slot ) {
			$out[ self::title_id( $slot ) ]   = self::header( self::title_id( $slot ) );
			$out[ self::content_id( $slot ) ] = self::header( self::content_id( $slot ) );
		}
		return $out + self::LIST_HEADERS;
	}

	/**
	 * Replace the `bulktab` placeholder in WooCommerce's column list with the expanded columns
	 * (in place), or drop it when our columns are not being exported. Idempotent.
	 *
	 * @param array $columns   Column id => header.
	 * @param int[] $slots     Slots in use.
	 * @param bool  $exporting Whether the BulkTab column is selected.
	 * @return array
	 */
	public static function expand( array $columns, array $slots, bool $exporting ): array {
		$ours = $exporting ? self::export_columns( $slots ) : array();
		$out  = array();
		$done = false;
		foreach ( $columns as $id => $header ) {
			if ( self::GROUP === $id ) {
				$out += $ours;
				$done = true;
				continue;
			}
			if ( self::is_ours( (string) $id ) ) {
				continue; // Already expanded (filter ran twice); rebuilt from $ours.
			}
			$out[ $id ] = $header;
		}
		if ( ! $done ) {
			$out += $ours;
		}
		return $out;
	}

	/**
	 * Slots that have a title or content, from a list of meta keys.
	 *
	 * @param string[] $meta_keys Meta keys (e.g. every distinct `_bulktab_%` key).
	 * @return int[] Sorted slot numbers.
	 */
	public static function slots_from_meta_keys( array $meta_keys ): array {
		$slots = array();
		foreach ( $meta_keys as $key ) {
			if ( preg_match( '/^_bulktab_([0-9]{1,3})_(?:title|content)$/', (string) $key, $m ) ) {
				$slots[] = (int) $m[1];
			}
		}
		return self::normalize_slots( $slots );
	}

	/**
	 * Import auto-mapping for fixed headers (lower-cased header => mapped key), for
	 * `woocommerce_csv_product_import_mapping_default_columns`.
	 *
	 * @return array<string, string>
	 */
	public static function default_mapping(): array {
		$out = array();
		foreach ( self::LIST_HEADERS as $id => $header ) {
			$out[ strtolower( $header ) ] = $id;
		}
		return $out;
	}

	/**
	 * Import auto-mapping for numbered headers (`%d` pattern => key prefix), for
	 * `woocommerce_csv_product_import_mapping_special_columns`.
	 *
	 * @return array<string, string>
	 */
	public static function special_mapping(): array {
		return array(
			'BulkTab %d title'   => self::GROUP . ':title:',
			'BulkTab %d content' => self::GROUP . ':content:',
		);
	}

	/**
	 * Slots to offer in the import mapping dropdown for a column: the number in its
	 * header (or current mapping) when it has a valid one, otherwise every slot.
	 *
	 * @param string $item Header or current mapped key.
	 * @return int[]
	 */
	public static function mapping_slots( string $item ): array {
		if ( preg_match( '/[0-9]+/', $item, $m ) ) {
			$n = (int) $m[0];
			if ( $n >= 1 && $n <= TabStore::MAX_TABS ) {
				return array( $n );
			}
		}
		return range( 1, TabStore::MAX_TABS );
	}

	/**
	 * Unique, valid, sorted slot numbers.
	 *
	 * @param array $slots Slot numbers.
	 * @return int[]
	 */
	public static function normalize_slots( array $slots ): array {
		$out = array();
		foreach ( $slots as $slot ) {
			$slot = (int) $slot;
			if ( $slot >= 1 && $slot <= TabStore::MAX_TABS ) {
				$out[ $slot ] = $slot;
			}
		}
		ksort( $out );
		return array_values( $out );
	}
}
