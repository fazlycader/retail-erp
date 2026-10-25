<?php
/**
 * Plugin Name:       MVRK Woo Connector
 * Description:       Connects this WooCommerce shop to its MVRK+ ERP: live "Available at our showrooms" per product, and "Reserve to view in showroom" requests. Products, prices, stock and orders are synced by the ERP itself.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            MVRK+
 * License:           GPL-2.0-or-later
 * Text Domain:       mvrk-woo-connector
 * WC requires at least: 8.0
 * WC tested up to:   9.3
 *
 * No business data is stored in WordPress: only the app address, the connection token, the last
 * connection check and a 5-minute cache of availability answers.
 */
defined('ABSPATH') || exit;

define('MVRK_WC_VERSION', '1.0.0');
define('MVRK_WC_FILE', __FILE__);
define('MVRK_WC_DIR', plugin_dir_path(__FILE__));
define('MVRK_WC_URL', plugin_dir_url(__FILE__));

require_once MVRK_WC_DIR.'includes/brand.php';
require_once MVRK_WC_DIR.'includes/class-mvrk-api.php';
require_once MVRK_WC_DIR.'includes/class-mvrk-plugin.php';
require_once MVRK_WC_DIR.'includes/class-mvrk-settings.php';
require_once MVRK_WC_DIR.'includes/class-mvrk-availability.php';

// WooCommerce High-Performance Order Storage: this plugin never reads or writes orders.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', MVRK_WC_FILE, true);
    }
});

add_action('plugins_loaded', [Mvrk_Wc_Plugin::class, 'boot']);
