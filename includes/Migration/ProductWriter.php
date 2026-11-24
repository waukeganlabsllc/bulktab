<?php
/**
 * Writes a Mapper plan to one product through TabStore, idempotently.
 *
 * Marker meta `_bulktab_yikes_migrated` = "{time}:{source sha1}:{state sha1}:{refs}"
 * - source sha1: Mapper::source_hash(); unchanged = skip.
 * - state sha1:  what this import wrote (titles + content of its slots); if the
 *   BulkTab copy was edited since, a changed YIKES source is NOT re-imported
 *   over it (reported as a conflict).
 * - refs:        what this import added ("c:3", "s:size-guide", "reviews"), so a
 *   re-import replaces only its own tabs and never the product's native ones.
 *
 * YIKES data is never written. Pure PHP (MetaStore), no WordPress calls.
 *
 * @package BulkTab
 */

namespace BulkTab\Migration;

use BulkTab\Layout;
use BulkTab\MetaStore;
use BulkTab\TabStore;

defined( 'ABSPATH' ) || exit;

/**
 * Applies migration plans to product meta.
 */
final class ProductWriter {

	const MARKER = '_bulktab_yikes_migrated';

	const WRITTEN   = 'written';
	const UNCHANGED = 'unchanged';
	const CONFLICT  = 'conflict';
	const NOTHING   = 'nothing';
	const ERROR     = 'error';

	/**
	 * Meta storage.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Tab storage.
	 *
	 * @var TabStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param MetaStore $meta Meta storage.
	 */
	public function __construct( MetaStore $meta ) {
		$this->meta  = $meta;
		$this->store = new TabStore( $meta );
	}

	/**
	 * Apply (or, with $dry, only simulate) a plan.
	 *
	 * @param int    $product_id  Product ID.
	 * @param array  $plan        Mapper::map_product() result.
	 * @param string $source_hash Mapper::source_hash() result.
	 * @param int    $now         Unix time for the marker.
	 * @param bool   $dry         Simulate only: nothing is written.
	 * @return array{status:string,slots:int[],overflow:string[],errors:string[]}
	 */
	public function apply( int $product_id, array $plan, string $source_hash, int $now, bool $dry ): array {
		$result = array(
			'status'   => self::WRITTEN,
			'slots'    => array(),
			'overflow' => array(),
			'errors'   => array(),
		);

		$marker = self::parse_marker( $this->meta->get( $product_id, self::MARKER ) );
		if ( $marker && $marker['source'] === $source_hash ) {
			$result['status'] = self::UNCHANGED;
			return $result;
		}
		if ( $marker && $this->state_hash( $product_id, $marker['refs'] ) !== $marker['state'] ) {
			$result['status'] = self::CONFLICT;
			return $result;
		}
		if ( ! $marker && ! $plan['items'] && ! $plan['attach'] && ! $plan['hide'] ) {
			$result['status'] = self::NOTHING;
			return $result;
		}

		$old_slots = array();
		foreach ( $marker ? $marker['refs'] : array() as $ref ) {
			if ( 0 === strpos( $ref, 'c:' ) ) {
				$old_slots[ (int) substr( $ref, 2 ) ] = true;
			}
		}
		if ( $marker && ! $dry ) {
			$this->undo( $product_id, $marker['refs'] );
		}

		// Free slots, lowest first (slots of the previous import are reused).
		$taken = array_diff_key( $this->store->get_tabs( $product_id ), $old_slots );
		$free  = array();
		for ( $n = 1; $n <= TabStore::MAX_TABS; $n++ ) {
			if ( ! isset( $taken[ $n ] ) ) {
				$free[] = $n;
			}
		}

		$refs   = array();
		$hide   = $plan['hide'];
		$attach = $plan['attach'];
		$writes = array();
		foreach ( $plan['items'] as $item ) {
			if ( 'shared' === $item['kind'] ) {
				$refs[] = 's:' . $item['key'];
				continue;
			}
			if ( ! $free ) {
				$result['overflow'][] = $item['title'];
				continue;
			}
			$slot              = array_shift( $free );
			$writes[ $slot ]   = array(
				'title'   => $item['title'],
				'content' => $item['content'],
			);
			$refs[]            = 'c:' . $slot;
			$result['slots'][] = $slot;
			if ( ! empty( $item['hidden'] ) ) {
				$hide[] = 'c:' . $slot;
			}
		}

		if ( $dry ) {
			return $result;
		}

		$result['errors'] = $this->store->save_tabs( $product_id, $writes );
		if ( $result['errors'] ) {
			$result['status'] = self::ERROR;
			$this->meta->delete( $product_id, self::MARKER ); // The next run retries this product.
			return $result;
		}

		$existing_order = $this->store->get_list( $product_id, '_bulktab_order' );
		$order          = array_merge( array( 'description', 'additional_information' ), $refs, array( 'reviews' ) );
		$order          = array_merge( $order, array_diff( $existing_order, $order ) );

		$ok = $this->store->set_list( $product_id, '_bulktab_attach', array_merge( $this->store->get_list( $product_id, '_bulktab_attach' ), $attach ) )
			&& $this->store->set_list( $product_id, '_bulktab_hide', array_merge( $this->store->get_list( $product_id, '_bulktab_hide' ), $hide ) )
			&& $this->store->set_list( $product_id, '_bulktab_order', $order );
		if ( ! $ok ) {
			$result['status']   = self::ERROR;
			$result['errors'][] = 'Tab order, attached or hidden tabs were not saved.';
			$this->meta->delete( $product_id, self::MARKER );
			return $result;
		}

		$added = array();
		foreach ( $attach as $key ) {
			$added[] = 's:' . $key;
		}
		foreach ( $result['slots'] as $slot ) {
			$added[] = 'c:' . $slot;
		}
		foreach ( $plan['hide'] as $ref ) {
			if ( in_array( $ref, Layout::BUILT_IN, true ) ) {
				$added[] = $ref;
			}
		}
		$value = $now . ':' . $source_hash . ':' . $this->state_hash( $product_id, $added ) . ':' . implode( ',', $added );
		if ( ! $this->meta->set( $product_id, self::MARKER, $value ) ) {
			$result['status']   = self::ERROR;
			$result['errors'][] = 'The import marker was not saved; the product will be imported again next time.';
		}
		return $result;
	}

