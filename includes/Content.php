<?php
/**
 * Tab content pipeline. Deliberately NOT `the_content`: page builders,
 * related-posts and social plugins hook that and duplicate themselves inside
 * tabs. Sites that need it can switch on the `bulktab_use_the_content` option.
 *
 * @package BulkTab
 */

namespace BulkTab;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and sanitizes tab content.
 */
final class Content {

	/**
	 * Render stored tab HTML for the storefront.
	 *
	 * @param string $html    Stored tab HTML.
	 * @param array  $context array( 'ref' => string, 'product_id' => int ).
	 * @return string
	 */
	public static function render( string $html, array $context = array() ): string {
		if ( get_option( 'bulktab_use_the_content' ) ) {
			$out = apply_filters( 'the_content', $html ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		} else {
			global $wp_embed;
			$out = $html;
			if ( function_exists( 'do_blocks' ) && has_blocks( $out ) ) {
				$out = do_blocks( $out );
			}
			if ( $wp_embed instanceof \WP_Embed ) {
				$out = $wp_embed->autoembed( $out );
			}
			$out = wptexturize( $out );
			$out = wpautop( $out );
			$out = shortcode_unautop( $out );
			$out = do_shortcode( $out );
			$out = wp_filter_content_tags( $out );
		}

		/**
		 * Filter rendered tab content.
		 *
		 * @param string $out     Rendered HTML.
		 * @param string $html    Raw stored HTML.
		 * @param array  $context array( 'ref' => 's:size-guide'|'c:1', 'product_id' => int ).
		 */
		return (string) apply_filters( 'bulktab_tab_content', $out, $html, $context );
	}

	/**
	 * HTML allowed in rendered tab content: everything allowed in post content,
	 * plus the embed elements that WordPress itself outputs (iframe, video, audio).
	 *
	 * @return array
	 */
	public static function allowed_html(): array {
		$allowed           = wp_kses_allowed_html( 'post' );
		$media             = array(
			'src'      => true,
			'width'    => true,
			'height'   => true,
			'class'    => true,
			'style'    => true,
			'title'    => true,
			'loading'  => true,
			'controls' => true,
			'preload'  => true,
			'poster'   => true,
			'loop'     => true,
			'muted'    => true,
			'autoplay' => true,
		);
		$allowed['iframe'] = $media + array(
			'frameborder'     => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'referrerpolicy'  => true,
			'sandbox'         => true,
			'name'            => true,
		);
		$allowed['video']  = $media + array( 'playsinline' => true );
		$allowed['audio']  = $media;
		$allowed['source'] = array(
			'src'  => true,
			'type' => true,
		);
		$allowed['track']  = array(
			'src'     => true,
			'kind'    => true,
			'srclang' => true,
			'label'   => true,
			'default' => true,
		);
		/**
		 * Filter the HTML allowed in rendered tab content.
		 *
		 * @param array $allowed Allowed tags and attributes (wp_kses format).
		 */
		return (array) apply_filters( 'bulktab_allowed_html', $allowed );
	}

	/**
	 * Empty = no visible text and no media/shortcode/block. Empty tabs are not shown.
	 *
	 * @param string $html Tab HTML.
	 * @return bool
	 */
	public static function is_empty( string $html ): bool {
		if ( '' === trim( $html ) ) {
			return true;
		}
		if ( preg_match( '/<(img|iframe|video|audio|embed|object|svg|table|form|input|button)\b|\[[a-z_\-]+|<!-- wp:|https?:\/\//i', $html ) ) {
			return false;
		}
		return '' === trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES ), " \t\n\r\0\x0B\xC2\xA0" );
	}

	/**
	 * Sanitize editor input for storage, respecting unfiltered_html.
	 *
	 * @param string $html Submitted HTML (unslashed).
	 * @return string
	 */
	public static function sanitize_for_storage( string $html ): string {
		return current_user_can( 'unfiltered_html' ) ? $html : wp_kses_post( $html );
	}
}
