<?php
/**
 * Jan Newsletter Uninstall
 *
 * Fired when the plugin is uninstalled.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete options
delete_option('jan_newsletter_settings');
delete_option('jan_newsletter_db_version');
delete_option('jan_newsletter_intercept_wp_mail');

// Drop tables
$tables = [
    $wpdb->prefix . 'jan_nl_subscribers',
    $wpdb->prefix . 'jan_nl_lists',
    $wpdb->prefix . 'jan_nl_list_subscriber',
    $wpdb->prefix . 'jan_nl_campaigns',
    $wpdb->prefix . 'jan_nl_queue',
    $wpdb->prefix . 'jan_nl_logs',
    $wpdb->prefix . 'jan_nl_stats',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Clear scheduled cron
wp_clear_scheduled_hook('jan_newsletter_process_queue');

// Flush rewrite rules
flush_rewrite_rules();
