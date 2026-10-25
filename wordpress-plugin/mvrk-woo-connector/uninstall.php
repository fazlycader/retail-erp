<?php

/* Removes only this plugin's own options and cached answers. */
defined('WP_UNINSTALL_PLUGIN') || exit;

foreach (['mvrk_wc_app_url', 'mvrk_wc_token', 'mvrk_wc_last_status', 'mvrk_wc_auto_insert', 'mvrk_wc_enquiry_enabled'] as $option) {
    delete_option($option);
}

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mvrk\\_wc\\_av\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mvrk\\_wc\\_av\\_%'");
