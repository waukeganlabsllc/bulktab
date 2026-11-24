<?php
/**
 * Products > Import from Custom Product Tabs: dry run, report, import, progress.
 *
 * All actions need manage_woocommerce and a nonce. When Action Scheduler is
 * missing (or stalls), this page processes batches itself and reloads.
 *
 * @package BulkTab
 */

namespace BulkTab\Admin;

use BulkTab\Migration\Migrator;
use BulkTab\Migration\Report;
use BulkTab\Migration\YikesSource;

defined( 'ABSPATH' ) || exit;

/**
 * The YIKES import screen.
 */
final class ImportPage {

	const SLUG         = 'bulktab-import';
	const ACTION       = 'bulktab_yikes';
	const DISMISS_META = 'bulktab_yikes_notice_dismissed';
	const CAP          = 'manage_woocommerce';

	/** Seconds of batch processing per page load when the browser drives the job. */
	const DRIVE_SECONDS = 10;

	/** A running job with no progress for this long is treated as stalled. */
	const STALLED_AFTER = 180;

	/**
	 * Hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ), 60 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Register the page under Products.
	 */
	public static function add_page(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=product',
			__( 'Import from Custom Product Tabs', 'bulktab' ),
			__( 'Import tabs', 'bulktab' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'load' ) );
		}
	}

	/**
	 * Page URL.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'edit.php?post_type=product&page=' . self::SLUG );
	}

	/**
	 * Nonced admin-post URL for a GET action (dismiss).
	 *
	 * @param string $action Action.
	 * @return string
	 */
	public static function action_url( string $action ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&do=' . rawurlencode( $action ) ), self::ACTION . '_' . $action );
	}

	/**
	 * Print an admin notice with links.
	 *
	 * @param string               $type    info|success|warning|error.
	 * @param string               $message Message (plain text).
	 * @param array<string,string> $links   Label => URL.
	 */
	public static function notice( string $type, string $message, array $links = array() ): void {
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message );
		foreach ( $links as $label => $url ) {
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Before output: remember the visit (hides the Path B notice) and, when
	 * the browser drives the job, process batches.
	 */
	public static function load(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		if ( isset( $_GET['bulktab_drive'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::ACTION . '_drive' ) ) {
			Migrator::drive( self::DRIVE_SECONDS );
		}
	}

	/**
	 * Handle a posted action, then redirect back.
	 */
	public static function handle(): void {
		$action = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		if ( ! in_array( $action, array( 'dry', 'run', 'cancel', 'dismiss' ), true ) ) {
			wp_die( esc_html__( 'Unknown action.', 'bulktab' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( self::ACTION . '_' . $action );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to import product tabs.', 'bulktab' ), '', array( 'response' => 403 ) );
		}

		$redirect = self::url();
		switch ( $action ) {
			case 'dry':
				Migrator::start( 'dry' );
				break;
			case 'run':
				$job = Migrator::get_job();
				if ( $job && 'dry' === $job['mode'] && 'done' === $job['status'] ) {
					Migrator::start( 'run' );
				}
				break;
			case 'cancel':
				Migrator::cancel();
				break;
			case 'dismiss':
				update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
				if ( Migrator::is_takeover() && 'done' === get_option( Migrator::AUTO_OPTION ) ) {
					update_option( Migrator::AUTO_OPTION, 'done-seen', true );
				}
				$redirect = wp_get_referer() ? wp_get_referer() : admin_url();
				break;
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$job = Migrator::get_job();

		echo '<div class="wrap"><h1>' . esc_html__( 'Import from Custom Product Tabs', 'bulktab' ) . '</h1>';
		echo '<p>' . esc_html__( 'Copies the tabs made with "Custom Product Tabs for WooCommerce" (YIKES) into BulkTab. Saved tabs become shared tabs, attached to the products that used them; other tabs become the products\' own tabs, in the same order. The original data is never changed or deleted, and running the import again does not duplicate anything.', 'bulktab' ) . '</p>';

		if ( ! $job && ! YikesSource::has_data() ) {
			echo '<p><strong>' . esc_html__( 'No Custom Product Tabs data was found on this site.', 'bulktab' ) . '</strong></p></div>';
			return;
		}

		if ( $job && 'running' === $job['status'] ) {
			self::render_progress( $job );
		} elseif ( $job && 'done' === $job['status'] ) {
			if ( 'dry' === $job['mode'] ) {
				echo '<h2>' . esc_html__( 'Preview: what the import would do', 'bulktab' ) . '</h2>';
				echo '<p>' . esc_html__( 'Nothing has been changed yet.', 'bulktab' ) . '</p>';
			} else {
				echo '<h2>' . esc_html__( 'Import finished', 'bulktab' ) . '</h2>';
			}
			self::render_report( $job );
			if ( 'dry' === $job['mode'] ) {
				self::button( 'run', __( 'Run import', 'bulktab' ), true );
			}
			self::button( 'dry', __( 'Scan again (changes nothing)', 'bulktab' ), false );
		} else {
			if ( $job && 'cancelled' === $job['status'] ) {
				echo '<p>' . esc_html__( 'The last scan or import was stopped. Products already imported keep their tabs; a new run skips them.', 'bulktab' ) . '</p>';
			}
			self::button( 'dry', __( 'Preview import (changes nothing)', 'bulktab' ), true );
		}
		echo '</div>';
	}

	/**
	 * Progress view, with automatic reload.
	 *
	 * @param array $job Job.
	 */
	private static function render_progress( array $job ): void {
		$total   = max( 1, (int) $job['total'] );
		$done    = min( (int) $job['done'], $total );
		$browser = ! Migrator::has_action_scheduler() || ( time() - (int) $job['updated'] ) > self::STALLED_AFTER;
		$drive   = wp_nonce_url( add_query_arg( 'bulktab_drive', 1, self::url() ), self::ACTION . '_drive' );

		echo '<h2>' . esc_html( 'dry' === $job['mode'] ? __( 'Scanning products...', 'bulktab' ) : __( 'Importing...', 'bulktab' ) ) . '</h2>';
		echo '<p><progress max="' . esc_attr( (string) $total ) . '" value="' . esc_attr( (string) $done ) . '" style="width:100%;max-width:480px"></progress></p>';
		echo '<p role="status">' . esc_html(
			sprintf(
				/* translators: 1: products processed, 2: total products */
				__( '%1$d of %2$d products processed.', 'bulktab' ),
				$done,
				(int) $job['total']
			)
		) . '</p>';
		if ( $browser ) {
			echo '<p>' . esc_html__( 'Processing in this browser window: keep this page open until it finishes.', 'bulktab' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Running in the background. You can leave this page.', 'bulktab' ) . '</p>';
		}
		echo '<meta http-equiv="refresh" content="' . esc_attr( $browser ? '1' : '5' ) . ';url=' . esc_url( $browser ? $drive : self::url() ) . '">';
		echo '<p><a class="button" href="' . esc_url( $drive ) . '">' . esc_html__( 'Process next batch now', 'bulktab' ) . '</a></p>';
		self::button( 'cancel', __( 'Stop', 'bulktab' ), false );
	}

	/**
	 * Report: counters, then issues.
	 *
	 * @param array $job Job.
	 */
	private static function render_report( array $job ): void {
		$report = Report::from_array( $job['report'] );
		$dry    = 'dry' === $job['mode'];
		$rows   = array(
			'products_scanned'   => __( 'Products scanned', 'bulktab' ),
			'products_with_tabs' => __( 'Products with tabs', 'bulktab' ),
			'products_written'   => $dry ? __( 'Products that would be imported', 'bulktab' ) : __( 'Products imported', 'bulktab' ),
			'products_unchanged' => __( 'Products skipped: already imported, unchanged', 'bulktab' ),
			'products_conflict'  => __( 'Products skipped: edited in BulkTab after the last import', 'bulktab' ),
			'products_error'     => __( 'Products not saved (errors)', 'bulktab' ),
			'tabs_found'         => __( 'Tabs found', 'bulktab' ),
			'shared_refs'        => __( 'Tabs linked to a shared tab (not copied)', 'bulktab' ),
			'drifted'            => __( 'Saved-tab copies that differ from the saved tab (kept as the product\'s own tab)', 'bulktab' ),
			'custom_tabs'        => __( 'Product tabs', 'bulktab' ),
			'hidden_tabs'        => __( 'Tabs imported hidden (not shown before either)', 'bulktab' ),
			'skipped_empty'      => __( 'Empty tabs skipped', 'bulktab' ),
			'overflow'           => __( 'Tabs over the 20-per-product limit (not imported)', 'bulktab' ),
			'repaired'           => __( 'Damaged entries repaired', 'bulktab' ),
			'unreadable'         => __( 'Unreadable entries skipped', 'bulktab' ),
			'shared_to_create'   => $dry ? __( 'Shared tabs to create', 'bulktab' ) : __( 'Shared tabs created', 'bulktab' ),
			'shared_existing'    => __( 'Shared tabs already imported', 'bulktab' ),
		);
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		foreach ( $rows as $key => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( number_format_i18n( $report->get( $key ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$issues = $report->issues();
		if ( ! $issues ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Details', 'bulktab' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'bulktab' ) . '</th><th>' . esc_html__( 'What happened', 'bulktab' ) . '</th><th>' . esc_html__( 'Detail', 'bulktab' ) . '</th></tr></thead><tbody>';
		foreach ( $issues as $issue ) {
			$product = '&mdash;';
			if ( $issue['product_id'] ) {
				$label   = '' !== $issue['title'] ? $issue['title'] : '#' . $issue['product_id'];
				$link    = get_edit_post_link( (int) $issue['product_id'] );
				$product = $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
			}
			echo '<tr><td>' . $product . '</td><td>' . esc_html( self::issue_label( $issue['code'] ) ) . '</td><td><code>' . esc_html( $issue['detail'] ) . '</code></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $product is escaped above.
		}
		echo '</tbody></table>';
		if ( $report->dropped() ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of details not listed */
					_n( '%d more detail not listed.', '%d more details not listed.', $report->dropped(), 'bulktab' ),
					$report->dropped()
				)
			) . '</p>';
		}
	}

	/**
	 * Human text for an issue code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function issue_label( string $code ): string {
		$labels = array(
			'unreadable'           => __( 'Tabs could not be read and were skipped (the original data is untouched).', 'bulktab' ),
			'repaired'             => __( 'Damaged data was repaired. Please check this product\'s tabs.', 'bulktab' ),
			'drifted'              => __( 'This copy of a saved tab differs from the saved tab, so it was kept as the product\'s own tab; the shared tab is attached but hidden here.', 'bulktab' ),
			'duplicate_hidden'     => __( 'Two tabs had the same title and only the last one was shown; the other was imported hidden.', 'bulktab' ),
			'empty_title_hidden'   => __( 'A tab without a title was not shown before; it was imported hidden.', 'bulktab' ),
			'replaced_builtin'     => __( 'A tab replaced a WooCommerce tab with the same name; that WooCommerce tab is hidden on this product.', 'bulktab' ),
			'saved_tab_missing'    => __( 'Linked to a saved tab that no longer exists; kept as the product\'s own tab.', 'bulktab' ),
			'applied_without_copy' => __( 'Listed as using a saved tab, but the tab was not on the product; not attached.', 'bulktab' ),
			'overflow'             => __( 'Not imported: the product already has 20 tabs.', 'bulktab' ),
			'conflict'             => __( 'Changed in Custom Product Tabs and edited in BulkTab since the last import; not overwritten.', 'bulktab' ),
			'write_error'          => __( 'Could not be saved; it will be retried next time.', 'bulktab' ),
			'option_repaired'      => __( 'Damaged saved-tab settings were repaired.', 'bulktab' ),
			'option_unreadable'    => __( 'Saved-tab settings could not be read; their tabs are imported as product tabs.', 'bulktab' ),
			'rule_not_converted'   => __( 'This saved tab was applied by category or to all products; it is imported as a shared tab attached to the same products. Add a rule to it if you want.', 'bulktab' ),
			'shared_create_failed' => __( 'The shared tab could not be created.', 'bulktab' ),
			'content_filter_on'    => __( 'Tab content will run through the_content filter, as it did before.', 'bulktab' ),
			'content_filter_off'   => __( 'Tab content will not run through the_content filter, as before.', 'bulktab' ),
		);
		return $labels[ $code ] ?? $code;
	}

	/**
	 * A one-button form posting an action.
	 *
	 * @param string $action  Action.
	 * @param string $label   Button label.
	 * @param bool   $primary Primary button.
	 */
	private static function button( string $action, string $label, bool $primary ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:1em 1em 0 0">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( self::ACTION . '_' . $action );
		submit_button( $label, $primary ? 'primary' : 'secondary', 'submit', false );
		echo '</form>';
	}
}
