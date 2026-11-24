<?php
/**
 * Applies one CSV row's BulkTab cells to a product through TabStore. Pure PHP
 * (sanitizers are injected), so the semantics are unit-tested without WordPress.
 *
 * Per field (a slot's title or content, or a list):
 *   - column absent     -> field untouched
 *   - cell is `[keep]`  -> field untouched
 *   - cell is empty     -> field cleared
 *   - otherwise         -> field replaced (a list cell whose items are all invalid
 *                          leaves the list untouched; skipped items are reported)
 *
 * Clearing a tab's title and content keeps the slot (empty tabs are not shown) so the
 * old content stays in TabStore's `_prev` undo copy; nothing is deleted by an import.
 * Content equal to the stored content (ignoring line endings and surrounding spaces,
 * which spreadsheets change) is not rewritten, so a re-imported export does not
 * replace the undo copy or re-filter HTML stored by an administrator.
 *
 * @package BulkTab
 */

namespace BulkTab\Integrations\Csv;

use BulkTab\Layout;
use BulkTab\TabStore;

/**
 * CSV row -> TabStore changes.
 */
final class RowImporter {

	/**
	 * Tab storage.
	 *
	 * @var TabStore
	 */
	private $store;

	/**
	 * Title sanitizer: fn( string ): string.
	 *
	 * @var callable
	 */
	private $sanitize_title;

	/**
	 * Content sanitizer: fn( string ): string.
	 *
	 * @var callable
	 */
	private $sanitize_content;

	/**
	 * Constructor.
	 *
	 * @param TabStore      $store            Tab storage.
	 * @param callable|null $sanitize_title   fn( string ): string. Default: identity.
	 * @param callable|null $sanitize_content fn( string ): string. Default: identity.
	 */
	public function __construct( TabStore $store, ?callable $sanitize_title = null, ?callable $sanitize_content = null ) {
		$identity               = static function ( string $value ): string {
			return $value;
		};
		$this->store            = $store;
		$this->sanitize_title   = $sanitize_title ? $sanitize_title : $identity;
		$this->sanitize_content = $sanitize_content ? $sanitize_content : $identity;
	}

