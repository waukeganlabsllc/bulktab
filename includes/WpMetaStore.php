<?php
/**
 * Post-meta implementation of MetaStore.
 *
 * Update_post_meta() unslashes its input, which silently strips backslashes
 * from tab content (a data-loss bug in older tab plugins). We wp_slash() first
 * and verify by reading back.
 *
 * @package BulkTab
 */

namespace BulkTab;

defined( 'ABSPATH' ) || exit;

/**
 * Stores values as post meta.
 */
final class WpMetaStore implements MetaStore {

	/**
	 * Read a value.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $key       Meta key.
	 * @return string|null
	 */
	public function get( int $object_id, string $key ): ?string {
		if ( ! metadata_exists( 'post', $object_id, $key ) ) {
			return null;
		}
		$value = get_post_meta( $object_id, $key, true );
		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Whether the key exists, readable or not.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $key       Meta key.
	 * @return bool
	 */
	public function has( int $object_id, string $key ): bool {
		return metadata_exists( 'post', $object_id, $key );
	}

	/**
	 * Store a value and verify it by reading back.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $key       Meta key.
	 * @param string $value     Value.
	 * @return bool
	 */
	public function set( int $object_id, string $key, string $value ): bool {
		$current = $this->get( $object_id, $key );
		if ( $current === $value ) {
			return true;
		}
		update_post_meta( $object_id, $key, wp_slash( $value ) );
		wp_cache_delete( $object_id, 'post_meta' );
		return $this->get( $object_id, $key ) === $value;
	}

	/**
	 * Delete a value.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $key       Meta key.
	 */
	public function delete( int $object_id, string $key ): void {
		delete_post_meta( $object_id, $key );
	}
}
