<?php
/**
 * R-4: tab data BulkTab can't read is never dropped silently.
 *
 * Finds product tabs whose stored value isn't plain text (e.g. an array
 * written by another tool or a bad import) and shared tabs whose rules can't
 * be parsed. Raw data is never modified here; the admin gets a warning that
 * names each product / shared tab until it's fixed. This is an error report,
 * not a nag: it disappears on its own once the data is readable again.
 *
 * Runs: daily (WP-Cron), after a product or shared tab is saved, and on demand.
 *
 * @package BulkTab
 */

namespace BulkTab\Admin;

use BulkTab\PostType;
use BulkTab\Rules;
use BulkTab\TabStore;
use BulkTab\WpMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * Data health scan and admin warnings.
 */
final class DataHealth {

	const OPTION = 'bulktab_health';
	const CRON   = 'bulktab_health_scan';
	const ACTION = 'bulktab_health_rescan';

	/** Max products listed by name in the notice. */
	const LIST_LIMIT = 10;

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( self::CRON, array( self::class, 'scan' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
		add_action( 'admin_notices', array( self::class, 'render_notices' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_rescan' ) );
		add_action( 'woocommerce_process_product_meta', array( self::class, 'recheck_product' ), 99 );
		add_action( 'save_post_' . PostType::NAME, array( self::class, 'recheck_shared_tab' ), 99 );
	}

	/**
	 * Schedule the daily scan once.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Full scan. Cheap: one indexed meta query for products, one parse per shared tab.
	 */
	public static function scan(): void {
		$report = array(
			'checked'  => time(),
			'products' => self::find_unreadable_products(),
			'tabs'     => self::find_unreadable_rules(),
		);
		update_option( self::OPTION, $report, false );
	}

	/**
	 * Product IDs => unreadable slots.
	 *
	 * @return array<int, int[]>
	 */
	public static function find_unreadable_products(): array {
		global $wpdb;
		// Values that unserialize to non-scalars start with these markers.
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- health scan, runs daily.
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE meta_key LIKE %s
				AND ( meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value = 'N;' )",
				$wpdb->esc_like( '_bulktab_' ) . '%',
				'a:%',
				'O:%',
				'C:%'
			)
		);
		$found = array();
		foreach ( (array) $rows as $row ) {
			if ( ! preg_match( '/^_bulktab_(\d+)_(title|content)$/', $row->meta_key, $m ) ) {
				continue;
			}
			if ( is_scalar( maybe_unserialize( $row->meta_value ) ) ) {
				continue;
			}
			$found[ (int) $row->post_id ][] = (int) $m[1];
		}
		foreach ( $found as $id => $slots ) {
			$found[ $id ] = array_values( array_unique( $slots ) );
			sort( $found[ $id ] );
		}
		return $found;
	}

	/**
	 * Shared tab post IDs => rule parse error codes.
	 *
	 * @return array<int, string[]>
	 */
	public static function find_unreadable_rules(): array {
		$ids   = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'lang'           => '', // Polylang: all languages.
			)
		);
		$found = array();
		foreach ( $ids as $id ) {
			$errors = self::rule_errors( (int) $id );
			if ( $errors ) {
				$found[ (int) $id ] = $errors;
			}
		}
		return $found;
	}

	/**
	 * Parse errors for one shared tab's stored rules.
	 *
	 * @param int $post_id Shared tab ID.
	 * @return string[]
	 */
	private static function rule_errors( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_bulktab_rules', true );
		if ( '' === $raw || null === $raw ) {
			return array();
		}
		if ( ! is_string( $raw ) ) {
			return array( 'invalid_type' );
		}
		$errors = array();
		Rules::parse( $raw, $errors );
		return array_values( array_unique( $errors ) );
	}

	/**
	 * Re-check one product after it is saved.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function recheck_product( $product_id ): void {
		$product_id = (int) $product_id;
		$report     = self::report();
		$slots      = ( new TabStore( new WpMetaStore() ) )->unreadable_slots( $product_id );
		if ( $slots ) {
			$report['products'][ $product_id ] = $slots;
		} else {
			unset( $report['products'][ $product_id ] );
		}
		update_option( self::OPTION, $report, false );
	}

	/**
	 * Re-check one shared tab after it is saved.
	 *
	 * @param int $post_id Shared tab ID.
	 */
	public static function recheck_shared_tab( $post_id ): void {
		$post_id = (int) $post_id;
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		$report = self::report();
		$errors = self::rule_errors( $post_id );
		if ( $errors ) {
			$report['tabs'][ $post_id ] = $errors;
		} else {
			unset( $report['tabs'][ $post_id ] );
		}
		update_option( self::OPTION, $report, false );
	}

	/**
	 * Stored report, normalised.
	 *
	 * @return array{checked:int,products:array,tabs:array}
	 */
	public static function report(): array {
		$report = get_option( self::OPTION, array() );
		$report = is_array( $report ) ? $report : array();
		return array(
			'checked'  => (int) ( $report['checked'] ?? 0 ),
			'products' => is_array( $report['products'] ?? null ) ? $report['products'] : array(),
			'tabs'     => is_array( $report['tabs'] ?? null ) ? $report['tabs'] : array(),
		);
	}

	/**
	 * "Check again" handler.
	 */
	public static function handle_rescan(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bulktab' ) );
		}
		check_admin_referer( self::ACTION );
		self::scan();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=product' ) );
		exit;
	}

	/**
	 * Show warnings on product and shared-tab screens and the dashboard.
	 */
	public static function render_notices(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( ! in_array( $screen->post_type, array( 'product', PostType::NAME ), true ) && 'dashboard' !== $screen->id ) ) {
			return;
		}
		$report = self::report();

		// On a single product's edit screen, be specific and live.
		if ( 'product' === $screen->post_type && 'post' === $screen->base ) {
			$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			if ( $id ) {
				$slots = ( new TabStore( new WpMetaStore() ) )->unreadable_slots( $id );
				if ( $slots ) {
					printf(
						'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
						esc_html__( 'BulkTab:', 'bulktab' ),
						esc_html(
							sprintf(
								/* translators: %s: comma-separated tab numbers */
								__( 'Tab %s on this product holds data BulkTab can\'t read, so it isn\'t shown. The data is kept unchanged. To replace it, delete that tab in the Tabs panel, or restore it from a backup.', 'bulktab' ),
								implode( ', ', $slots )
							)
						)
					);
				}
			}
			return;
		}

		if ( ! $report['products'] && ! $report['tabs'] ) {
			return;
		}

		$items = array();
		foreach ( array_slice( array_keys( $report['products'] ), 0, self::LIST_LIMIT ) as $id ) {
			$items[] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $id ) ), esc_html( get_the_title( $id ) ? get_the_title( $id ) : '#' . $id ) );
		}
		$more = count( $report['products'] ) - count( $items );

		$tabs = array();
		foreach ( array_keys( $report['tabs'] ) as $id ) {
			$tabs[] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $id ) ), esc_html( get_the_title( $id ) ? get_the_title( $id ) : '#' . $id ) );
		}

		$rescan = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'BulkTab found tab data it can\'t read.', 'bulktab' ) . '</strong> ' . esc_html__( 'Nothing was deleted; the data is kept unchanged.', 'bulktab' ) . '</p>';
		if ( $items ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of products */
					_n( '%d product has a tab that isn\'t shown:', '%d products have tabs that aren\'t shown:', count( $report['products'] ), 'bulktab' ),
					count( $report['products'] )
				)
			) . ' ' . wp_kses_post( implode( ', ', $items ) );
			if ( $more > 0 ) {
				/* translators: %d: number of further products */
				echo ' ' . esc_html( sprintf( __( 'and %d more', 'bulktab' ), $more ) );
			}
			echo '</p>';
		}
		if ( $tabs ) {
			echo '<p>' . esc_html__( 'These shared tabs have rules that can\'t be read, so they show on no products until you re-save their rules:', 'bulktab' ) . ' ' . wp_kses_post( implode( ', ', $tabs ) ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( $rescan ) . '">' . esc_html__( 'Check again', 'bulktab' ) . '</a></p></div>';
	}
}
