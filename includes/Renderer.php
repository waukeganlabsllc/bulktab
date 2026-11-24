<?php
/**
 * Front end: adds tabs to `woocommerce_product_tabs` (priority 20) and applies
 * layout last (priority 98, after most plugins have added theirs).
 *
 * Keys are stable: bulktab_s_{key} for shared tabs, bulktab_c_{n} for product tabs,
 * so theme CSS/JS and anchor links survive title edits.
 *
 * @package BulkTab
 */

namespace BulkTab;

defined( 'ABSPATH' ) || exit;

/**
 * Storefront tabs.
 */
final class Renderer {

	/**
	 * Tab storage.
	 *
	 * @var TabStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param MetaStore $meta Meta storage.
	 */
	public function __construct( MetaStore $meta ) {
		$this->store = new TabStore( $meta );
	}

	/**
	 * Register the `woocommerce_product_tabs` filters.
	 */
	public function register_hooks(): void {
		add_filter( 'woocommerce_product_tabs', array( $this, 'add_tabs' ), 20 );
		add_filter( 'woocommerce_product_tabs', array( $this, 'apply_layout' ), 98 );
	}

	/**
	 * Add the current product's BulkTab tabs (priority 20).
	 *
	 * @param array $tabs WooCommerce tabs.
	 * @return array
	 */
	public function add_tabs( $tabs ): array {
		$tabs    = is_array( $tabs ) ? $tabs : array();
		$product = $this->current_product();
		if ( ! $product ) {
			return $tabs;
		}
		return $tabs + $this->build_tabs( $product->get_id() );
	}

	/**
	 * All BulkTab tabs for a product, in WooCommerce tab format.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public function build_tabs( int $product_id ): array {
		$out      = array();
		$attached = $this->store->get_list( $product_id, '_bulktab_attach' );
		$hidden   = $this->store->get_list( $product_id, '_bulktab_hide' );
		$index    = RulesIndex::get();

		foreach ( TabMatcher::resolve( $index, ProductContext::for_product( $product_id ), $attached, $hidden ) as $key ) {
			$post_id = I18n::current_post_id( (int) $index[ $key ]['post_id'], PostType::NAME );
			$post    = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status || Content::is_empty( (string) $post->post_content ) ) {
				continue;
			}
			$out[ 'bulktab_s_' . $key ] = array(
				'title'           => $post->post_title,
				'priority'        => (int) $index[ $key ]['priority'],
				'callback'        => array( $this, 'panel' ),
				'bulktab_ref'     => 's:' . $key,
				'bulktab_content' => (string) $post->post_content,
				'bulktab_product' => $product_id,
			);
		}

		foreach ( $this->store->get_tabs( $product_id ) as $slot => $tab ) {
			if ( in_array( 'c:' . $slot, $hidden, true ) || Content::is_empty( $tab['content'] ) ) {
				continue;
			}
			$out[ 'bulktab_c_' . $slot ] = array(
				'title'           => '' !== $tab['title'] ? $tab['title'] : __( 'More info', 'bulktab' ),
				'priority'        => 40 + $slot,
				'callback'        => array( $this, 'panel' ),
				'bulktab_ref'     => 'c:' . $slot,
				'bulktab_content' => $tab['content'],
				'bulktab_product' => $product_id,
			);
		}

		/**
		 * Filter BulkTab tabs before they merge into WooCommerce's list.
		 *
		 * @param array $out        Tabs.
		 * @param int   $product_id Product ID.
		 */
		return (array) apply_filters( 'bulktab_product_tabs', $out, $product_id );
	}

	/**
	 * Apply the global layout, then the product's own hide / order (priority 98).
	 *
	 * @param array $tabs WooCommerce tabs.
	 * @return array
	 */
	public function apply_layout( $tabs ): array {
		$tabs    = is_array( $tabs ) ? $tabs : array();
		$product = $this->current_product();
		$global  = get_option( 'bulktab_layout', array() );
		$global  = is_array( $global ) ? $global : array();
		if ( ! $product ) {
			return Layout::apply( $tabs, $global, array(), array() );
		}
		$id = $product->get_id();
		return Layout::apply(
			$tabs,
			$global,
			$this->store->get_list( $id, '_bulktab_order' ),
			$this->store->get_list( $id, '_bulktab_hide' )
		);
	}

	/**
	 * Tab panel callback (WooCommerce passes key + tab array).
	 *
	 * @param string $key Tab key.
	 * @param array  $tab Tab array.
	 */
	public function panel( $key, $tab ): void {
		$title = isset( $tab['title'] ) ? (string) $tab['title'] : '';
		/** Show the tab title as a heading inside the panel (WooCommerce default style). */
		if ( apply_filters( 'bulktab_show_heading', true, $tab ) && '' !== $title ) {
			echo '<h2>' . esc_html( $title ) . '</h2>';
		}
		$html = Content::render(
			(string) ( $tab['bulktab_content'] ?? '' ),
			array(
				'ref'        => (string) ( $tab['bulktab_ref'] ?? '' ),
				'product_id' => (int) ( $tab['bulktab_product'] ?? 0 ),
			)
		);
		// Stored via kses (or by users with unfiltered_html) and rendered like post content.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Product of the current page or loop.
	 *
	 * @return \WC_Product|null
	 */
	private function current_product() {
		global $product;
		if ( $product instanceof \WC_Product ) {
			return $product;
		}
		$id = get_the_ID();
		if ( $id && 'product' === get_post_type( $id ) ) {
			$p = wc_get_product( $id );
			return $p instanceof \WC_Product ? $p : null;
		}
		return null;
	}
}
