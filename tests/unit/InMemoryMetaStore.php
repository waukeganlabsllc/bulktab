<?php
/**
 * In-memory MetaStore for unit tests.
 *
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit;

use BulkTab\MetaStore;

final class InMemoryMetaStore implements MetaStore {

	/** @var array<int, array<string, string>> */
	public $data = array();

	/** @var callable|null fn( int $id, string $key, string $value ): bool  Return true to make that write fail. */
	public $fail_when;

	/** @var array<int, array{0:string,1:int,2:string}> Log of calls: [op, id, key]. */
	public $log = array();

	public function get( int $object_id, string $key ): ?string {
		return $this->data[ $object_id ][ $key ] ?? null;
	}

	public function set( int $object_id, string $key, string $value ): bool {
		$this->log[] = array( 'set', $object_id, $key );
		if ( null !== $this->fail_when && ( $this->fail_when )( $object_id, $key, $value ) ) {
			return false;
		}
		$this->data[ $object_id ][ $key ] = $value;
		return true;
	}

	public function delete( int $object_id, string $key ): void {
		$this->log[] = array( 'delete', $object_id, $key );
		unset( $this->data[ $object_id ][ $key ] );
	}
}
