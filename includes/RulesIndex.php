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
		// Index all languages; WPML/Polylang resolve the right translation at render.
		$ids   = I18n::all_language_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
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
	 * Callback for TabMatcher that lets other plugins decide whether a shared
	 * tab shows on a product, or null when nothing is hooked.
	 *
	 * @return callable|null
	 */
	public static function decider(): ?callable {
		if ( ! has_filter( 'bulktab_shared_tab_shows' ) ) {
			return null;
		}
		return static function ( string $key, array $entry, array $ctx, bool $attached ) {
			/**
			 * Decide whether a shared tab shows on a product.
			 *
			 * Return true or false to decide, or null to keep BulkTab's own decision
			 * (attached on the product, or matched by the tab's rule).
			 *
			 * @param bool|null $shows    Decision, null by default.
			 * @param string    $key      Shared-tab key.
			 * @param array     $entry    Index entry: post_id, priority, rules.
			 * @param array     $ctx      Product context: id and term IDs per taxonomy.
			 * @param bool      $attached Whether the tab is attached on this product.
			 */
			return apply_filters( 'bulktab_shared_tab_shows', null, $key, $entry, $ctx, $attached );
		};
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
