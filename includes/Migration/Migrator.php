<?php
/**
 * YIKES "Custom Product Tabs for WooCommerce" import.
 *
 * - Dry run first (Path B): the job scans every product and builds a report
 *   without writing anything; "Run import" then repeats it with writes.
 * - Path A (this code ships as the YIKES slug): on the first admin / cron load
 *   after the upgrade, the YIKES options are backed up and the full import is
 *   queued automatically. Until a product is migrated, its YIKES tabs are
 *   rendered from the stored data, so tabs never vanish mid-job.
 * - Path B with YIKES still active: on migrated products the YIKES tabs are
 *   removed from the page, so each tab shows once.
 * - Batches of 50 products via Action Scheduler (bundled with WooCommerce);
 *   without it, the import page drives the batches from the browser.
 *
 * YIKES data (yikes_* options and meta) is only ever read.
 *
 * @package BulkTab
 */

namespace BulkTab\Migration;

use BulkTab\Admin\ImportPage;
use BulkTab\Content;
use BulkTab\PostType;
use BulkTab\Rules;
use BulkTab\RulesIndex;
use BulkTab\WpMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the YIKES import and coexistence hooks.
 */
final class Migrator {

	const JOB_OPTION    = 'bulktab_yikes_job';
	const AUTO_OPTION   = 'bulktab_yikes_auto';
	const BACKUP_OPTION = 'bulktab_yikes_backup';
	const LOCK          = 'bulktab_yikes_lock';
	const HOOK          = 'bulktab_yikes_batch';
	const GROUP         = 'bulktab-migrate';
	const SOURCE_META   = '_bulktab_source';
	const BATCH         = 50;
	const TAKEOVER_DIR  = 'yikes-inc-easy-custom-woocommerce-product-tabs';

