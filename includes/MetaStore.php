<?php
/**
 * Minimal meta storage interface so TabStore logic is unit-testable.
 *
 * @package BulkTab
 */

namespace BulkTab;

interface MetaStore {

	/** @return string|null Null when the key does not exist. */
	public function get( int $object_id, string $key ): ?string;

	/** Must store the value byte-for-byte (no unslashing). Returns true on success. */
	public function set( int $object_id, string $key, string $value ): bool;

	public function delete( int $object_id, string $key ): void;
}