	/**
	 * Whether the row has any BulkTab column.
	 *
	 * @param array $data Importer row data (mapped key => value).
	 * @return bool
	 */
	public static function has_columns( array $data ): bool {
		foreach ( array_keys( $data ) as $key ) {
			if ( Columns::is_ours( (string) $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read the product's tabs and lists, plan the changes and write them.
	 *
	 * @param int        $product_id   Product ID.
	 * @param array      $data         Importer row data (mapped key => value).
	 * @param array|null $known_shared Keys of existing shared tabs, or null to skip that check.
	 * @return array{changed:bool,messages:array<int,array{level:string,code:string,args:array}>}
	 *         Messages use the codes documented on plan(), plus 'store' (args: TabStore error
	 *         text) and 'list_not_saved' (args: column header), both at level 'error'.
	 */
	public function import( int $product_id, array $data, ?array $known_shared = null ): array {
		if ( ! self::has_columns( $data ) ) {
			return array(
				'changed'  => false,
				'messages' => array(),
			);
		}

		$tabs  = $this->store->get_tabs( $product_id );
		$lists = array();
		foreach ( Columns::LISTS as $meta_key ) {
			$lists[ $meta_key ] = $this->store->get_list( $product_id, $meta_key );
		}

		$plan     = $this->plan( $data, $tabs, $lists, $known_shared );
		$messages = $plan['messages'];

		if ( $plan['save'] ) {
			foreach ( $this->store->save_tabs( $product_id, $plan['save'] ) as $error ) {
				$messages[] = self::message( 'error', 'store', array( (string) $error ) );
			}
		}
		foreach ( $plan['lists'] as $meta_key => $values ) {
			if ( ! $this->store->set_list( $product_id, $meta_key, $values ) ) {
				$messages[] = self::message( 'error', 'list_not_saved', array( Columns::header( (string) array_search( $meta_key, Columns::LISTS, true ) ) ) );
			}
		}

		return array(
			'changed'  => (bool) ( $plan['save'] || $plan['lists'] ),
			'messages' => $messages,
		);
	}

	/**
	 * Work out the changes for one row, without writing anything.
	 *
	 * Warning codes (level 'warning'):
	 *   bad_slot         args: header           tab number outside 1..MAX_TABS; column ignored
	 *   bad_ref          args: header, token    not a valid key/ref; skipped
	 *   unknown_shared   args: header, key      no such shared tab; kept anyway
	 *   missing_slot_ref args: header, ref      `c:n` for a tab the product does not have; skipped
	 *   content_filtered args: slot             the content sanitizer changed the HTML
	 *
	 * @param array      $data         Importer row data (mapped key => value).
	 * @param array      $tabs         TabStore::get_tabs() result.
	 * @param array      $lists        Meta key => current string[] list.
	 * @param array|null $known_shared Keys of existing shared tabs, or null to skip that check.
	 * @return array{save:array<int,array{title:string,content:string}>,lists:array<string,string[]>,messages:array}
	 */
	public function plan( array $data, array $tabs, array $lists, ?array $known_shared = null ): array {
		$messages   = array();
		$slot_cells = array();
		$list_cells = array();

		foreach ( $data as $key => $value ) {
			$column = Columns::parse_id( (string) $key );
			if ( null === $column || ! ( null === $value || is_scalar( $value ) ) ) {
				continue;
			}
			$value = (string) $value;
			if ( 'list' === $column['kind'] ) {
				$list_cells[ (string) $key ] = $value;
			} elseif ( 0 === $column['slot'] ) {
				if ( ! Cells::is_blank( $value ) && ! Cells::is_keep( $value ) ) {
					$messages[] = self::message( 'warning', 'bad_slot', array( Columns::header( (string) $key ) ) );
				}
			} else {
				$slot_cells[ $column['slot'] ][ $column['field'] ] = $value;
			}
		}
		ksort( $slot_cells );

		$save  = array();
		$slots = array_fill_keys( array_map( 'intval', array_keys( $tabs ) ), true );

		foreach ( $slot_cells as $slot => $cells ) {
			$old         = isset( $tabs[ $slot ] ) ? (array) $tabs[ $slot ] : null;
			$old_title   = null !== $old ? (string) ( $old['title'] ?? '' ) : '';
			$old_content = null !== $old ? (string) ( $old['content'] ?? '' ) : '';

			$title   = $this->resolve_title( $cells, $old_title );
			$content = $this->resolve_content( $cells, $old_content, (int) $slot, $messages );

			if ( null === $old ) {
				if ( '' === Cells::trim( $title ) && Cells::is_blank( $content ) ) {
					continue; // Nothing to create.
				}
			} elseif ( $title === $old_title && $content === $old_content ) {
				continue; // Unchanged.
			}

			$save[ $slot ]  = array(
				'title'   => $title,
				'content' => $content,
			);
			$slots[ $slot ] = true;
		}

		$list_changes = array();
		foreach ( Columns::LISTS as $id => $meta_key ) {
			if ( ! array_key_exists( $id, $list_cells ) || Cells::is_keep( $list_cells[ $id ] ) ) {
				continue;
			}
			$values = $this->resolve_list( $id, $list_cells[ $id ], $slots, $known_shared, $messages );
			if ( array() === $values && ! Cells::is_blank( (string) preg_replace( '/[,;\r\n]+/', '', $list_cells[ $id ] ) ) ) {
				continue; // Every item was skipped (and reported): a typo must not wipe the list.
			}
			$current = isset( $lists[ $meta_key ] ) ? array_values( (array) $lists[ $meta_key ] ) : array();
			if ( $values !== $current ) {
				$list_changes[ $meta_key ] = $values;
			}
		}

		return array(
			'save'     => $save,
			'lists'    => $list_changes,
			'messages' => $messages,
		);
	}

	/**
	 * New title for a slot.
	 *
	 * @param array  $cells     Field => raw cell for this slot.
	 * @param string $old_title Stored title ('' for a new slot).
	 * @return string
	 */
	private function resolve_title( array $cells, string $old_title ): string {
		if ( ! array_key_exists( 'title', $cells ) || Cells::is_keep( $cells['title'] ) ) {
			return $old_title;
		}
		$raw = $cells['title'];
		if ( Cells::is_blank( $raw ) ) {
			return '';
		}
		if ( Cells::trim( $raw ) === $old_title ) {
			return $old_title;
		}
		return (string) call_user_func( $this->sanitize_title, Cells::trim( $raw ) );
	}

	/**
	 * New content for a slot.
	 *
	 * @param array  $cells       Field => raw cell for this slot.
	 * @param string $old_content Stored content ('' for a new slot).
	 * @param int    $slot        Slot number (for messages).
	 * @param array  $messages    Messages, appended to.
	 * @return string
	 */
	private function resolve_content( array $cells, string $old_content, int $slot, array &$messages ): string {
		if ( ! array_key_exists( 'content', $cells ) || Cells::is_keep( $cells['content'] ) ) {
			return $old_content;
		}
		$raw = $cells['content'];
		if ( Cells::is_blank( $raw ) ) {
			return '';
		}
		if ( Cells::same_text( $raw, $old_content ) ) {
			return $old_content;
		}
		$clean = (string) call_user_func( $this->sanitize_content, $raw );
		if ( $clean !== $raw ) {
			$messages[] = self::message( 'warning', 'content_filtered', array( $slot ) );
		}
		return Cells::same_text( $clean, $old_content ) ? $old_content : $clean;
	}

	/**
	 * Parse a list cell. Accepts commas, semicolons or line breaks between items.
	 *
	 * @param string     $id           List column id.
	 * @param string     $raw          Cell value.
	 * @param array      $slots        Slot => true for every tab the product will have.
	 * @param array|null $known_shared Existing shared-tab keys, or null.
	 * @param array      $messages     Messages, appended to.
	 * @return string[]
	 */
	private function resolve_list( string $id, string $raw, array $slots, ?array $known_shared, array &$messages ): array {
		$header = Columns::header( $id );
		$known  = null !== $known_shared ? array_fill_keys( array_map( 'strval', $known_shared ), true ) : null;
		$out    = array();

		foreach ( (array) preg_split( '/[,;\r\n]+/', $raw ) as $token ) {
			$token = Cells::trim( (string) $token );
			if ( '' === $token ) {
				continue;
			}
			if ( Columns::ATTACHED === $id && 0 === strpos( $token, 's:' ) ) {
				$token = substr( $token, 2 ); // Accept refs ("s:size-guide") where keys are expected.
			}
			if ( array() === Layout::parse_list( array( $token ) ) ) {
				$messages[] = self::message( 'warning', 'bad_ref', array( $header, $token ) );
				continue;
			}

			$shared_key = Columns::ATTACHED === $id ? $token : ( 0 === strpos( $token, 's:' ) ? substr( $token, 2 ) : null );
			if ( null !== $shared_key && null !== $known && ! isset( $known[ $shared_key ] ) ) {
				$messages[] = self::message( 'warning', 'unknown_shared', array( $header, $shared_key ) );
			}
			if ( Columns::ATTACHED !== $id && preg_match( '/^c:([0-9]+)$/', $token, $m ) && ! isset( $slots[ (int) $m[1] ] ) ) {
				$messages[] = self::message( 'warning', 'missing_slot_ref', array( $header, $token ) );
				continue;
			}
			$out[] = $token;
		}

		return Layout::parse_list( $out );
	}

	/**
	 * Build a message.
	 *
	 * @param string $level 'error' or 'warning'.
	 * @param string $code  Message code.
	 * @param array  $args  Message arguments.
	 * @return array{level:string,code:string,args:array}
	 */
	private static function message( string $level, string $code, array $args ): array {
		return array(
			'level' => $level,
			'code'  => $code,
			'args'  => $args,
		);
	}
}
