<?php
/**
 * Bootstrap: wires every component. Pro hooks in through the public
 * filters/actions documented in docs/extending.md.
 *
 * @package BulkTab
 */

namespace BulkTab;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	const DB_VERSION = 1;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register every component's hooks (on `plugins_loaded`, priority 20).
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		if ( ! class_exists( 'WooCommerce' ) ) {
			// "Requires Plugins" normally prevents this; keep a guard for WP-CLI / mu setups.
			return;
		}

		load_plugin_textdomain( 'bulktab', false, dirname( plugin_basename( BULKTAB_FILE ) ) . '/languages' );

		self::maybe_upgrade();

		PostType::register_hooks();
		RulesIndex::register_hooks();
		( new Renderer( new WpMetaStore() ) )->register_hooks();
		Shortcodes::register_hooks();

		// Components built in parallel; each registers only if present.
		foreach ( array(
			'BulkTab\\Migration\\Migrator',      // YIKES import (dry run, Path A auto-migrate).
			'BulkTab\\Integrations\\WcCsv',      // WooCommerce CSV import/export columns.
			'BulkTab\\Admin\\SettingsPage',      // Global layout + options.
			'BulkTab\\Compat\\Compat',           // Theme / builder / block notices.
			'BulkTab\\Admin\\DataHealth',        // R-4: report unreadable tab data.
		) as $component ) {
			if ( class_exists( $component ) ) {
				$component::register_hooks();
			}
		}

		if ( is_admin() ) {
			( new Admin\ProductPanel( new WpMetaStore() ) )->register_hooks();
			Admin\RulesMetabox::register_hooks();
			Admin\Notices::register_hooks();
		}

		/**
		 * Fires after BulkTab is loaded. Pro and integrations attach here.
		 *
		 * @param string $version Free plugin version.
		 */
		do_action( 'bulktab_loaded', BULKTAB_VERSION );
	}

	/**
	 * Activation: register the post type, build the rules index, record the schema version.
	 */
	public static function activate(): void {
		PostType::register();
		RulesIndex::rebuild();
		flush_rewrite_rules( false );
		update_option( 'bulktab_db_version', self::DB_VERSION, false );
	}

	/**
	 * Run schema upgrades when the stored DB version is older than DB_VERSION.
	 */
	private static function maybe_upgrade(): void {
		$current = (int) get_option( 'bulktab_db_version', 0 );
		if ( $current >= self::DB_VERSION ) {
			return;
		}
		// Future schema migrations run here, one version at a time.
		update_option( 'bulktab_db_version', self::DB_VERSION, false );
	}
}
