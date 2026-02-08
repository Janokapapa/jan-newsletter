<?php

namespace JanNewsletter;

/**
 * Plugin activation and deactivation
 */
class Activator {
    /**
     * Run on plugin activation
     */
    public static function activate(): void {
        self::create_tables();
        self::schedule_cron();
        self::set_default_options();

        // Flush rewrite rules for custom endpoints
        flush_rewrite_rules();
    }

    /**
     * Run on plugin deactivation
     */
    public static function deactivate(): void {
        self::unschedule_cron();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create database tables
     */
    private static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'jan_nl_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Subscribers table
        $sql_subscribers = "CREATE TABLE {$prefix}subscribers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) DEFAULT '',
            last_name VARCHAR(100) DEFAULT '',
            status ENUM('subscribed','unsubscribed','bounced','pending') DEFAULT 'pending',
            source VARCHAR(100) DEFAULT '',
            confirmation_token VARCHAR(64) DEFAULT NULL,
            confirmed_at DATETIME DEFAULT NULL,
            bounce_status ENUM('none','soft','hard','complaint') DEFAULT 'none',
            bounce_count TINYINT UNSIGNED DEFAULT 0,
            custom_fields JSON DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_email (email),
            KEY idx_status (status),
            KEY idx_bounce (bounce_status)
        ) $charset_collate;";

        dbDelta($sql_subscribers);

        // Lists table
        $sql_lists = "CREATE TABLE {$prefix}lists (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '',
            double_optin TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_slug (slug)
        ) $charset_collate;";

        dbDelta($sql_lists);

        // List-Subscriber pivot table
        $sql_list_subscriber = "CREATE TABLE {$prefix}list_subscriber (
            list_id INT UNSIGNED NOT NULL,
            subscriber_id BIGINT UNSIGNED NOT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (list_id, subscriber_id),
            KEY idx_subscriber (subscriber_id)
        ) $charset_collate;";

        dbDelta($sql_list_subscriber);

        // Campaigns table
        $sql_campaigns = "CREATE TABLE {$prefix}campaigns (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL,
            subject VARCHAR(500) NOT NULL,
            body_html LONGTEXT DEFAULT NULL,
            body_text LONGTEXT DEFAULT NULL,
            from_name VARCHAR(100) DEFAULT '',
            from_email VARCHAR(255) DEFAULT '',
            list_id INT UNSIGNED DEFAULT NULL,
            status ENUM('draft','scheduled','sending','sent','paused') DEFAULT 'draft',
            scheduled_at DATETIME DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            finished_at DATETIME DEFAULT NULL,
            total_recipients INT UNSIGNED DEFAULT 0,
            sent_count INT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_list (list_id)
        ) $charset_collate;";

        dbDelta($sql_campaigns);

        // Email queue table
        $sql_queue = "CREATE TABLE {$prefix}queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            to_email VARCHAR(500) NOT NULL,
            from_email VARCHAR(255) NOT NULL,
            from_name VARCHAR(255) DEFAULT '',
            subject VARCHAR(500) NOT NULL,
            body_html LONGTEXT DEFAULT NULL,
            body_text LONGTEXT DEFAULT NULL,
            headers TEXT DEFAULT NULL,
            attachments TEXT DEFAULT NULL,
            status ENUM('pending','processing','sent','failed','cancelled') DEFAULT 'pending',
            priority TINYINT UNSIGNED DEFAULT 5,
            attempts INT UNSIGNED DEFAULT 0,
            max_attempts TINYINT UNSIGNED DEFAULT 3,
            error_message TEXT DEFAULT NULL,
            source VARCHAR(50) DEFAULT 'wordpress',
            subscriber_id BIGINT UNSIGNED DEFAULT NULL,
            campaign_id INT UNSIGNED DEFAULT NULL,
            scheduled_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_process (status, priority, created_at),
            KEY idx_subscriber (subscriber_id),
            KEY idx_campaign (campaign_id)
        ) $charset_collate;";

        dbDelta($sql_queue);

        // Email logs table
        $sql_logs = "CREATE TABLE {$prefix}logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            queue_id BIGINT UNSIGNED DEFAULT NULL,
            to_email VARCHAR(255) NOT NULL,
            from_email VARCHAR(255) DEFAULT '',
            from_name VARCHAR(255) DEFAULT '',
            subject VARCHAR(500) DEFAULT '',
            body_html LONGTEXT DEFAULT NULL,
            body_text LONGTEXT DEFAULT NULL,
            headers TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            smtp_response TEXT DEFAULT NULL,
            source VARCHAR(50) DEFAULT '',
            campaign_id INT UNSIGNED DEFAULT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_campaign (campaign_id),
            KEY idx_sent_at (sent_at)
        ) $charset_collate;";

        dbDelta($sql_logs);

        // Subscriber meta table (like WordPress postmeta)
        $sql_subscriber_meta = "CREATE TABLE {$prefix}subscriber_meta (
            meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscriber_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(255) NOT NULL,
            meta_value LONGTEXT DEFAULT NULL,
            PRIMARY KEY (meta_id),
            KEY idx_subscriber (subscriber_id),
            KEY idx_key (meta_key(191)),
            KEY idx_subscriber_key (subscriber_id, meta_key(191))
        ) $charset_collate;";

        dbDelta($sql_subscriber_meta);

        // Statistics table
        $sql_stats = "CREATE TABLE {$prefix}stats (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id INT UNSIGNED NOT NULL,
            subscriber_id BIGINT UNSIGNED DEFAULT NULL,
            email VARCHAR(255) DEFAULT '',
            event_type ENUM('sent','open','click','bounce','unsubscribe') NOT NULL,
            link_url VARCHAR(1000) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            user_agent VARCHAR(500) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_campaign (campaign_id, event_type),
            KEY idx_subscriber (subscriber_id)
        ) $charset_collate;";

        dbDelta($sql_stats);

        // Store the database version
        update_option('jan_newsletter_db_version', JAN_NEWSLETTER_VERSION);
    }

    /**
     * Schedule cron jobs
     */
    private static function schedule_cron(): void {
        if (!wp_next_scheduled('jan_newsletter_process_queue')) {
            wp_schedule_event(time(), 'jan_newsletter_interval', 'jan_newsletter_process_queue');
        }

        // Schedule daily cleanup
        if (!wp_next_scheduled('jan_newsletter_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'jan_newsletter_cleanup_logs');
        }
    }

    /**
     * Unschedule cron jobs
     */
    private static function unschedule_cron(): void {
        $timestamp = wp_next_scheduled('jan_newsletter_process_queue');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'jan_newsletter_process_queue');
        }

        $cleanup_timestamp = wp_next_scheduled('jan_newsletter_cleanup_logs');
        if ($cleanup_timestamp) {
            wp_unschedule_event($cleanup_timestamp, 'jan_newsletter_cleanup_logs');
        }
    }

    /**
     * Set default options
     */
    private static function set_default_options(): void {
        if (!get_option('jan_newsletter_settings')) {
            update_option('jan_newsletter_settings', Plugin::get_default_settings());
        }
    }
}

