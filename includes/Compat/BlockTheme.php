<?php
/**
 * Block-theme detection (spec 04, R-21).
 *
 * WooCommerce's `woocommerce/product-details` block has two modes:
 * - no inner blocks ("legacy"): it prints the classic tabs, so rename / hide /
 *   reorder of the default tabs all work;
 * - inner blocks with an accordion ("accordion"): WooCommerce drops the default
 *   tab callbacks and appends other tabs (ours) as accordion items, so the
 *   global layout of Description / Additional information / Reviews is ignored.
 * With inner blocks but no accordion ("no_accordion") our tabs are not shown.
 *
 * @package BulkTab
 */

namespace BulkTab\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Finds which Product Details mode the active block theme's single-product template uses.
 */
final class BlockTheme {

	const MODE_NONE         = 'none';
	const MODE_LEGACY       = 'legacy';
	const MODE_ACCORDION    = 'accordion';
	const MODE_NO_ACCORDION = 'no_accordion';

	const DETAILS_BLOCK = 'woocommerce/product-details';
	const ANCHOR_BLOCKS = array( 'core/accordion', 'woocommerce/accordion-group' );
	const CACHE_KEY     = 'bulktab_block_mode';

	/** Maximum depth of nested patterns / template parts that are followed. */
	const MAX_DEPTH = 5;

	/**
	 * Mode of the first Product Details block found in a parsed block tree.
	 *
	 * Pure: pattern and template-part references are followed only through $resolve.
	 *
	 * @param array         $blocks  Output of parse_blocks().
	 * @param callable|null $resolve Receives a `core/pattern` or `core/template-part` block,
	 *                               returns its parsed inner blocks (array) or null.
	 * @param int           $depth   Current reference depth (internal).
	 * @return string One of the MODE_* constants.
	 */
	public static function analyze( array $blocks, ?callable $resolve = null, int $depth = 0 ): string {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();

			if ( self::DETAILS_BLOCK === $name ) {
				if ( ! $inner ) {
					return self::MODE_LEGACY;
				}
				return self::has_anchor( $inner, $resolve, $depth ) ? self::MODE_ACCORDION : self::MODE_NO_ACCORDION;
			}

			$mode = self::analyze( array_merge( $inner, self::resolve( $block, $resolve, $depth ) ), $resolve, $depth + 1 );
			if ( self::MODE_NONE !== $mode ) {
				return $mode;
			}
		}
		return self::MODE_NONE;
	}

	/**
	 * Whether a block tree contains an accordion WooCommerce can add our tabs to.
	 *
	 * @param array         $blocks  Parsed blocks.
	 * @param callable|null $resolve See analyze().
	 * @param int           $depth   Current reference depth.
	 * @return bool
	 */
	private static function has_anchor( array $blocks, ?callable $resolve, int $depth ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( in_array( $block['blockName'] ?? '', self::ANCHOR_BLOCKS, true ) ) {
				return true;
			}
			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			if ( self::has_anchor( array_merge( $inner, self::resolve( $block, $resolve, $depth ) ), $resolve, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parsed content of a pattern / template-part reference, or an empty array.
	 *
	 * @param array         $block   Parsed block.
	 * @param callable|null $resolve Resolver.
	 * @param int           $depth   Current reference depth.
	 * @return array
	 */
	private static function resolve( array $block, ?callable $resolve, int $depth ): array {
		if ( null === $resolve || $depth >= self::MAX_DEPTH ) {
			return array();
		}
		if ( ! in_array( $block['blockName'] ?? '', array( 'core/pattern', 'core/template-part' ), true ) ) {
			return array();
		}
		$out = $resolve( $block );
		return is_array( $out ) ? $out : array();
	}

	/**
	 * Mode used by the active theme's single-product template (cached).
	 *
	 * @return string One of the MODE_* constants; MODE_NONE for classic themes.
	 */
	public static function detect(): string {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return self::MODE_NONE;
		}
		$key    = md5( get_stylesheet() . '|' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '' ) );
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['key'], $cached['mode'] ) && $cached['key'] === $key ) {
			return (string) $cached['mode'];
		}

		$mode    = self::MODE_NONE;
		$content = self::template_content( 'wp_template', 'single-product', get_stylesheet() );
		if ( '' !== $content ) {
			$mode = self::analyze( parse_blocks( $content ), array( self::class, 'resolve_reference' ) );
		}
		set_transient(
			self::CACHE_KEY,
			array(
				'key'  => $key,
				'mode' => $mode,
			),
			DAY_IN_SECONDS
		);
		return $mode;
	}

	/** Forget the cached mode (theme switched, template edited). */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Resolver for analyze(): pattern and template-part references.
	 *
	 * @param array $block Parsed `core/pattern` or `core/template-part` block.
	 * @return array Parsed blocks.
	 */
	public static function resolve_reference( array $block ): array {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$slug  = isset( $attrs['slug'] ) ? (string) $attrs['slug'] : '';
		if ( '' === $slug ) {
			return array();
		}
		if ( 'core/pattern' === $block['blockName'] ) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
			return is_array( $pattern ) && ! empty( $pattern['content'] ) ? parse_blocks( (string) $pattern['content'] ) : array();
		}
		$theme   = isset( $attrs['theme'] ) ? (string) $attrs['theme'] : get_stylesheet();
		$content = self::template_content( 'wp_template_part', $slug, $theme );
		return '' !== $content ? parse_blocks( $content ) : array();
	}

	/**
	 * Content of a template, trying the theme's (or user's customised) copy, then WooCommerce's.
	 *
	 * @param string $type  'wp_template' or 'wp_template_part'.
	 * @param string $slug  Template slug.
	 * @param string $theme Theme stylesheet.
	 * @return string
	 */
	private static function template_content( string $type, string $slug, string $theme ): string {
		foreach ( array( $theme . '//' . $slug, 'woocommerce//' . $slug ) as $id ) {
			$template = get_block_template( $id, $type );
			if ( $template && ! empty( $template->content ) ) {
				return (string) $template->content;
			}
		}
		return '';
	}

	/**
	 * Whether WooCommerce's block compatibility layer (which adds our tabs to the accordion) is off.
	 *
	 * @return bool
	 */
	public static function compat_layer_disabled(): bool {
		return (bool) apply_filters( 'woocommerce_disable_compatibility_layer', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
	}
}
