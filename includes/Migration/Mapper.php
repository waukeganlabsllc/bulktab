<?php
/**
 * Maps one product's YIKES tabs to a BulkTab plan, reproducing what YIKES
 * showed on the storefront:
 *
 * - YIKES keys tabs by `id` (sanitized title) and skips empty titles, so a
 *   later tab with the same id replaces an earlier one, and an id equal to a
 *   WooCommerce built-in ("reviews") replaces that built-in. Tabs YIKES did
 *   not show are imported hidden, so nothing is lost and nothing new appears.
 * - A copy of a saved tab (linked in the applied map) that still equals the
 *   saved version becomes a reference to the shared tab (not duplicated).
 * - A linked copy that drifted keeps the product's text as its own tab, and
 *   the shared tab is attached but hidden on that product.
 * - An unlinked copy identical to a saved tab (same slug, title, content;
 *   CSV imports drop the applied map) also becomes a reference.
 *
 * Pure PHP, no WordPress calls.
 *
 * @package BulkTab
 */

namespace BulkTab\Migration;

use BulkTab\Layout;

defined( 'ABSPATH' ) || exit;

/**
 * Builds per-product migration plans.
 */
final class Mapper {

	/** Bump when mapping rules change, so products are re-evaluated. */
	const VERSION = 1;

	/**
	 * Plan for one product.
	 *
	 * @param array $tabs    YikesData::product_tabs() result.
	 * @param array $applied saved_id => tab_id for this product (YikesData::applied_map()[ $id ]).
	 * @param array $saved   YikesData::saved_tabs() result.
	 * @param array $keys    saved_id => BulkTab shared-tab key.
	 * @return array{items:array,attach:string[],hide:string[],issues:array,counts:array<string,int>}
	 */
	public static function map_product( array $tabs, array $applied, array $saved, array $keys ): array {
		$plan = array(
			'items'  => array(),
			'attach' => array(),
			'hide'   => array(),
			'issues' => array(),
			'counts' => array(
				'tabs'             => count( $tabs ),
				'shared'           => 0,
				'drifted'          => 0,
				'custom'           => 0,
				'hidden'           => 0,
				'skipped_empty'    => 0,
				'matched_unlinked' => 0,
			),
		);

		// The tab YIKES actually rendered for each id: the last one with a title.
		$last = array();
		foreach ( $tabs as $i => $tab ) {
			if ( self::yikes_shows( $tab ) ) {
				$last[ $tab['id'] ] = $i;
			}
		}

		// tab_id => saved_id, for links to saved tabs that still exist.
		$links = array();
		foreach ( $applied as $saved_id => $tab_id ) {
			if ( ! isset( $saved[ $saved_id ], $keys[ $saved_id ] ) ) {
				$plan['issues'][] = array(
					'code'   => 'saved_tab_missing',
					'detail' => (string) $saved_id,
				);
				continue;
			}
			if ( ! isset( $last[ $tab_id ] ) ) {
				$plan['issues'][] = array(
					'code'   => 'applied_without_copy',
					'detail' => $saved[ $saved_id ]['title'],
				);
				continue;
			}
			$links[ $tab_id ] = (int) $saved_id;
		}

		$used_keys = array();
		foreach ( $tabs as $i => $tab ) {
			$title   = $tab['title'];
			$content = $tab['content'];

			if ( '' === trim( $title ) && '' === trim( $content ) ) {
				++$plan['counts']['skipped_empty'];
				continue;
			}

			if ( ! self::yikes_shows( $tab ) || $last[ $tab['id'] ] !== $i ) {
				$plan['items'][]  = self::custom( $title, $content, true );
				$plan['issues'][] = array(
					'code'   => self::yikes_shows( $tab ) ? 'duplicate_hidden' : 'empty_title_hidden',
					'detail' => $title,
				);
				++$plan['counts']['custom'];
				++$plan['counts']['hidden'];
				continue;
			}

			if ( in_array( $tab['id'], Layout::BUILT_IN, true ) && ! in_array( $tab['id'], $plan['hide'], true ) ) {
				$plan['hide'][]   = $tab['id'];
				$plan['issues'][] = array(
					'code'   => 'replaced_builtin',
					'detail' => $tab['id'],
				);
			}

			$saved_id = $links[ $tab['id'] ] ?? 0;
			if ( $saved_id && ! isset( $used_keys[ $keys[ $saved_id ] ] ) ) {
				$key               = $keys[ $saved_id ];
				$used_keys[ $key ] = true;
				$plan['attach'][]  = $key;
				$matches           = self::same( $tab, $saved[ $saved_id ] );
				$plan['items'][]   = $matches ? self::shared( $key ) : self::custom( $title, $content, false );
				if ( $matches ) {
					++$plan['counts']['shared'];
					continue;
				}
				$plan['hide'][]   = 's:' . $key;
				$plan['issues'][] = array(
					'code'   => 'drifted',
					'detail' => $title,
				);
				++$plan['counts']['drifted'];
				++$plan['counts']['custom'];
				continue;
			}

			$match = $saved_id ? 0 : self::unlinked_match( $tab, $saved, $keys, $used_keys );
			if ( $match ) {
				$key               = $keys[ $match ];
				$used_keys[ $key ] = true;
				$plan['attach'][]  = $key;
				$plan['items'][]   = self::shared( $key );
				++$plan['counts']['shared'];
				++$plan['counts']['matched_unlinked'];
				continue;
			}

			$plan['items'][] = self::custom( $title, $content, false );
			++$plan['counts']['custom'];
		}

		return $plan;
	}

