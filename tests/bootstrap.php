<?php
/**
 * PHPUnit bootstrap for unit tests of the pure classes (no WordPress loaded).
 *
 * @package BulkTab
 */

error_reporting( E_ALL );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/bulktab-fake-wp/' );
}

require_once dirname( __DIR__ ) . '/includes/autoload.php';
require_once __DIR__ . '/unit/InMemoryMetaStore.php';
