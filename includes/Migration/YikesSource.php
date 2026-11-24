<?php
/**
 * Reads YIKES data straight from the database, read-only.
 *
 * Raw values are read with $wpdb (not get_option / get_post_meta) because
 * WordPress's maybe_unserialize() returns false for corrupted serialized data,
 * which is exactly the data this import must repair.
 *
 * @package BulkTab
 */

namespace BulkTab\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only access to YIKES options and product meta.
 */
final class YikesSource {

	/**
	 * Per-request cache of decoded options.
	 *
	 * @var array<string, array>
	 */
	private static $cache = array();

	/**
	 * Raw option_value, or null when the option does not exist.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	public static function raw_option( string $name ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw value needed for repair; read once per request.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Raw meta value of a product's YIKES tabs, or null.
	 *
	 * @param int $product_id Product ID.
	 * @return string|null
	 */
	public static function raw_meta( int $product_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw value needed for repair.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $product_id, YikesData::META_TABS ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Decode an option through the repairing reader.
	 *
	 * @param string $name Option name.
	 * @return array{status:string,value:mixed,error:string,layers:int}
	 */
	public static function option( string $name ): array {
		if ( ! isset( self::$cache[ $name ] ) ) {
			$raw                  = self::raw_option( $name );
			self::$cache[ $name ] = null === $raw
				? array(
					'status' => Serialized::STATUS_EMPTY,
					'value'  => null,
					'error'  => '',
					'layers' => 0,
				)
				: Serialized::decode( $raw );
		}
		return self::$cache[ $name ];
	}

	/**
	 * Saved (reusable) tabs.
	 *
	 * @return array
	 */
	public static function saved_tabs(): array {
		return YikesData::saved_tabs( self::option( YikesData::OPTION_SAVED )['value'] );
	}

	/**
	 * Applied map.
	 *
	 * @return array
	 */
	public static function applied_map(): array {
		return YikesData::applied_map( self::option( YikesData::OPTION_APPLIED )['value'] );
	}

	/**
	 * Next batch of products with YIKES tab meta, in post ID order.
	 *
	 * @param int $after_id Last product ID already processed.
	 * @param int $limit    Batch size.
	 * @return array<int, array{id:int,raw:string,title:string}>
	 */
	public static function product_batch( int $after_id, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched one-off migration scan.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value, p.post_title FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.post_id > %d AND p.post_type = 'product' AND p.post_status <> 'auto-draft'
				ORDER BY pm.post_id ASC, pm.meta_id ASC LIMIT %d",
				YikesData::META_TABS,
				$after_id,
				$limit
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['post_id'];
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array(
					'id'    => $id,
					'raw'   => (string) $row['meta_value'],
					'title' => (string) $row['post_title'],
				);
			}
		}
		return array_values( $out );
	}

	/**
	 * Products with YIKES tab meta.
	 *
	 * @return int
	 */
	public static function count_products(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counted once per job.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type = 'product' AND p.post_status <> 'auto-draft'",
				YikesData::META_TABS
			)
		);
	}

	/**
	 * Cheap check: is there any YIKES data? Two indexed lookups, cached for a day.
	 *
	 * @return bool
	 */
	public static function has_data(): bool {
		$cached = get_transient( 'bulktab_yikes_detect' );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}
		global $wpdb;
		$saved = self::raw_option( YikesData::OPTION_SAVED );
		$found = null !== $saved && strlen( $saved ) > 6; // More than "a:0:{}".
		if ( ! $found ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached in a transient.
			$found = null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", YikesData::META_TABS ) );
		}
		set_transient( 'bulktab_yikes_detect', $found ? 'yes' : 'no', DAY_IN_SECONDS );
		return $found;
	}

	/**
	 * Forget cached decodes and detection (after a job starts).
	 */
	public static function flush(): void {
		self::$cache = array();
		delete_transient( 'bulktab_yikes_detect' );
	}
}
