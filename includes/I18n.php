<?php
/**
 * WPML / Polylang helpers. No-ops when neither is active.
 *
 * @package BulkTab
 */

namespace BulkTab;

defined( 'ABSPATH' ) || exit;

/**
 * Translation plugin helpers.
 */
final class I18n {

	/**
	 * Shared-tab post in the current language (falls back to the original).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Post type.
	 * @return int
	 */
	public static function current_post_id( int $post_id, string $type ): int {
		if ( has_filter( 'wpml_object_id' ) ) {
			return (int) apply_filters( 'wpml_object_id', $post_id, $type, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
		}
		if ( function_exists( 'pll_get_post' ) ) {
			$translated = pll_get_post( $post_id );
			return $translated ? (int) $translated : $post_id;
		}
		return $post_id;
	}

	/**
	 * Post in the default language (rules and indexes store original IDs).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Post type.
	 * @return int
	 */
	public static function original_post_id( int $post_id, string $type ): int {
		if ( has_filter( 'wpml_object_id' ) ) {
			$lang = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
			return (int) apply_filters( 'wpml_object_id', $post_id, $type, true, $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
		}
		if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_default_language' ) ) {
			$original = pll_get_post( $post_id, pll_default_language() );
			return $original ? (int) $original : $post_id;
		}
		return $post_id;
	}

	/**
	 * Term in the default language (rules store original term IDs).
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	public static function original_term_id( int $term_id, string $taxonomy ): int {
		if ( has_filter( 'wpml_object_id' ) ) {
			$lang = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
			return (int) apply_filters( 'wpml_object_id', $term_id, $taxonomy, true, $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
		}
		if ( function_exists( 'pll_get_term' ) && function_exists( 'pll_default_language' ) ) {
			$original = pll_get_term( $term_id, pll_default_language() );
			return $original ? (int) $original : $term_id;
		}
		return $term_id;
	}
}
