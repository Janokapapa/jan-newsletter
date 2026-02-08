<?php

namespace JanNewsletter\Mail;

/**
 * Cleanup old log content (1 month retention)
 */
class LogCleaner {
    /**
     * Initialize cron hook
     */
    public function init(): void {
        add_action('jan_newsletter_cleanup_logs', [$this, 'cleanup']);
    }

    /**
     * Remove content from logs older than 1 month
     * Keeps the log entry but clears body_html, body_text, headers
     */
    public function cleanup(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'jan_nl_logs';
        $cutoff = date('Y-m-d H:i:s', strtotime('-1 month'));

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET body_html = NULL, body_text = NULL, headers = NULL
             WHERE sent_at < %s AND (body_html IS NOT NULL OR body_text IS NOT NULL OR headers IS NOT NULL)",
            $cutoff
        ));

        if (defined('WP_DEBUG') && WP_DEBUG && $affected > 0) {
            error_log(sprintf(
                '[Jan Newsletter] Cleaned up content from %d log entries older than %s',
                $affected,
                $cutoff
            ));
        }
    }
}
