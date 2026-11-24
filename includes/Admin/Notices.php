<?php
/**
 * Save warnings (R-4: never fail silently). Messages are queued per user and
 * shown on the next admin page load (after the post-save redirect).
 *
 * @package BulkTab
 */

namespace BulkTab\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user queue of admin notices.
 */
final class Notices {

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	/**
	 * Queue a notice for the current user's next admin page.
	 *
	 * @param string $message Plain-text message.
	 * @param string $type    error|warning|success|info.
	 */
	public static function add( string $message, string $type = 'error' ): void {
		$key   = 'bulktab_notices_' . get_current_user_id();
		$queue = get_transient( $key );
		$queue = is_array( $queue ) ? $queue : array();

		$queue[] = array(
			'message' => $message,
			'type'    => $type,
		);
		set_transient( $key, $queue, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Print and clear the current user's queued notices.
	 */
	public static function render(): void {
		$key   = 'bulktab_notices_' . get_current_user_id();
		$queue = get_transient( $key );
		if ( ! is_array( $queue ) || ! $queue ) {
			return;
		}
		delete_transient( $key );
		foreach ( $queue as $n ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> %3$s</p></div>',
				esc_attr( in_array( $n['type'], array( 'error', 'warning', 'success', 'info' ), true ) ? $n['type'] : 'error' ),
				esc_html__( 'Product tabs:', 'bulktab' ),
				esc_html( $n['message'] )
			);
		}
	}
}
