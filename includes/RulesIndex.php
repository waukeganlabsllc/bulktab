<?php
/**
 * Compiled rules index (option `bulktab_rules_index`): every published shared
 * tab's key, post ID, order and parsed rules. No content. Product pages read
 * this one option instead of querying the post type.
 *
 * @package BulkTab
 */

namespace BulkTab;

defined( 'ABSPATH' ) || exit;

/**
 * Compiled index of shared tabs and their rules.
 */
final class RulesIndex {

	const OPTION = 'bulktab_rules_index';

	/**
	 * Rebuild the index whenever a shared tab is saved, trashed, restored or deleted.
	 */
	public static function register_hooks(): void {
		// Priority 20: after RulesMetabox saves rules (10).
		add_action( 'save_post_' . PostType::NAME, array( self::class, 'rebuild' ), 20 );
		add_action( 'deleted_post', array( self::class, 'maybe_rebuild' ) );
		add_action( 'trashed_post', array( self::class, 'maybe_rebuild' ) );
		add_action( 'untrashed_post', array( self::class, 'maybe_rebuild' ) );
	}

	/**
	 * Rebuild when the changed post is a shared tab.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function maybe_rebuild( $post_id ): void {
		if ( PostType::NAME === get_post_type( $post_id ) ) {
			self::rebuild();
		}
	}

	/**
	 * Rebuild the index from all published shared tabs.
	 */
	public static function rebuild(): void {
		$ids   = get_posts(
			array(
				'post_type'        => PostType::NAME,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'suppress_filters' => true, // Index all languages; WPML/Polylang resolve at render.
			)
		);
		$index = array();
		foreach ( $ids as $id ) {
			$key = (string) get_post_meta( $id, '_bulktab_key', true );
			if ( '' === $key || isset( $index[ $key ] ) ) {
				continue; // Translations share the key; keep the first (original).
			}
			$errors        = array();
			$index[ $key ] = array(
				'post_id'  => (int) $id,
				'priority' => 25 + (int) get_post_field( 'menu_order', $id ),
				'rules'    => Rules::parse( get_post_meta( $id, '_bulktab_rules', true ), $errors ),
			);
		}
		update_option( self::OPTION, $index, true );
	}

	/**
	 * The index (built on first use).
	 *
	 * @return array<string, array{post_id: int, priority: int, rules: array}>
	 */
	public static function get(): array {
		$index = get_option( self::OPTION, null );
		if ( ! is_array( $index ) ) {
			self::rebuild();
			$index = get_option( self::OPTION, array() );
		}
		return is_array( $index ) ? $index : array();
	}
}
