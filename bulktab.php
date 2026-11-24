<?php
/**
 * Plugin Name:          BulkTab – Bulk Product Tabs for WooCommerce
 * Plugin URI:           https://github.com/waukeganlabsllc/bulktab
 * Description:          Tabs that scale with your catalog: set product info once, apply it everywhere, never lose it.
 * Version:              0.1.0
 * Requires at least:    6.8
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Waukegan Labs LLC
 * Author URI:           https://github.com/waukeganlabsllc
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          bulktab
 * Domain Path:          /languages
 * WC requires at least: 9.6
 * WC tested up to:      10.4
 *
 * @package BulkTab
 */

defined( 'ABSPATH' ) || exit;

define( 'BULKTAB_VERSION', '0.1.0' );
define( 'BULKTAB_FILE', __FILE__ );
define( 'BULKTAB_DIR', plugin_dir_path( __FILE__ ) );
define( 'BULKTAB_URL', plugin_dir_url( __FILE__ ) );

require_once BULKTAB_DIR . 'includes/autoload.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, array( \BulkTab\Plugin::class, 'activate' ) );

add_action( 'plugins_loaded', array( \BulkTab\Plugin::class, 'boot' ), 20 );