	/**
	 * Hook everything up. Called from Plugin::boot().
	 */
	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'run_batch' ) );
		add_action( 'init', array( self::class, 'maybe_auto_migrate' ), 20 );
		add_filter( 'woocommerce_product_tabs', array( self::class, 'legacy_tabs' ), 21 );
		add_filter( 'woocommerce_product_tabs', array( self::class, 'drop_yikes_duplicates' ), 99 );
		if ( is_admin() ) {
			add_action( 'admin_notices', array( self::class, 'notices' ) );
			ImportPage::register_hooks();
		}
	}

	/**
	 * Is this plugin installed under the YIKES slug (Path A, takeover)?
	 *
	 * @return bool
	 */
	public static function is_takeover(): bool {
		return defined( 'BULKTAB_FILE' ) && self::TAKEOVER_DIR === dirname( plugin_basename( BULKTAB_FILE ) );
	}

	/**
	 * Current job state, or null.
	 *
	 * @return array|null
	 */
	public static function get_job(): ?array {
		$job = get_option( self::JOB_OPTION, null );
		return is_array( $job ) && isset( $job['id'], $job['status'] ) ? $job : null;
	}

	/**
	 * Is Action Scheduler available?
	 *
	 * @return bool
	 */
	public static function has_action_scheduler(): bool {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Start a dry run ('dry') or an import ('run').
	 *
	 * @param string $mode 'dry' or 'run'.
	 * @param bool   $auto Started automatically (Path A).
	 * @return array Job.
	 */
	public static function start( string $mode, bool $auto = false ): array {
		$mode = 'run' === $mode ? 'run' : 'dry';
		$dry  = 'dry' === $mode;
		self::cancel();
		YikesSource::flush();

		$report = new Report();
		foreach ( array( YikesData::OPTION_SAVED, YikesData::OPTION_APPLIED ) as $name ) {
			$decoded = YikesSource::option( $name );
			if ( Serialized::STATUS_REPAIRED === $decoded['status'] ) {
				$report->count( 'repaired' );
				$report->issue( 0, '', 'option_repaired', $name );
			} elseif ( Serialized::STATUS_UNREADABLE === $decoded['status'] ) {
				$report->issue( 0, '', 'option_unreadable', $name . ' (' . $decoded['error'] . ')' );
			}
		}

		$keys = self::prepare_shared( YikesSource::saved_tabs(), $dry, $report );
		if ( ! $dry ) {
			RulesIndex::rebuild(); // Products migrated by the first batches must already see the new keys.
		}

		$content_value = Mapper::use_the_content( get_option( YikesData::OPTION_CONTENT, null ) );
		if ( null === get_option( 'bulktab_use_the_content', null ) ) {
			$report->issue( 0, '', '1' === $content_value ? 'content_filter_on' : 'content_filter_off' );
			if ( ! $dry ) {
				update_option( 'bulktab_use_the_content', $content_value );
			}
		}

		$job = array(
			'id'       => wp_generate_password( 16, false ),
			'mode'     => $mode,
			'auto'     => $auto,
			'status'   => 'running',
			'total'    => YikesSource::count_products(),
			'done'     => 0,
			'last_id'  => 0,
			'keys'     => $keys,
			'report'   => $report->to_array(),
			'started'  => time(),
			'updated'  => time(),
			'finished' => 0,
		);
		update_option( self::JOB_OPTION, $job, false );
		self::schedule( $job['id'] );
		return $job;
	}

	/**
	 * Stop the current job (state is kept, status 'cancelled').
	 */
	public static function cancel(): void {
		$job = self::get_job();
		if ( $job && self::has_action_scheduler() ) {
			as_unschedule_all_actions( self::HOOK, array( $job['id'] ), self::GROUP );
		}
		if ( $job && 'running' === $job['status'] ) {
			$job['status']   = 'cancelled';
			$job['finished'] = time();
			update_option( self::JOB_OPTION, $job, false );
		}
	}

	/**
	 * Action Scheduler callback: one batch, then queue the next.
	 *
	 * @param string $job_id Job ID.
	 */
	public static function run_batch( $job_id ): void {
		$job = self::get_job();
		if ( ! $job || (string) $job_id !== $job['id'] || 'running' !== $job['status'] ) {
			return;
		}
		if ( ! self::lock() ) {
			// The import page is processing a batch right now; try again shortly.
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 30, self::HOOK, array( $job['id'] ), self::GROUP );
			}
			return;
		}
		$job = self::process_batch( $job );
		self::unlock();
		if ( 'running' === $job['status'] ) {
			self::schedule( $job['id'] );
		}
	}

	/**
	 * Process batches in this request (import page; also the fallback when
	 * Action Scheduler is missing or stalled).
	 *
	 * @param float $seconds Time budget.
	 */
	public static function drive( float $seconds ): void {
		$until = microtime( true ) + $seconds;
		do {
			$job = self::get_job();
			if ( ! $job || 'running' !== $job['status'] || ! self::lock() ) {
				return;
			}
			self::process_batch( $job );
			self::unlock();
		} while ( microtime( true ) < $until );
	}

	/**
	 * Path A: queue the full import once, on the first admin / cron / CLI
	 * load after the upgrade, if there is YIKES data.
	 */
	public static function maybe_auto_migrate(): void {
		if ( ! self::is_takeover() || false !== get_option( self::AUTO_OPTION, false ) ) {
			return;
		}
		if ( ! ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
			return;
		}
		if ( ! YikesSource::has_data() ) {
			add_option( self::AUTO_OPTION, 'none', '', true );
			return;
		}
		// add_option() fails if another request got here first.
		if ( ! add_option( self::AUTO_OPTION, 'queued', '', true ) ) {
			return;
		}
		self::backup();
		self::start( 'run', true );
	}

	/**
	 * Path A: add YIKES tabs, as YIKES built them, on products not migrated yet.
	 *
	 * @param mixed $tabs WooCommerce tabs.
	 * @return array
	 */
	public static function legacy_tabs( $tabs ): array {
		$tabs = is_array( $tabs ) ? $tabs : array();
		if ( ! self::is_takeover() ) {
			return $tabs;
		}
		$product_id = self::current_product_id();
		if ( ! $product_id || '' !== (string) get_post_meta( $product_id, ProductWriter::MARKER, true ) ) {
			return $tabs;
		}
		$legacy = Coexistence::legacy_tabs( self::product_yikes_tabs( $product_id ) );
		if ( ! $legacy ) {
			return $tabs;
		}
		$last = 25;
		foreach ( $legacy as $key => $tab ) {
			$tabs[ (string) $key ] = array(
				'title'    => $tab['title'],
				'priority' => $tab['priority'],
				'callback' => array( self::class, 'legacy_panel' ),
				'content'  => $tab['content'],
			);
			$last                  = $tab['priority'] + 1;
		}
		if ( isset( $tabs['reviews'] ) && ! isset( $legacy['reviews'] ) ) {
			$tabs['reviews']['priority'] = $last; // YIKES kept Reviews last.
		}
		return $tabs;
	}

	/**
	 * Panel callback for legacy_tabs().
	 *
	 * @param string $key Tab key.
	 * @param array  $tab Tab.
	 */
	public static function legacy_panel( $key, $tab ): void {
		$title = (string) ( $tab['title'] ?? '' );
		if ( '' !== $title ) {
			echo '<h2 class="yikes-custom-woo-tab-title">' . esc_html( $title ) . '</h2>';
		}
		// Content written by shop staff in YIKES; rendered like our own tab content.
		echo Content::render( (string) ( $tab['content'] ?? '' ), array( 'ref' => 'x:' . $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Path B: on migrated products, drop the tabs YIKES (still active) added.
	 *
	 * @param mixed $tabs WooCommerce tabs.
	 * @return array
	 */
	public static function drop_yikes_duplicates( $tabs ): array {
		$tabs = is_array( $tabs ) ? $tabs : array();
		if ( ! class_exists( Coexistence::YIKES_DISPLAY_CLASS, false ) ) {
			return $tabs;
		}
		$product_id = self::current_product_id();
		if ( ! $product_id || '' === (string) get_post_meta( $product_id, ProductWriter::MARKER, true ) ) {
			return $tabs;
		}
		foreach ( Coexistence::keys_to_drop( $tabs, self::product_yikes_tabs( $product_id ) ) as $key ) {
			unset( $tabs[ $key ] );
		}
		return $tabs;
	}

	/**
	 * Admin notices: Path A progress / result; Path B offer to import.
	 */
	public static function notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, ImportPage::SLUG ) ) {
			return;
		}
		$job = self::get_job();

		if ( self::is_takeover() ) {
			$auto = get_option( self::AUTO_OPTION, '' );
			if ( $job && ! empty( $job['auto'] ) && 'running' === $job['status'] ) {
				ImportPage::notice(
					'info',
					sprintf(
						/* translators: 1: products done, 2: total products */
						__( 'Custom Product Tabs is moving your tabs to its new storage format (%1$d of %2$d products). Your tabs keep showing while this runs, and the original data is kept as a backup.', 'bulktab' ),
						(int) $job['done'],
						(int) $job['total']
					),
					array( __( 'View progress', 'bulktab' ) => ImportPage::url() )
				);
			} elseif ( 'done' === $auto ) {
				ImportPage::notice(
					'success',
					__( 'Custom Product Tabs moved your tabs to its new storage format. The original data is kept as a backup.', 'bulktab' ),
					array(
						__( 'View report', 'bulktab' ) => ImportPage::url(),
						__( 'Dismiss', 'bulktab' )     => ImportPage::action_url( 'dismiss' ),
					)
				);
			}
			return;
		}

		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard', 'edit-product', 'edit-' . PostType::NAME ), true ) ) {
			return;
		}
		if ( ( $job && 'run' === $job['mode'] && 'done' === $job['status'] ) || get_user_meta( get_current_user_id(), ImportPage::DISMISS_META, true ) ) {
			return;
		}
		if ( ! YikesSource::has_data() ) {
			return;
		}
		ImportPage::notice(
			'info',
			__( 'BulkTab found tabs from Custom Product Tabs for WooCommerce. Preview the import first: the preview changes nothing, and the import never changes or deletes the original data.', 'bulktab' ),
			array(
				__( 'Preview import', 'bulktab' ) => ImportPage::url(),
				__( 'Dismiss', 'bulktab' )        => ImportPage::action_url( 'dismiss' ),
			)
		);
	}

	/**
	 * Path A: keep a raw copy of the YIKES options (autoload off), once.
	 */
	private static function backup(): void {
		if ( false !== get_option( self::BACKUP_OPTION, false ) ) {
			return;
		}
		add_option(
			self::BACKUP_OPTION,
			array(
				'time'                    => time(),
				YikesData::OPTION_SAVED   => YikesSource::raw_option( YikesData::OPTION_SAVED ),
				YikesData::OPTION_APPLIED => YikesSource::raw_option( YikesData::OPTION_APPLIED ),
				YikesData::OPTION_CONTENT => YikesSource::raw_option( YikesData::OPTION_CONTENT ),
			),
			'',
			false
		);
	}

	/**
	 * Create (or, in a dry run, plan) one shared tab per YIKES saved tab.
	 * Upserted by `_bulktab_source`, so a re-run reuses existing ones.
	 *
	 * @param array  $saved  YikesData::saved_tabs().
	 * @param bool   $dry    Plan only.
	 * @param Report $report Report.
	 * @return array<int,string> saved_id => key.
	 */
	private static function prepare_shared( array $saved, bool $dry, Report $report ): array {
		$existing = self::existing_shared();
		$keys     = array();
		$reserved = array();
		foreach ( $saved as $id => $tab ) {
			if ( $tab['has_rule'] ) {
				$report->count( 'shared_with_rules' );
				$report->issue( 0, '', 'rule_not_converted', $tab['title'] );
			}
			$source = 'yikes:saved:' . $id;
			if ( isset( $existing[ $source ] ) ) {
				$keys[ $id ] = $existing[ $source ];
				$report->count( 'shared_existing' );
				continue;
			}
			$report->count( 'shared_to_create' );
			if ( $dry ) {
				$key = PostType::unique_key( Mapper::key_source( $tab ), 0 );
				$i   = 2;
				$try = $key;
				while ( isset( $reserved[ $try ] ) ) {
					$try = $key . '-' . $i;
					++$i;
				}
				$reserved[ $try ] = true;
				$keys[ $id ]      = $try;
				continue;
			}
			$key = self::create_shared( $tab, $source );
			if ( '' === $key ) {
				$report->issue( 0, '', 'shared_create_failed', $tab['title'] );
				continue;
			}
			$keys[ $id ] = $key;
		}
		return $keys;
	}

	/**
	 * Shared tabs created by earlier imports (any status, including trash).
	 *
	 * @return array<string,string> source => key.
	 */
	private static function existing_shared(): array {
		$ids = get_posts(
			array(
				'post_type'        => PostType::NAME,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'meta_key'         => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin-only, small set.
				'suppress_filters' => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$source = (string) get_post_meta( $id, self::SOURCE_META, true );
			$key    = (string) get_post_meta( $id, '_bulktab_key', true );
			if ( 0 === strpos( $source, 'yikes:saved:' ) && '' !== $key && ! isset( $out[ $source ] ) ) {
				$out[ $source ] = $key;
			}
		}
		return $out;
	}

	/**
	 * Insert a shared tab (manual-only rules) for a YIKES saved tab.
	 *
	 * Content is stored exactly as YIKES stored it (kses would strip iframes,
	 * which YIKES kept), like product tab content.
	 *
	 * @param array  $tab    Saved tab.
	 * @param string $source Source marker.
	 * @return string Key, or '' on failure.
	 */
	private static function create_shared( array $tab, string $source ): string {
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => PostType::NAME,
					'post_status'  => 'publish',
					'post_title'   => $tab['title'],
					'post_content' => $tab['content'],
					'meta_input'   => array(
						'_bulktab_rules'  => wp_json_encode( Rules::empty_rules() ),
						self::SOURCE_META => $source,
					),
				)
			),
			true
		);
		if ( $kses ) {
			kses_init_filters();
		}
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return '';
		}
		// Replace the title-based key PostType::ensure_key() set on save: nothing references it yet.
		$key = PostType::unique_key( Mapper::key_source( $tab ), (int) $post_id );
		update_post_meta( $post_id, '_bulktab_key', $key );
		return $key;
	}

	/**
	 * Process one batch of products and store the job.
	 *
	 * @param array $job Job.
	 * @return array Updated job.
	 */
	private static function process_batch( array $job ): array {
		$dry     = 'dry' === $job['mode'];
		$saved   = YikesSource::saved_tabs();
		$applied = YikesSource::applied_map();
		$keys    = array_map( 'strval', (array) $job['keys'] );
		$report  = Report::from_array( $job['report'] );
		$writer  = new ProductWriter( new WpMetaStore() );
		$rows    = YikesSource::product_batch( (int) $job['last_id'], self::BATCH );

		foreach ( $rows as $row ) {
			self::process_product( $row, $saved, $applied[ $row['id'] ] ?? array(), $keys, $writer, $report, $dry );
			$job['last_id'] = $row['id'];
			++$job['done'];
		}
		$job['report']  = $report->to_array();
		$job['updated'] = time();

		if ( count( $rows ) < self::BATCH ) {
			$job['status']   = 'done';
			$job['finished'] = time();
			if ( ! $dry ) {
				RulesIndex::rebuild();
				if ( ! empty( $job['auto'] ) ) {
					update_option( self::AUTO_OPTION, 'done', true );
				}
			}
			YikesSource::flush();
		}

		// Do not overwrite a job that was cancelled or replaced meanwhile.
		$current = self::get_job();
		if ( $current && $current['id'] === $job['id'] && 'running' === $current['status'] ) {
			update_option( self::JOB_OPTION, $job, false );
		}
		return $job;
	}

	/**
	 * Read, map and (unless dry) write one product.
	 *
	 * @param array         $row     Row from YikesSource::product_batch().
	 * @param array         $saved   Saved tabs.
	 * @param array         $links   Applied map entry for the product.
	 * @param array         $keys    saved_id => key.
	 * @param ProductWriter $writer  Writer.
	 * @param Report        $report  Report.
	 * @param bool          $dry     Dry run.
	 */
	private static function process_product( array $row, array $saved, array $links, array $keys, ProductWriter $writer, Report $report, bool $dry ): void {
		$id    = (int) $row['id'];
		$title = (string) $row['title'];
		$report->count( 'products_scanned' );

		$decoded = Serialized::decode( $row['raw'] );
		$tabs    = Serialized::STATUS_UNREADABLE === $decoded['status'] ? null : YikesData::product_tabs( $decoded['value'] );
		if ( null === $tabs ) {
			$report->count( 'unreadable' );
			$report->issue( $id, $title, 'unreadable', ( '' !== $decoded['error'] ? $decoded['error'] : 'unexpected_shape' ) . ': ' . $row['raw'] );
			return;
		}
		if ( Serialized::STATUS_REPAIRED === $decoded['status'] ) {
			$report->count( 'repaired' );
			$report->issue( $id, $title, 'repaired' );
		}
		if ( ! $tabs ) {
			return;
		}
		$report->count( 'products_with_tabs' );

		$plan   = Mapper::map_product( $tabs, $links, $saved, $keys );
		$result = $writer->apply( $id, $plan, Mapper::source_hash( $row['raw'], $links, $saved, $keys ), time(), $dry );
		$report->add_product( $id, $title, $plan, $result );

		if ( ! $dry && ProductWriter::WRITTEN === $result['status'] ) {
			clean_post_cache( $id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}
		}
	}

	/**
	 * Queue the next batch with Action Scheduler (no-op without it: the
	 * import page drives the batches instead).
	 *
	 * @param string $job_id Job ID.
	 */
	private static function schedule( string $job_id ): void {
		if ( self::has_action_scheduler() ) {
			as_enqueue_async_action( self::HOOK, array( $job_id ), self::GROUP );
		}
	}

	/**
	 * Take the batch lock.
	 *
	 * @return bool False if another request holds it.
	 */
	private static function lock(): bool {
		if ( get_transient( self::LOCK ) ) {
			return false;
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Release the batch lock.
	 */
	private static function unlock(): void {
		delete_transient( self::LOCK );
	}

	/**
	 * A product's YIKES tabs, read the safe way.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private static function product_yikes_tabs( int $product_id ): array {
		$value = get_post_meta( $product_id, YikesData::META_TABS, true );
		if ( '' === $value || null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			$raw   = YikesSource::raw_meta( $product_id );
			$value = null === $raw ? null : Serialized::decode( $raw )['value'];
		}
		return YikesData::product_tabs( $value ) ?? array();
	}

	/**
	 * ID of the product being rendered, or 0.
	 *
	 * @return int
	 */
	private static function current_product_id(): int {
		global $product;
		if ( $product instanceof \WC_Product ) {
			return (int) $product->get_id();
		}
		$id = get_the_ID();
		return $id && 'product' === get_post_type( $id ) ? (int) $id : 0;
	}
}
