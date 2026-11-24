<?php
/**
 * A product's own tabs + per-product settings, as flat post meta.
 *
 *   _bulktab_{n}_title / _bulktab_{n}_content   n = 1..20 (slot numbers are stable refs: "c:n")
 *   _bulktab_{n}_content_prev                 previous content, one-step undo
 *   _bulktab_attach / _bulktab_order / _bulktab_hide   comma lists
 *
 * Plain values (no serialized arrays) so CSV import/export, WPML and SQL all
 * read them directly and one bad value can't wipe every tab.
 *
 * @package BulkTab
 */

namespace BulkTab;

/**
 * Reads and writes a product's own tabs and per-product lists.
 */
final class TabStore {

	const MAX_TABS = 20;

	/**
	 * Meta storage.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Constructor.
	 *
	 * @param MetaStore $meta Meta storage.
	 */
	public function __construct( MetaStore $meta ) {
		$this->meta = $meta;
	}

	/**
	 * A product's own tabs.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int, array{slot:int,title:string,content:string,has_prev:bool}> Keyed by slot.
	 */
	public function get_tabs( int $product_id ): array {
		$tabs = array();
		for ( $n = 1; $n <= self::MAX_TABS; $n++ ) {
			$title   = $this->meta->get( $product_id, "_bulktab_{$n}_title" );
			$content = $this->meta->get( $product_id, "_bulktab_{$n}_content" );
			if ( null === $title && null === $content ) {
				continue;
			}
			$tabs[ $n ] = array(
				'slot'     => $n,
				'title'    => (string) $title,
				'content'  => (string) $content,
				'has_prev' => null !== $this->meta->get( $product_id, "_bulktab_{$n}_content_prev" ),
			);
		}
		return $tabs;
	}

	/**
	 * Save tabs. $tabs is slot => array( 'title', 'content' ); slot 0 / missing = new tab
	 * (gets the lowest free slot). Slots not in $tabs are left alone; use delete_tab().
	 * Content changes keep the old content in `_prev`; if that backup fails, the
	 * content is not overwritten.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $tabs       Slot => array( 'title' => string, 'content' => string ).
	 * @return string[] Error messages (empty on success). Callers must show these.
	 */
	public function save_tabs( int $product_id, array $tabs ): array {
		$errors   = array();
		$existing = $this->get_tabs( $product_id );

		// Slots saved explicitly in this call are not free for new tabs, even if empty now.
		$taken = $existing;
		foreach ( array_keys( $tabs ) as $slot ) {
			$slot = (int) $slot;
			if ( $slot >= 1 && $slot <= self::MAX_TABS ) {
				$taken[ $slot ] = true;
			}
		}

		foreach ( $tabs as $slot => $tab ) {
			$tab  = (array) $tab;
			$slot = (int) $slot;
			if ( $slot < 1 || $slot > self::MAX_TABS ) {
				$slot = $this->free_slot( $taken );
				if ( 0 === $slot ) {
					$errors[] = sprintf( 'Tab "%s" was not added: the limit is %d tabs per product.', $tab['title'] ?? '', self::MAX_TABS );
					continue;
				}
			}
			$title   = (string) ( $tab['title'] ?? '' );
			$content = (string) ( $tab['content'] ?? '' );

			$taken[ $slot ] = true;

			$write_content = true;
			if ( isset( $existing[ $slot ] ) && $existing[ $slot ]['content'] !== $content ) {
				if ( ! $this->meta->set( $product_id, "_bulktab_{$slot}_content_prev", $existing[ $slot ]['content'] ) ) {
					// Without a backup, overwriting would lose the stored content for good.
					$errors[]      = sprintf( 'Could not keep the previous version of tab %d.', $slot );
					$write_content = false;
				}
			} elseif ( ! isset( $existing[ $slot ] ) ) {
				// A reused slot must not inherit an orphaned undo copy from a deleted tab.
				$this->meta->delete( $product_id, "_bulktab_{$slot}_content_prev" );
			}
			if ( ! $this->meta->set( $product_id, "_bulktab_{$slot}_title", $title ) ) {
				$errors[] = sprintf( 'Tab %d title was not saved.', $slot );
			}
			if ( ! $write_content || ! $this->meta->set( $product_id, "_bulktab_{$slot}_content", $content ) ) {
				$errors[] = sprintf( 'Tab %d content was not saved. Your text is unchanged in the editor; copy it before leaving.', $slot );
			}
			$existing[ $slot ] = array(
				'slot'     => $slot,
				'title'    => $title,
				'content'  => $content,
				'has_prev' => true,
			);
		}
		return $errors;
	}

	/**
	 * Delete a tab and drop its ref from the product's order and hide lists.
	 *
	 * @param int $product_id Product ID.
	 * @param int $slot       Slot number.
	 */
	public function delete_tab( int $product_id, int $slot ): void {
		foreach ( array( 'title', 'content', 'content_prev' ) as $field ) {
			$this->meta->delete( $product_id, "_bulktab_{$slot}_{$field}" );
		}
		$ref = 'c:' . $slot;
		foreach ( array( '_bulktab_order', '_bulktab_hide' ) as $key ) {
			$list = $this->get_list( $product_id, $key );
			if ( in_array( $ref, $list, true ) ) {
				$this->set_list( $product_id, $key, array_values( array_diff( $list, array( $ref ) ) ) );
			}
		}
	}

	/**
	 * Swap current and previous content (so undo can itself be undone).
	 *
	 * @param int $product_id Product ID.
	 * @param int $slot       Slot number.
	 * @return bool False when there is nothing to undo or a write failed.
	 */
	public function undo( int $product_id, int $slot ): bool {
		$prev = $this->meta->get( $product_id, "_bulktab_{$slot}_content_prev" );
		if ( null === $prev ) {
			return false;
		}
		$current = (string) $this->meta->get( $product_id, "_bulktab_{$slot}_content" );
		// Back up the current content first; if the second write fails, restore the old prev.
		if ( ! $this->meta->set( $product_id, "_bulktab_{$slot}_content_prev", $current ) ) {
			return false;
		}
		if ( ! $this->meta->set( $product_id, "_bulktab_{$slot}_content", $prev ) ) {
			$this->meta->set( $product_id, "_bulktab_{$slot}_content_prev", $prev );
			return false;
		}
		return true;
	}

	/**
	 * Read a comma-list meta value.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $key        Meta key.
	 * @return string[]
	 */
	public function get_list( int $product_id, string $key ): array {
		return Layout::parse_list( (string) $this->meta->get( $product_id, $key ) );
	}

	/**
	 * Write a comma-list meta value (deleted when empty).
	 *
	 * @param int      $product_id Product ID.
	 * @param string   $key        Meta key.
	 * @param string[] $values     Refs.
	 * @return bool
	 */
	public function set_list( int $product_id, string $key, array $values ): bool {
		$clean = Layout::parse_list( $values );
		if ( ! $clean ) {
			$this->meta->delete( $product_id, $key );
			return true;
		}
		return $this->meta->set( $product_id, $key, implode( ',', $clean ) );
	}

	/**
	 * Lowest slot not in $taken, or 0 when all are used.
	 *
	 * @param array $taken Slot => anything.
	 * @return int
	 */
	private function free_slot( array $taken ): int {
		for ( $n = 1; $n <= self::MAX_TABS; $n++ ) {
			if ( ! isset( $taken[ $n ] ) ) {
				return $n;
			}
		}
		return 0;
	}
}
