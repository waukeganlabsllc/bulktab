<?php
/**
 * WooCommerce product CSV import/export of BulkTab data (Products > Export / Import).
 *
 * Export: one "BulkTab tabs" checkbox expands to plain columns, never serialized:
 * "BulkTab {n} title" / "BulkTab {n} content" for every slot used in the catalog,
 * then "BulkTab attached" (shared-tab keys), "BulkTab hidden" and "BulkTab order"
 * (comma lists of refs). Shared-tab content is not exported; it lives in the post type.
 *
 * Import: those headers map automatically. Content keeps its HTML (WooCommerce's
 * default wc_clean() would strip it) and is filtered with kses unless the importing
 * user has unfiltered_html. Rows are written through TabStore, so content changes keep
 * the `_prev` undo copy. Column absent = untouched, empty cell = cleared, `[keep]` =
 * untouched (see Csv\RowImporter). Problems are logged to the WooCommerce log and
 * shown in an admin notice after the import.
 *
 * All logic that does not need WordPress lives in Csv\* and is unit-tested.
 *
 * @package BulkTab
 */

namespace BulkTab\Integrations;

use BulkTab\Content;
use BulkTab\Integrations\Csv\Cells;
use BulkTab\Integrations\Csv\Columns;
use BulkTab\Integrations\Csv\ExportRow;
use BulkTab\Integrations\Csv\RowImporter;
use BulkTab\RulesIndex;
use BulkTab\TabStore;
use BulkTab\WpMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce CSV hooks.
 */
final class WcCsv {

	/** WooCommerce log source. */
	const LOG_SOURCE = 'bulktab-csv-import';

	/** Lines kept for the admin notice; the full list is in the WooCommerce log. */
	const NOTICE_LINES = 20;

	/**
	 * Slots exported in this request (cached per export across batches).
	 *
	 * @var int[]|null
	 */
	private static $slots = null;

	/**
	 * Whether the current export includes our columns.
	 *
	 * @var bool
	 */
	private static $exporting = false;

	/**
	 * Keys of published shared tabs, per request.
	 *
	 * @var string[]|null
	 */
	private static $shared_keys = null;

	/**
	 * Tab storage.
	 *
	 * @var TabStore|null
	 */
	private static $store = null;

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		// Export.
		add_filter( 'woocommerce_product_export_product_default_columns', array( self::class, 'add_export_column' ) );
		add_filter( 'woocommerce_product_export_column_names', array( self::class, 'expand_export_columns' ), 20, 2 );
		add_filter( 'woocommerce_product_export_row_data', array( self::class, 'export_row' ), 10, 3 );
		add_filter( 'woocommerce_product_export_skip_meta_keys', array( self::class, 'skip_meta_keys' ), 10, 2 );

