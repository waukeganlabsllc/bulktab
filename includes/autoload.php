<?php
/**
 * PSR-4 style autoloader: BulkTab\Admin\ProductPanel -> includes/Admin/ProductPanel.php
 *
 * @package BulkTab
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'BulkTab\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$path     = __DIR__ . '/' . $relative . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
