<?php
/**
 * "Check product page" diagnostics (spec 04): runs `woocommerce_product_tabs`
 * for a sample product in the admin, then fetches the product page and looks
 * for the marker Compat::panel() prints around each BulkTab tab.
 *
 * @package BulkTab
 */

namespace BulkTab\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Tab scan and storefront check for one product.
 */
final class Diagnostics {

	/**
	 * Refs whose marker is not in the page HTML.
	 *
	 * A tab counts as found when Compat::panel()'s `data-bulktab-ref` marker is
	 * present, or the `bulktab-{key}` class the [bulktab_tabs] shortcode prints.
	 *
	 * @param string                $html Page HTML.
	 * @param array<string, string> $refs Ref => front-end tab key (e.g. 's:faq' => 'bulktab_s_faq').
	 * @return string[] Missing refs, in input order.
	 */
	public static function missing_refs( string $html, array $refs ): array {
		$missing = array();
		foreach ( $refs as $ref => $key ) {
			$ref    = (string) $ref;
			$marker = 'data-bulktab-ref="' . htmlspecialchars( $ref, ENT_QUOTES ) . '"';
			if ( false !== strpos( $html, $marker ) ) {
				continue;
			}
			if ( '' !== (string) $key && preg_match( '/class="[^"]*\bbulktab-' . preg_quote( (string) $key, '/' ) . '(?![\w-])/', $html ) ) {
				continue;
			}
			$missing[] = $ref;
		}
		return $missing;
	}

	/**
	 * Run `woocommerce_product_tabs` for a product outside the product page.
	 *
	 * Returns the tabs as other plugins left them before BulkTab applies its layout
	 * (priority 97, 'before') and the final list ('after'). Globals are restored.
	 *
	 * @param int $product_id Product ID.
	 * @return array{before: array, after: array}
	 */
	public static function scan( int $product_id ): array {
		$result  = array(
			'before' => array(),
			'after'  => array(),
		);
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$target  = get_post( $product_id );
		if ( ! $product instanceof \WC_Product || ! $target instanceof \WP_Post ) {
			return $result;
		}

		global $post;
		$saved_post    = $post;
		$saved_product = $GLOBALS['product'] ?? null;

		$post               = $target; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		$GLOBALS['product'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restored below.
		setup_postdata( $target );

		$capture = static function ( $tabs ) use ( &$result ) {
			$result['before'] = is_array( $tabs ) ? $tabs : array();
			return $tabs;
		};
		add_filter( 'woocommerce_product_tabs', $capture, 97 );
		ob_start();
		try {
			$after           = apply_filters( 'woocommerce_product_tabs', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
			$result['after'] = is_array( $after ) ? $after : array();
		} catch ( \Throwable $e ) {
			// Another plugin's tab code failed outside the product page: report what we have.
			unset( $e );
		} finally {
			ob_end_clean();
			remove_filter( 'woocommerce_product_tabs', $capture, 97 );
			$post               = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
			$GLOBALS['product'] = $saved_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restoring.
			if ( $saved_post instanceof \WP_Post ) {
				setup_postdata( $saved_post );
			}
		}
		return $result;
	}

	/**
	 * BulkTab tabs a product should show: ref => front-end key.
	 *
	 * @param array $tabs Final tabs array (scan()['after']).
	 * @return array<string, string>
	 */
	public static function expected_refs( array $tabs ): array {
		$out = array();
		foreach ( $tabs as $key => $tab ) {
			if ( is_array( $tab ) && ! empty( $tab['bulktab_ref'] ) ) {
				$out[ (string) $tab['bulktab_ref'] ] = (string) $key;
			}
		}
		return $out;
	}

	/**
	 * Fetch the product page as a guest and compare it with the expected tabs.
	 *
	 * @param int $product_id Product ID.
	 * @return array{type: string, message: string} Notice type (success|warning|error) and message.
	 */
	public static function check( int $product_id ): array {
		if ( 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) ) {
			return self::result( 'error', __( 'Pick a published product to check.', 'bulktab' ) );
		}
		$refs = self::expected_refs( self::scan( $product_id )['after'] );
		if ( ! $refs ) {
			return self::result( 'warning', __( 'This product has no BulkTab tabs to look for. Pick a product with a custom or shared tab.', 'bulktab' ) );
		}

		$url      = add_query_arg( 'bulktab_check', (string) time(), (string) get_permalink( $product_id ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return self::result( 'error', sprintf( __( 'Could not load the product page: %s', 'bulktab' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return self::result( 'error', sprintf( __( 'The product page returned HTTP status %d, so it could not be checked.', 'bulktab' ), $code ) );
		}

		$missing = self::missing_refs( (string) wp_remote_retrieve_body( $response ), $refs );
		if ( ! $missing ) {
			return self::result(
				'success',
				/* translators: %d: number of tabs */
				sprintf( _n( 'All good: the %d BulkTab tab was found on the product page.', 'All good: all %d BulkTab tabs were found on the product page.', count( $refs ), 'bulktab' ), count( $refs ) )
			);
		}
		if ( count( $missing ) === count( $refs ) ) {
			return self::result(
				'error',
				__( 'No BulkTab tabs were found on the product page. Your theme or page builder may not use WooCommerce product tabs, or a page cache is serving an old copy. Clear the cache, or add the [bulktab_tabs] shortcode where the tabs should appear.', 'bulktab' )
			);
		}
		return self::result(
			'warning',
			/* translators: %s: comma-separated tab IDs */
			sprintf( __( 'Some BulkTab tabs were not found on the product page: %s. A theme, page builder setting or page cache may be hiding them.', 'bulktab' ), implode( ', ', $missing ) )
		);
	}

	/**
	 * Result array.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Message.
	 * @return array{type: string, message: string}
	 */
	private static function result( string $type, string $message ): array {
		return array(
			'type'    => $type,
			'message' => $message,
		);
	}
}