		// Import.
		add_filter( 'woocommerce_csv_product_import_mapping_options', array( self::class, 'mapping_options' ), 10, 2 );
		add_filter( 'woocommerce_csv_product_import_mapping_default_columns', array( self::class, 'mapping_default_columns' ) );
		add_filter( 'woocommerce_csv_product_import_mapping_special_columns', array( self::class, 'mapping_special_columns' ) );
		add_filter( 'woocommerce_product_importer_formatting_callbacks', array( self::class, 'formatting_callbacks' ), 10, 2 );
		add_filter( 'woocommerce_product_csv_importer_args', array( self::class, 'maybe_reset_log' ) );
		add_action( 'woocommerce_product_import_inserted_product_object', array( self::class, 'import_row' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'render_log' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Export
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Add the single selectable "BulkTab tabs" column to the export screen.
	 *
	 * @param array $columns Column id => label.
	 * @return array
	 */
	public static function add_export_column( $columns ) {
		if ( is_array( $columns ) ) {
			$columns[ Columns::GROUP ] = __( 'BulkTab tabs', 'bulktab' );
		}
		return $columns;
	}

	/**
	 * Expand the placeholder into the numbered columns.
	 *
	 * @param array  $columns  Column id => header.
	 * @param object $exporter WC_Product_CSV_Exporter.
	 * @return array
	 */
	public static function expand_export_columns( $columns, $exporter = null ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		self::$exporting = self::is_exporting( $exporter );
		return Columns::expand( $columns, self::$exporting ? self::export_slots( $exporter ) : array(), self::$exporting );
	}

	/**
	 * Fill our cells for one product.
	 *
	 * @param array       $row      Column id => value.
	 * @param \WC_Product $product  Product.
	 * @param object      $exporter WC_Product_CSV_Exporter.
	 * @return array
	 */
	public static function export_row( $row, $product, $exporter = null ) {
		if ( ! is_array( $row ) ) {
			return $row;
		}
		unset( $row[ Columns::GROUP ] ); // Placeholder value WooCommerce sets for the truncated column id.

		// Variations have no tabs of their own; their cells stay empty.
		if ( ! $product instanceof \WC_Product || $product->is_type( 'variation' ) || ! self::is_exporting( $exporter ) ) {
			return $row;
		}

		$id    = (int) $product->get_id();
		$store = self::store();
		$lists = array();
		foreach ( Columns::LISTS as $meta_key ) {
			$lists[ $meta_key ] = $store->get_list( $id, $meta_key );
		}
		foreach ( ExportRow::build( $store->get_tabs( $id ), $lists, self::export_slots( $exporter ) ) as $column => $value ) {
			$row[ $column ] = $value;
		}
		return $row;
	}

	/**
	 * Keep our raw meta out of "Export custom meta" when our columns are exported,
	 * so the data is not in the file twice (and never re-imported around TabStore).
	 *
	 * @param string[]    $keys    Meta keys to skip.
	 * @param \WC_Product $product Product.
	 * @return string[]
	 */
	public static function skip_meta_keys( $keys, $product = null ) {
		if ( ! self::$exporting || ! is_array( $keys ) || ! $product instanceof \WC_Product ) {
			return $keys;
		}
		foreach ( $product->get_meta_data() as $meta ) {
			$key = isset( $meta->key ) ? (string) $meta->key : '';
			if ( 0 === strpos( $key, '_bulktab_' ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Whether the exporter has the BulkTab column selected (or no selection = all).
	 *
	 * @param object|null $exporter Exporter.
	 * @return bool
	 */
	private static function is_exporting( $exporter ): bool {
		if ( is_object( $exporter ) && method_exists( $exporter, 'is_column_exporting' ) ) {
			return (bool) $exporter->is_column_exporting( Columns::GROUP );
		}
		return true;
	}

	/**
	 * Slot columns for this export. Each batch is its own request but every batch
	 * must write the same columns, so page 1 computes the set and later pages reuse it.
	 *
	 * @param object|null $exporter Exporter.
	 * @return int[]
	 */
	private static function export_slots( $exporter ): array {
		if ( null !== self::$slots ) {
			return self::$slots;
		}
		$cache_key = '';
		if ( is_object( $exporter ) && method_exists( $exporter, 'get_filename' ) ) {
			$cache_key = 'bulktab_csv_slots_' . md5( get_current_user_id() . '|' . $exporter->get_filename() );
		}
		$page = is_object( $exporter ) && method_exists( $exporter, 'get_page' ) ? (int) $exporter->get_page() : 1;

		if ( '' !== $cache_key && $page > 1 ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				self::$slots = Columns::normalize_slots( $cached );
				return self::$slots;
			}
		}

		self::$slots = self::slots_in_use();
		if ( '' !== $cache_key ) {
			set_transient( $cache_key, self::$slots, HOUR_IN_SECONDS );
		}
		return self::$slots;
	}

	/**
	 * Every slot that has a title or content on any product.
	 *
	 * @return int[]
	 */
	private static function slots_in_use(): array {
		global $wpdb;
		// One indexed DISTINCT over meta_key, run once per export (cached by the caller).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_bulktab_' ) . '%'
			)
		);
		return Columns::slots_from_meta_keys( (array) $keys );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Import
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Add our fields to the column mapping dropdown.
	 *
	 * @param array  $options Mapping options.
	 * @param string $item    Column header or its current mapping.
	 * @return array
	 */
	public static function mapping_options( $options, $item = '' ) {
		if ( ! is_array( $options ) ) {
			return $options;
		}
		$group = array();
		foreach ( Columns::mapping_slots( (string) $item ) as $slot ) {
			/* translators: %d: tab number */
			$group[ Columns::title_id( $slot ) ] = sprintf( __( 'Tab %d title', 'bulktab' ), $slot );
			/* translators: %d: tab number */
			$group[ Columns::content_id( $slot ) ] = sprintf( __( 'Tab %d content', 'bulktab' ), $slot );
		}
		$group[ Columns::ATTACHED ] = __( 'Attached shared tabs (keys)', 'bulktab' );
		$group[ Columns::HIDDEN ]   = __( 'Hidden tabs', 'bulktab' );
		$group[ Columns::ORDER ]    = __( 'Tab order', 'bulktab' );

		$options[ Columns::GROUP ] = array(
			'name'    => __( 'BulkTab product tabs', 'bulktab' ),
			'options' => $group,
		);
		return $options;
	}

	/**
	 * Auto-map the fixed headers.
	 *
	 * @param array $columns Lower-cased header => mapped key.
	 * @return array
	 */
	public static function mapping_default_columns( $columns ) {
		return is_array( $columns ) ? $columns + Columns::default_mapping() : $columns;
	}

	/**
	 * Auto-map the numbered headers.
	 *
	 * @param array $columns Header pattern => mapped key prefix.
	 * @return array
	 */
	public static function mapping_special_columns( $columns ) {
		return is_array( $columns ) ? $columns + Columns::special_mapping() : $columns;
	}

	/**
	 * Replace wc_clean() (which strips HTML) for our columns. Cells are only unescaped
	 * here; titles, content and lists are sanitized when the row is written, where the
	 * stored value is known (unchanged content is not re-filtered).
	 *
	 * @param array  $callbacks Column index => callback.
	 * @param object $importer  WC_Product_CSV_Importer.
	 * @return array
	 */
	public static function formatting_callbacks( $callbacks, $importer = null ) {
		if ( ! is_array( $callbacks ) || ! is_object( $importer ) || ! method_exists( $importer, 'get_mapped_keys' ) ) {
			return $callbacks;
		}
		// WooCommerce builds the callbacks list in mapped-key order, indexed from 0.
		foreach ( array_values( (array) $importer->get_mapped_keys() ) as $index => $key ) {
			if ( is_string( $key ) && Columns::is_ours( $key ) ) {
				$callbacks[ $index ] = array( Cells::class, 'unescape' );
			}
		}
		return $callbacks;
	}

	/**
	 * Start a fresh problem log when an import's first batch runs.
	 *
	 * @param array $args Importer arguments.
	 * @return array
	 */
	public static function maybe_reset_log( $args ) {
		if ( is_array( $args ) && ! empty( $args['parse'] ) && isset( $args['start_pos'] ) && 0 === (int) $args['start_pos'] ) {
			delete_transient( self::log_key() );
		}
		return $args;
	}

	/**
	 * Write one imported row's tab data.
	 *
	 * @param \WC_Product $product Saved product.
	 * @param array       $data    Parsed row (mapped key => value).
	 */
	public static function import_row( $product, $data ): void {
		if ( ! $product instanceof \WC_Product || ! is_array( $data ) || ! RowImporter::has_columns( $data ) ) {
			return;
		}
		$id = (int) $product->get_id();
		if ( $id <= 0 ) {
			return;
		}

		$messages = array();
		if ( $product->is_type( 'variation' ) ) {
			if ( self::has_values( $data ) ) {
				$messages[] = array(
					'level' => 'warning',
					'code'  => 'variation',
					'args'  => array(),
				);
			}
		} elseif ( ! current_user_can( 'edit_product', $id ) ) {
			$messages[] = array(
				'level' => 'error',
				'code'  => 'no_permission',
				'args'  => array(),
			);
		} else {
			$importer = new RowImporter( self::store(), 'sanitize_text_field', array( Content::class, 'sanitize_for_storage' ) );
			$result   = $importer->import( $id, $data, self::shared_keys() );
			$messages = $result['messages'];
		}

		self::log( $product, $messages );
	}

	/**
	 * Whether any of our cells in the row has a value (other than `[keep]`).
	 *
	 * @param array $data Parsed row.
	 * @return bool
	 */
	private static function has_values( array $data ): bool {
		foreach ( $data as $key => $value ) {
			if ( Columns::is_ours( (string) $key ) && is_scalar( $value ) && ! Cells::is_blank( (string) $value ) && ! Cells::is_keep( (string) $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keys of published shared tabs.
	 *
	 * @return string[]
	 */
	private static function shared_keys(): array {
		if ( null === self::$shared_keys ) {
			self::$shared_keys = array_map( 'strval', array_keys( RulesIndex::get() ) );
		}
		return self::$shared_keys;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Problem log
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Record a row's messages: every line to the WooCommerce log, the first few to the
	 * notice shown after the import.
	 *
	 * @param \WC_Product $product  Product.
	 * @param array       $messages RowImporter messages.
	 */
	private static function log( $product, array $messages ): void {
		if ( ! $messages ) {
			return;
		}
		$logger = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
		$key    = self::log_key();
		$log    = get_transient( $key );
		if ( ! is_array( $log ) || ! isset( $log['errors'], $log['warnings'], $log['lines'] ) ) {
			$log = array(
				'errors'   => 0,
				'warnings' => 0,
				'lines'    => array(),
			);
		}

		$label = '#' . $product->get_id();
		$sku   = (string) $product->get_sku( 'edit' );
		if ( '' !== $sku ) {
			/* translators: 1: product ID like #12, 2: SKU */
			$label = sprintf( __( '%1$s (SKU %2$s)', 'bulktab' ), $label, $sku );
		}

		foreach ( $messages as $message ) {
			$is_error = 'error' === ( $message['level'] ?? '' );
			/* translators: 1: product label, 2: problem description */
			$line = sprintf( __( 'Product %1$s: %2$s', 'bulktab' ), $label, self::describe( $message ) );
			if ( $logger ) {
				$logger->log( $is_error ? 'error' : 'warning', $line, array( 'source' => self::LOG_SOURCE ) );
			}
			++$log[ $is_error ? 'errors' : 'warnings' ];
			if ( count( $log['lines'] ) < self::NOTICE_LINES ) {
				$log['lines'][] = $line;
			}
		}
		set_transient( $key, $log, DAY_IN_SECONDS );
	}

	/**
	 * Human-readable text for a RowImporter message.
	 *
	 * @param array $message Message (level, code, args).
	 * @return string
	 */
	private static function describe( array $message ): string {
		$args = array_values( (array) ( $message['args'] ?? array() ) );
		$a0   = (string) ( $args[0] ?? '' );
		$a1   = (string) ( $args[1] ?? '' );

		switch ( $message['code'] ?? '' ) {
			case 'bad_slot':
				/* translators: 1: column header, 2: highest tab number */
				return sprintf( __( 'Column "%1$s" was ignored: tab numbers go from 1 to %2$d.', 'bulktab' ), $a0, TabStore::MAX_TABS );
			case 'bad_ref':
				/* translators: 1: column header, 2: value from the file */
				return sprintf( __( '"%2$s" in column "%1$s" is not a valid tab key and was skipped.', 'bulktab' ), $a0, $a1 );
			case 'unknown_shared':
				/* translators: 1: column header, 2: shared tab key */
				return sprintf( __( 'Column "%1$s": no published shared tab has the key "%2$s". It was kept and will apply once that shared tab is published.', 'bulktab' ), $a0, $a1 );
			case 'missing_slot_ref':
				/* translators: 1: column header, 2: tab reference such as c:3 */
				return sprintf( __( '"%2$s" in column "%1$s" refers to a tab this product does not have and was skipped.', 'bulktab' ), $a0, $a1 );
			case 'content_filtered':
				/* translators: %s: tab number */
				return sprintf( __( 'Tab %s content: some HTML was removed or changed because your account may not post unfiltered HTML.', 'bulktab' ), $a0 );
			case 'list_not_saved':
				/* translators: %s: column header */
				return sprintf( __( 'Column "%s" was not saved.', 'bulktab' ), $a0 );
			case 'variation':
				return __( 'BulkTab columns are ignored on variations; tabs belong to the parent product.', 'bulktab' );
			case 'no_permission':
				return __( 'Tabs were not imported because you are not allowed to edit this product.', 'bulktab' );
			default:
				return $a0; // 'store': TabStore's own error text.
		}
	}

	/**
	 * Show the problem log once, after the import.
	 */
	public static function render_log(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		$key = self::log_key();
		$log = get_transient( $key );
		if ( ! is_array( $log ) || empty( $log['lines'] ) ) {
			return;
		}
		delete_transient( $key );

		$total = (int) $log['errors'] + (int) $log['warnings'];
		$more  = max( 0, $total - count( $log['lines'] ) );
		echo '<div class="notice notice-' . esc_attr( $log['errors'] ? 'error' : 'warning' ) . ' is-dismissible"><p><strong>';
		echo esc_html(
			sprintf(
				/* translators: %d: number of problems */
				_n( 'Product tabs: the CSV import reported %d problem.', 'Product tabs: the CSV import reported %d problems.', $total, 'bulktab' ),
				$total
			)
		);
		echo '</strong></p><ul style="list-style:disc;margin-left:2em">';
		foreach ( (array) $log['lines'] as $line ) {
			echo '<li>' . esc_html( (string) $line ) . '</li>';
		}
		echo '</ul><p>';
		if ( $more ) {
			echo esc_html(
				sprintf(
					/* translators: 1: number of further problems, 2: log source name */
					_n( '%1$d more is listed in WooCommerce > Status > Logs (source "%2$s").', '%1$d more are listed in WooCommerce > Status > Logs (source "%2$s").', $more, 'bulktab' ),
					$more,
					self::LOG_SOURCE
				)
			);
		} else {
			echo esc_html(
				sprintf(
					/* translators: %s: log source name */
					__( 'The full list is in WooCommerce > Status > Logs (source "%s").', 'bulktab' ),
					self::LOG_SOURCE
				)
			);
		}
		echo '</p></div>';
	}

	/**
	 * Per-user transient key of the problem log.
	 *
	 * @return string
	 */
	private static function log_key(): string {
		return 'bulktab_csv_import_log_' . get_current_user_id();
	}

	/**
	 * Shared TabStore.
	 *
	 * @return TabStore
	 */
	private static function store(): TabStore {
		if ( null === self::$store ) {
			self::$store = new TabStore( new WpMetaStore() );
		}
		return self::$store;
	}
}