	/**
	 * Fingerprint of everything a product's migration depends on. Unchanged
	 * fingerprint = nothing to do on a re-run.
	 *
	 * @param string $raw     Raw meta value.
	 * @param array  $applied saved_id => tab_id for this product.
	 * @param array  $saved   All saved tabs (only linked ones are hashed).
	 * @param array  $keys    saved_id => key.
	 * @return string sha1 hex.
	 */
	public static function source_hash( string $raw, array $applied, array $saved, array $keys ): string {
		ksort( $applied );
		$linked = array();
		foreach ( $applied as $saved_id => $tab_id ) {
			$linked[] = implode(
				"\x1f",
				array(
					(int) $saved_id,
					sha1( (string) $tab_id ),
					sha1( (string) ( $keys[ $saved_id ] ?? '' ) ),
					isset( $saved[ $saved_id ] ) ? sha1( $saved[ $saved_id ]['title'] . "\0" . $saved[ $saved_id ]['content'] ) : '-',
				)
			);
		}
		return sha1( 'v' . self::VERSION . "\0" . $raw . "\0" . implode( "\x1e", $linked ) );
	}

	/**
	 * Our `bulktab_use_the_content` value for YIKES's `yikes_cpt_use_the_content`.
	 *
	 * The YIKES option is inverted: 'true' means "do NOT run the_content, use our
	 * own filter"; anything else means YIKES ran the_content. Ours is truthy when
	 * the_content should run.
	 *
	 * @param mixed $yikes_value Stored YIKES value (string 'true' / 'false' or missing).
	 * @return string '1' or '0'.
	 */
	public static function use_the_content( $yikes_value ): string {
		return 'true' === $yikes_value ? '0' : '1';
	}

	/**
	 * Base for a shared tab's key: the admin name, else the title.
	 *
	 * @param array $saved One YikesData::saved_tabs() row.
	 * @return string
	 */
	public static function key_source( array $saved ): string {
		return '' !== trim( $saved['name'] ) ? $saved['name'] : $saved['title'];
	}

	/**
	 * Did YIKES render this tab at all? (It skips empty() titles, including "0".)
	 *
	 * @param array $tab Tab.
	 * @return bool
	 */
	private static function yikes_shows( array $tab ): bool {
		return '' !== $tab['title'] && '0' !== $tab['title'];
	}

	/**
	 * Is the product's copy the same as the saved tab?
	 *
	 * @param array $tab   Product tab.
	 * @param array $saved Saved tab.
	 * @return bool
	 */
	private static function same( array $tab, array $saved ): bool {
		return YikesData::normalize( $tab['title'] ) === YikesData::normalize( $saved['title'] )
			&& YikesData::normalize( $tab['content'] ) === YikesData::normalize( $saved['content'] );
	}

	/**
	 * Saved tab an unlinked copy is identical to (same slug, title, content), or 0.
	 *
	 * @param array $tab       Product tab.
	 * @param array $saved     Saved tabs.
	 * @param array $keys      saved_id => key.
	 * @param array $used_keys Keys already used on this product.
	 * @return int
	 */
	private static function unlinked_match( array $tab, array $saved, array $keys, array $used_keys ): int {
		foreach ( $saved as $saved_id => $row ) {
			if ( isset( $keys[ $saved_id ] ) && ! isset( $used_keys[ $keys[ $saved_id ] ] )
				&& '' !== $row['slug'] && $row['slug'] === $tab['id'] && self::same( $tab, $row ) ) {
				return (int) $saved_id;
			}
		}
		return 0;
	}

	/**
	 * Plan item: reference to a shared tab.
	 *
	 * @param string $key Shared-tab key.
	 * @return array
	 */
	private static function shared( string $key ): array {
		return array(
			'kind' => 'shared',
			'key'  => $key,
		);
	}

	/**
	 * Plan item: product's own tab.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @param bool   $hidden  Hidden on the storefront.
	 * @return array
	 */
	private static function custom( string $title, string $content, bool $hidden ): array {
		return array(
			'kind'    => 'custom',
			'title'   => $title,
			'content' => $content,
			'hidden'  => $hidden,
		);
	}
}
