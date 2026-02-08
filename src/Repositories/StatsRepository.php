<?php

namespace JanNewsletter\Repositories;

/**
 * Statistics repository
 */
class StatsRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'jan_nl_stats';
    }

    /**
     * Record an event
     */
    public function record(array $data): int {
        global $wpdb;

        $defaults = [
            'created_at' => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        $wpdb->insert($this->table, $data);

        return (int) $wpdb->insert_id;
    }

    /**
     * Check if event already recorded (prevent duplicates)
     */
    public function event_exists(int $campaign_id, int $subscriber_id, string $event_type): bool {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
            WHERE campaign_id = %d AND subscriber_id = %d AND event_type = %s",
            $campaign_id,
            $subscriber_id,
            $event_type
        ));

        return (int) $count > 0;
    }

    /**
     * Get campaign statistics
     */
    public function get_campaign_stats(int $campaign_id): array {
        global $wpdb;

        $sent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
            WHERE campaign_id = %d AND event_type = 'sent'",
            $campaign_id
        ));

        $opens = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT subscriber_id) FROM {$this->table}
            WHERE campaign_id = %d AND event_type = 'open'",
            $campaign_id
        ));

        $clicks = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT subscriber_id) FROM {$this->table}
            WHERE campaign_id = %d AND event_type = 'click'",
            $campaign_id
        ));

        $bounces = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
            WHERE campaign_id = %d AND event_type = 'bounce'",
            $campaign_id
        ));

        $unsubscribes = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
            WHERE campaign_id = %d AND event_type = 'unsubscribe'",
            $campaign_id
        ));

        return [
            'sent' => $sent,
            'opens' => $opens,
            'clicks' => $clicks,
            'bounces' => $bounces,
            'unsubscribes' => $unsubscribes,
            'open_rate' => $sent > 0 ? round(($opens / $sent) * 100, 2) : 0,
            'click_rate' => $sent > 0 ? round(($clicks / $sent) * 100, 2) : 0,
        ];
    }

    /**
     * Get click details for campaign
     */
    public function get_campaign_clicks(int $campaign_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT link_url, COUNT(*) as click_count,
                    COUNT(DISTINCT subscriber_id) as unique_clicks
            FROM {$this->table}
            WHERE campaign_id = %d AND event_type = 'click'
            GROUP BY link_url
            ORDER BY click_count DESC",
            $campaign_id
        ));
    }

    /**
     * Get timeline data for campaign
     */
    public function get_campaign_timeline(int $campaign_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date,
                    event_type,
                    COUNT(*) as count
            FROM {$this->table}
            WHERE campaign_id = %d
            GROUP BY DATE(created_at), event_type
            ORDER BY date ASC",
            $campaign_id
        ));
    }

    /**
     * Get subscriber activity for campaign
     */
    public function get_campaign_subscriber_activity(int $campaign_id, int $limit = 100): array {
        global $wpdb;

        $subscriber_table = $wpdb->prefix . 'jan_nl_subscribers';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.email, st.event_type, st.link_url, st.created_at
            FROM {$this->table} st
            LEFT JOIN {$subscriber_table} s ON st.subscriber_id = s.id
            WHERE st.campaign_id = %d
            ORDER BY st.created_at DESC
            LIMIT %d",
            $campaign_id,
            $limit
        ));
    }

    /**
     * Get global statistics
     */
    public function get_global_stats(): array {
        global $wpdb;

        $total_sent = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE event_type = 'sent'"
        );

        $total_opens = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE event_type = 'open'"
        );

        $total_clicks = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE event_type = 'click'"
        );

        $sent_today = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}
            WHERE event_type = 'sent' AND created_at >= CURDATE()"
        );

        $sent_week = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}
            WHERE event_type = 'sent' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        $sent_month = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}
            WHERE event_type = 'sent' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        return [
            'total_sent' => $total_sent,
            'total_opens' => $total_opens,
            'total_clicks' => $total_clicks,
            'sent_today' => $sent_today,
            'sent_this_week' => $sent_week,
            'sent_this_month' => $sent_month,
            'avg_open_rate' => $total_sent > 0 ? round(($total_opens / $total_sent) * 100, 2) : 0,
            'avg_click_rate' => $total_sent > 0 ? round(($total_clicks / $total_sent) * 100, 2) : 0,
        ];
    }

    /**
     * Get daily stats for chart
     */
    public function get_daily_stats(int $days = 30): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date,
                    SUM(CASE WHEN event_type = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN event_type = 'open' THEN 1 ELSE 0 END) as opens,
                    SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) as clicks
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC",
            $days
        ));
    }

    /**
     * Get subscriber engagement
     */
    public function get_subscriber_engagement(int $subscriber_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT campaign_id, event_type, created_at
            FROM {$this->table}
            WHERE subscriber_id = %d
            ORDER BY created_at DESC
            LIMIT 100",
            $subscriber_id
        ));
    }

    /**
     * Cleanup old stats
     */
    public function cleanup(int $days = 365): int {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table}
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }
}
