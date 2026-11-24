<?php
/**
 * Shared tabs: post type `bulktab_tab` (revisions on = history for free).
 * `_bulktab_key` is a fixed slug set once at creation; products and layouts
 * reference it ("s:size-guide"), so renaming the title never breaks links.
 *
 * @package BulkTab
 */

namespace BulkTab;

defined( 'ABSPATH' ) || exit;

final class PostType {

	const NAME = 'bulktab_tab';

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'save_post_' . self::NAME, array( self::class, 'ensure_key' ), 5, 2 );
	}

	public static function register(): void {
		register_post_type(
			self::NAME,
			array(
				'labels'          => array(
					'name'          => __( 'Shared tabs', 'bulktab' ),
					'singular_name' => __( 'Shared tab', 'bulktab' ),
					'add_new_item'  => __( 'Add shared tab', 'bulktab' ),
					'edit_item'     => __( 'Edit shared tab', 'bulktab' ),
					'menu_name'     => __( 'Shared tabs', 'bulktab' ),
					'not_found'     => __( 'No shared tabs yet.', 'bulktab' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=product',
				'show_in_rest'    => false, // Classic editor, matching the product screen.
				'supports'        => array( 'title', 'editor', 'revisions', 'page-attributes' ),
				'capability_type' => 'product',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);

		$auth = static function () {
			return current_user_can( 'edit_products' );
		};
		register_post_meta(
			self::NAME,
			'_bulktab_key',
			array(
				'type'          => 'string',
				'single'        => true,
				'auth_callback' => $auth,
			)
		);
		register_post_meta(
			self::NAME,
			'_bulktab_rules',
			array(
				'type'          => 'string',
				'single'        => true,
				'auth_callback' => $auth,
			)
		);
	}

	/**
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public static function ensure_key( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( '' !== (string) get_post_meta( $post_id, '_bulktab_key', true ) ) {
			return;
		}
		update_post_meta( $post_id, '_bulktab_key', self::unique_key( (string) $post->post_title, (int) $post_id ) );
	}

	public static function unique_key( string $title, int $post_id ): string {
		$base = sanitize_title( $title );
		$base = '' === $base ? 'tab-' . $post_id : substr( $base, 0, 60 );
		$key  = $base;
		$i    = 2;
		while ( self::key_in_use( $key, $post_id ) ) {
			$key = $base . '-' . $i++;
		}
		return $key;
	}

	private static function key_in_use( string $key, int $exclude_id ): bool {
		$found = get_posts(
			array(
				'post_type'      => self::NAME,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'post__not_in'   => array( $exclude_id ),
				'meta_key'       => '_bulktab_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'     => $key,         // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			)
		);
		return ! empty( $found );
	}
}
