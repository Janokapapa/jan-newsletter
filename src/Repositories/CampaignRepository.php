<?php

namespace JanNewsletter\Repositories;

use JanNewsletter\Models\Campaign;

/**
 * Campaign repository
 */
class CampaignRepository {
    private string $table;
    private string $stats_table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'jan_nl_campaigns';
        $this->stats_table = $wpdb->prefix . 'jan_nl_stats';
    }

    /**
     * Find campaign by ID
     */
    public function find(int $id): ?Campaign {
        global $wpdb;

        $list_table = $wpdb->prefix . 'jan_nl_lists';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, l.name as list_name,
                (SELECT COUNT(*) FROM {$this->stats_table} WHERE campaign_id = c.id AND event_type = 'open') as open_count,
                (SELECT COUNT(*) FROM {$this->stats_table} WHERE campaign_id = c.id AND event_type = 'click') as click_count
            FROM {$this->table} c
            LEFT JOIN {$list_table} l ON c.list_id = l.id
            WHERE c.id = %d",
            $id
        ));

        return $row ? Campaign::from_row($row) : null;
    }

    /**
     * Get all campaigns with pagination
     */
    public function get_all(array $args = []): array {
        global $wpdb;

        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'status' => null,
            'order_by' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $offset = ($args['page'] - 1) * $args['per_page'];

        $list_table = $wpdb->prefix . 'jan_nl_lists';

        $where = ['1=1'];
        $params = [];

        if ($args['status']) {
            $where[] = 'c.status = %s';
            $params[] = $args['status'];
        }

        $where_sql = implode(' AND ', $where);
        $order_by = sanitize_sql_orderby($args['order_by'] . ' ' . $args['order']) ?: 'created_at DESC';

        $sql = "SELECT c.*, l.name as list_name,
                (SELECT COUNT(*) FROM {$this->stats_table} WHERE campaign_id = c.id AND event_type = 'open') as open_count,
                (SELECT COUNT(*) FROM {$this->stats_table} WHERE campaign_id = c.id AND event_type = 'click') as click_count
            FROM {$this->table} c
            LEFT JOIN {$list_table} l ON c.list_id = l.id
            WHERE {$where_sql}
            ORDER BY c.{$order_by}
            LIMIT %d OFFSET %d";

        $params[] = $args['per_page'];
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        return array_map(fn($row) => Campaign::from_row($row), $rows);
    }

    /**
     * Count campaigns
     */
    public function count(array $args = []): int {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}";

        if (!empty($params)) {
            return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Insert new campaign
     */
    public function insert(Campaign $campaign): int {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            $campaign->to_array(),
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update campaign
     */
    public function update(Campaign $campaign): bool {
        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            $campaign->to_array(),
            ['id' => $campaign->id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete campaign
     */
    public function delete(int $id): bool {
        global $wpdb;

        // Delete stats
        $wpdb->delete($this->stats_table, ['campaign_id' => $id], ['%d']);

        // Delete campaign
        return $wpdb->delete($this->table, ['id' => $id], ['%d']) !== false;
    }

    /**
     * Update campaign status
     */
    public function update_status(int $id, string $status): bool {
        global $wpdb;

        $data = ['status' => $status];

        if ($status === 'sending' && !$this->find($id)?->started_at) {
            $data['started_at'] = current_time('mysql');
        }

        if ($status === 'sent') {
            $data['finished_at'] = current_time('mysql');
        }

        return $wpdb->update($this->table, $data, ['id' => $id]) !== false;
    }

    /**
     * Increment sent count
     */
    public function increment_sent_count(int $id): bool {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET sent_count = sent_count + 1 WHERE id = %d",
            $id
        )) !== false;
    }

    /**
     * Get campaigns that need to be sent (scheduled time passed)
     */
    public function get_scheduled_ready(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table}
            WHERE status = 'scheduled' AND scheduled_at <= NOW()"
        );

        return array_map(fn($row) => Campaign::from_row($row), $rows);
    }

    /**
     * Get campaigns in sending state
     */
    public function get_sending(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE status = 'sending'"
        );

        return array_map(fn($row) => Campaign::from_row($row), $rows);
    }

    /**
     * Get recent campaigns for dashboard
     */
    public function get_recent(int $limit = 5): array {
        global $wpdb;

        $list_table = $wpdb->prefix . 'jan_nl_lists';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, l.name as list_name,
                (SELECT COUNT(*) FROM {$this->stats_table} WHERE campaign_id = c.id AND event_type = 'open') as open_count,
                (SELECT COUNT(*) FROM {$this->stats_table} WHERE campaign_id = c.id AND event_type = 'click') as click_count
            FROM {$this->table} c
            LEFT JOIN {$list_table} l ON c.list_id = l.id
            WHERE c.status IN ('sent', 'sending')
            ORDER BY c.created_at DESC
            LIMIT %d",
            $limit
        ));

        return array_map(fn($row) => Campaign::from_row($row), $rows);
    }
}
