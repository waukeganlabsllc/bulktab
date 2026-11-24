<?php
/**
 * Fallback for page builders / block themes that don't render the classic
 * tabs area:  [bulktab_tabs]  [bulktab_tab key="size-guide"]  [bulktab_tab slot="1"]
 * Optional id="123" for use outside a product page.
 *
 * @package BulkTab
 */

namespace BulkTab;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	/** @var int Recursion guard (a tab containing [bulktab_tabs]). */
	private static $depth = 0;

	public static function register_hooks(): void {
		add_shortcode( 'bulktab_tabs', array( self::class, 'tabs' ) );
		add_shortcode( 'bulktab_tab', array( self::class, 'tab' ) );
	}

	/** @param array|string $atts */
	public static function tabs( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'bulktab_tabs' );
		$id   = self::product_id( (int) $atts['id'] );
		if ( ! $id || self::$depth > 0 ) {
			return '';
		}
		++self::$depth;
		$renderer = new Renderer( new WpMetaStore() );
		$tabs     = $renderer->build_tabs( $id );
		uasort(
			$tabs,
			static function ( $a, $b ) {
				return (int) $a['priority'] <=> (int) $b['priority'];
			}
		);
		$html = '<div class="bulktab-stack">';
		foreach ( $tabs as $key => $tab ) {
			$html .= '<section class="bulktab-section bulktab-' . esc_attr( $key ) . '">';
			ob_start();
			$renderer->panel( $key, $tab );
			$html .= ob_get_clean() . '</section>';
		}
		--self::$depth;
		return $html . '</div>';
	}

	/** @param array|string $atts */
	public static function tab( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'key'  => '',
				'slot' => 0,
			),
			$atts,
			'bulktab_tab'
		);
		$id   = self::product_id( (int) $atts['id'] );
		if ( ! $id || self::$depth > 0 ) {
			return '';
		}
		$renderer = new Renderer( new WpMetaStore() );
		$tabs     = $renderer->build_tabs( $id );
		$want     = '' !== $atts['key'] ? 'bulktab_s_' . sanitize_key( $atts['key'] ) : 'bulktab_c_' . (int) $atts['slot'];
		if ( ! isset( $tabs[ $want ] ) ) {
			return '';
		}
		++self::$depth;
		ob_start();
		$renderer->panel( $want, $tabs[ $want ] );
		--self::$depth;
		return (string) ob_get_clean();
	}

	private static function product_id( int $id ): int {
		if ( $id > 0 ) {
			return 'product' === get_post_type( $id ) ? $id : 0;
		}
		global $product;
		if ( $product instanceof \WC_Product ) {
			return $product->get_id();
		}
		$current = get_the_ID();
		return ( $current && 'product' === get_post_type( $current ) ) ? (int) $current : 0;
	}
}