	/**
	 * Parse a marker value.
	 *
	 * @param string|null $value Stored marker.
	 * @return array{time:int,source:string,state:string,refs:string[]}|null
	 */
	public static function parse_marker( ?string $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		$parts = explode( ':', $value, 4 );
		if ( 4 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! preg_match( '/^[0-9a-f]{40}$/', $parts[1] ) ) {
			return null;
		}
		return array(
			'time'   => (int) $parts[0],
			'source' => $parts[1],
			'state'  => $parts[2],
			'refs'   => Layout::parse_list( $parts[3] ),
		);
	}

	/**
	 * Hash of the slots an import wrote, as they are stored now.
	 *
	 * @param int      $product_id Product ID.
	 * @param string[] $refs       Refs added by the import.
	 * @return string
	 */
	private function state_hash( int $product_id, array $refs ): string {
		$parts = array();
		foreach ( $refs as $ref ) {
			if ( 0 === strpos( $ref, 'c:' ) ) {
				$n       = (int) substr( $ref, 2 );
				$parts[] = $n . "\x1f" . (string) $this->meta->get( $product_id, "_bulktab_{$n}_title" ) . "\x1f" . (string) $this->meta->get( $product_id, "_bulktab_{$n}_content" );
			}
		}
		return sha1( implode( "\x1e", $parts ) );
	}

	/**
	 * Remove what a previous import added.
	 *
	 * @param int      $product_id Product ID.
	 * @param string[] $refs       Refs from the marker.
	 */
	private function undo( int $product_id, array $refs ): void {
		$attach = $this->store->get_list( $product_id, '_bulktab_attach' );
		$hide   = $this->store->get_list( $product_id, '_bulktab_hide' );
		$order  = $this->store->get_list( $product_id, '_bulktab_order' );
		foreach ( $refs as $ref ) {
			if ( 0 === strpos( $ref, 'c:' ) ) {
				continue;
			}
			if ( 0 === strpos( $ref, 's:' ) ) {
				$attach = array_diff( $attach, array( substr( $ref, 2 ) ) );
				$order  = array_diff( $order, array( $ref ) );
			}
			$hide = array_diff( $hide, array( $ref ) );
		}
		$this->store->set_list( $product_id, '_bulktab_attach', array_values( $attach ) );
		$this->store->set_list( $product_id, '_bulktab_hide', array_values( $hide ) );
		$this->store->set_list( $product_id, '_bulktab_order', array_values( $order ) );
		foreach ( $refs as $ref ) {
			if ( 0 === strpos( $ref, 'c:' ) ) {
				$this->store->delete_tab( $product_id, (int) substr( $ref, 2 ) );
			}
		}
	}
}
