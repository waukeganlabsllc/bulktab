<?php
/**
 * Theme / page-builder compatibility (spec 04).
 *
 * Front end: wraps the callback of every BulkTab tab so that rendering it
 * - restores the global $post / $product afterwards (a `[products]` shortcode in a
 *   tab otherwise leaks into the next tab),
 * - never renders the same tab inside itself (recursion guard),
 * - never runs `the_content` inside `the_content` (Elementor / Divi / WPBakery
 *   render the whole product layout inside it), and pauses Elementor's builder
 *   filter while `the_content` is used for tab content,
 * - prints a `data-bulktab-ref` marker for the "Check product page" diagnostics.
 * Nothing is enqueued on the front end.
 *
 * Admin: block-theme notice (R-21) and a Site Health test.
 *
 * @package BulkTab
 */

namespace BulkTab\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility hooks.
 */
final class Compat {

	const DISMISS_ACTION = 'bulktab_dismiss_notice';
	const DISMISSED_META = 'bulktab_dismissed_notices';

	/**
	 * "product_id|ref" of the tabs being rendered (recursion guard).
	 *
	 * @var string[]
	 */
	private static $rendering = array();

	/**
	 * Register hooks. Called from Plugin::boot().
	 */
	public static function register_hooks(): void {
		// After WooCommerce sorts the tabs (99), so we wrap what is finally rendered.
		add_filter( 'woocommerce_product_tabs', array( self::class, 'wrap_callbacks' ), 100 );

		add_action( 'switch_theme', array( BlockTheme::class, 'flush' ) );
		add_action( 'save_post_wp_template', array( BlockTheme::class, 'flush' ) );
		add_action( 'save_post_wp_template_part', array( BlockTheme::class, 'flush' ) );
		add_action( 'deleted_post', array( BlockTheme::class, 'flush' ) );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( self::class, 'block_theme_notice' ) );
			add_action( 'admin_post_' . self::DISMISS_ACTION, array( self::class, 'dismiss' ) );
			add_filter( 'site_status_tests', array( self::class, 'site_status_tests' ) );
		}
	}

	// Front end.

	/**
	 * Route BulkTab tab callbacks through self::panel().
	 *
	 * @param mixed $tabs WooCommerce tabs.
	 * @return mixed
	 */
	public static function wrap_callbacks( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}
		$wrapper = array( self::class, 'panel' );
		foreach ( $tabs as $key => $tab ) {
			if ( ! is_array( $tab ) || empty( $tab['bulktab_ref'] ) || ! isset( $tab['callback'] ) ) {
				continue;
			}
			if ( $wrapper === $tab['callback'] || ! is_callable( $tab['callback'] ) ) {
				continue;
			}
			$tabs[ $key ]['bulktab_callback'] = $tab['callback'];
			$tabs[ $key ]['callback']         = $wrapper;
		}
		return $tabs;
	}

	/**
	 * Tab callback wrapper (see class docblock).
	 *
	 * @param string|int $key WooCommerce tab key (numeric in the block accordion layer).
	 * @param array      $tab Tab array.
	 */
	public static function panel( $key, $tab ): void {
		$tab   = is_array( $tab ) ? $tab : array();
		$inner = $tab['bulktab_callback'] ?? null;
		if ( ! is_callable( $inner ) ) {
			return;
		}
		$ref   = (string) ( $tab['bulktab_ref'] ?? '' );
		$frame = (int) ( $tab['bulktab_product'] ?? 0 ) . '|' . $ref;
		if ( in_array( $frame, self::$rendering, true ) ) {
			return; // The tab's own content asked for the tab again.
		}

		global $post;
		$saved_post    = $post;
		$saved_product = $GLOBALS['product'] ?? null;
		$own_pipeline  = doing_filter( 'the_content' );
		$elementor     = self::pause_elementor();

		if ( $own_pipeline ) {
			add_filter( 'pre_option_bulktab_use_the_content', array( self::class, 'option_off' ) );
		}
		self::$rendering[] = $frame;
		try {
			printf( '<div class="bulktab-tab-content" data-bulktab-ref="%s">', esc_attr( $ref ) );
			call_user_func( $inner, $key, $tab );
			echo '</div>';
		} finally {
			array_pop( self::$rendering );
			if ( $own_pipeline ) {
				remove_filter( 'pre_option_bulktab_use_the_content', array( self::class, 'option_off' ) );
			}
			if ( $elementor ) {
				$elementor->add_content_filter();
			}
			if ( $post !== $saved_post ) {
				$post = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the product page's post.
				if ( $saved_post instanceof \WP_Post ) {
					setup_postdata( $saved_post );
				}
			}
			$GLOBALS['product'] = $saved_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restoring the product page's product.
		}
	}

	/**
	 * `pre_option_bulktab_use_the_content` while already inside `the_content`.
	 *
	 * @return int
	 */
	public static function option_off(): int {
		return 0;
	}

	/**
	 * When tab content goes through `the_content`, take Elementor's builder filter off
	 * so it does not replace the tab with the product's Elementor layout.
	 *
	 * @return object|null Elementor frontend to resume, or null when nothing was paused.
	 */
	private static function pause_elementor() {
		if ( ! get_option( 'bulktab_use_the_content' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}
		$plugin   = \Elementor\Plugin::$instance ?? null;
		$frontend = is_object( $plugin ) && isset( $plugin->frontend ) ? $plugin->frontend : null;
		if ( ! is_object( $frontend ) || ! method_exists( $frontend, 'remove_content_filter' ) || ! method_exists( $frontend, 'add_content_filter' ) ) {
			return null;
		}
		if ( false === has_filter( 'the_content', array( $frontend, 'apply_builder_in_content' ) ) ) {
			return null;
		}
		$frontend->remove_content_filter();
		return $frontend;
	}

	// Admin: block-theme notice (R-21).

	/**
	 * Notice id and message for the current theme, or null when all is well.
	 *
	 * @return array{id: string, type: string, message: string}|null
	 */
	public static function block_theme_issue(): ?array {
		$mode = BlockTheme::detect();
		if ( BlockTheme::MODE_ACCORDION !== $mode && BlockTheme::MODE_NO_ACCORDION !== $mode ) {
			return null;
		}
		if ( BlockTheme::compat_layer_disabled() ) {
			return array(
				'id'      => 'block_compat_off',
				'type'    => 'warning',
				'message' => __( 'Your theme shows product details as accordion sections, and WooCommerce\'s compatibility layer is turned off (filter "woocommerce_disable_compatibility_layer"), so BulkTab tabs are not shown on product pages. Remove that filter, or add a Shortcode block with [bulktab_tabs] to the Single Product template.', 'bulktab' ),
			);
		}
		if ( BlockTheme::MODE_NO_ACCORDION === $mode ) {
			return array(
				'id'      => 'block_no_accordion',
				'type'    => 'warning',
				'message' => __( 'The Product Details block in your Single Product template has no accordion, so WooCommerce cannot add BulkTab tabs to it. In Appearance > Editor > Templates > Single Product, add an Accordion inside Product Details, or add a Shortcode block with [bulktab_tabs].', 'bulktab' ),
			);
		}
		return array(
			'id'      => 'block_accordion',
			'type'    => 'info',
			'message' => __( 'Your theme shows product details as accordion sections (the Product Details block). Your custom and shared tabs still appear there as extra sections, in their own order. Renaming, hiding and reordering Description, Additional information and Reviews in BulkTab has no effect in this layout: edit those sections in Appearance > Editor > Templates > Single Product. To show BulkTab tabs somewhere else, use a Shortcode block with [bulktab_tabs].', 'bulktab' ),
		);
	}

	/**
	 * Print the block-theme notice on product and BulkTab screens.
	 */
	public static function block_theme_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::on_our_screen() ) {
			return;
		}
		$issue = self::block_theme_issue();
		if ( null === $issue || in_array( $issue['id'], self::dismissed(), true ) ) {
			return;
		}
		$dismiss = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DISMISS_ACTION,
					'notice' => $issue['id'],
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION . '_' . $issue['id']
		);
		printf(
			'<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s</p><p><a href="%4$s">%5$s</a></p></div>',
			esc_attr( $issue['type'] ),
			esc_html__( 'BulkTab:', 'bulktab' ),
			esc_html( $issue['message'] ),
			esc_url( $dismiss ),
			esc_html__( 'Dismiss this notice', 'bulktab' )
		);
	}

	/**
	 * Remember a dismissed notice for the current user.
	 */
	public static function dismiss(): void {
		$id = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		check_admin_referer( self::DISMISS_ACTION . '_' . $id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'bulktab' ), 403 );
		}
		if ( '' !== $id ) {
			$dismissed   = self::dismissed();
			$dismissed[] = $id;
			update_user_meta( get_current_user_id(), self::DISMISSED_META, array_values( array_unique( $dismissed ) ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Notice ids the current user dismissed.
	 *
	 * @return string[]
	 */
	private static function dismissed(): array {
		$value = get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
		return is_array( $value ) ? array_map( 'strval', $value ) : array();
	}

	/**
	 * Screens where the notice is useful: plugins, products, shared tabs, Tab settings.
	 *
	 * @return bool
	 */
	private static function on_our_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return in_array( $screen->id, array( 'plugins', 'edit-product', 'product', 'edit-bulktab_tab', 'bulktab_tab', 'product_page_bulktab-settings' ), true );
	}

	// Admin: Site Health.

	/**
	 * Register the Site Health test.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public static function site_status_tests( $tests ): array {
		$tests                                   = is_array( $tests ) ? $tests : array();
		$tests['direct']['bulktab_product_tabs'] = array(
			'label' => __( 'BulkTab product tabs are shown by the theme', 'bulktab' ),
			'test'  => array( self::class, 'site_status_test' ),
		);
		return $tests;
	}

	/**
	 * Site Health test: block-theme Product Details mode and WooCommerce's compatibility layer.
	 *
	 * @return array
	 */
	public static function site_status_test(): array {
		$issue  = self::block_theme_issue();
		$result = array(
			'label'       => __( 'BulkTab product tabs are shown by the theme', 'bulktab' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'BulkTab', 'bulktab' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Your theme shows WooCommerce product tabs, so BulkTab tabs and the tab layout work.', 'bulktab' ) . '</p>',
			'actions'     => '',
			'test'        => 'bulktab_product_tabs',
		);
		if ( null === $issue ) {
			return $result;
		}
		$result['description'] = '<p>' . esc_html( $issue['message'] ) . '</p>';
		if ( 'info' === $issue['type'] ) {
			$result['label'] = __( 'Some BulkTab layout settings do not apply to your block theme', 'bulktab' );
		} else {
			$result['status'] = 'recommended';
			$result['label']  = __( 'BulkTab tabs may not appear on product pages', 'bulktab' );
		}
		return $result;
	}
}
