<?php
/**
 * Minimal meta storage interface so TabStore logic is unit-testable.
 *
 * @package BulkTab
 */

namespace BulkTab;

/**
 * Key/value storage for one object's meta (product or shared tab).
 */
interface MetaStore {

	/**
	 * Read a value.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $key       Meta key.
	 * @return string|null Null when the key does not exist OR holds a value that isn't plain text.
	 */
	public function get( int $object_id, string $key ): ?string;

	/**
	 * Whether the key exists at all, readable or not (R-4: unreadable data is never treated as absent).
	 *
	 * @param int    $object_id Object ID.
	 * @param string $key       Meta key.
	 * @return bool
	 */
	public function has( int $object_id, string $key ): bool;

	/**
	 * Store a value byte-for-byte (no unslashing).
	 *
	 * @param int    $object_id Object ID.
	 * @param string $key       Meta key.
	 * @param string $value     Value.
	 * @return bool True when the stored value now equals $value.
	 */
	public function set( int $object_id, string $key, string $value ): bool;

	/**
	 * Delete a value.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $key       Meta key.
	 */
	public function delete( int $object_id, string $key ): void;
}
