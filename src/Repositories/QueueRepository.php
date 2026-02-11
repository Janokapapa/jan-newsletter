<?php

namespace JanNewsletter\Repositories;

use JanNewsletter\Models\QueuedEmail;

/**
 * Queue repository
 */
class QueueRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'jan_nl_queue';
    }

    /**
     * Find email by ID
     */
    public function find(int $id): ?QueuedEmail {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ));

        return $row ? QueuedEmail::from_row($row) : null;
    }

    /**
     * Get all queued emails with pagination
     */
    public function get_all(array $args = []): array {
        global $wpdb;

        $defaults = [
            'page' => 1,
            'per_page' => 50,
            'status' => null,
            'source' => null,
            'campaign_id' => null,
            'order_by' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $offset = ($args['page'] - 1) * $args['per_page'];

        $where = ['1=1'];
        $params = [];

        if ($args['status']) {
            if (is_array($args['status'])) {
                $placeholders = implode(',', array_fill(0, count($args['status']), '%s'));
                $where[] = "status IN ($placeholders)";
                $params = array_merge($params, $args['status']);
            } else {
                $where[] = 'status = %s';
                $params[] = $args['status'];
            }
        }

        if ($args['source']) {
            $where[] = 'source = %s';
            $params[] = $args['source'];
        }

        if ($args['campaign_id']) {
            $where[] = 'campaign_id = %d';
            $params[] = $args['campaign_id'];
        }

        $where_sql = implode(' AND ', $where);
        $order_by = sanitize_sql_orderby($args['order_by'] . ' ' . $args['order']) ?: 'created_at DESC';

        $sql = "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d";
        $params[] = $args['per_page'];
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        return array_map(fn($row) => QueuedEmail::from_row($row), $rows);
    }

    /**
     * Count queued emails
     */
    public function count(array $args = []): int {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($args['status'])) {
            if (is_array($args['status'])) {
                $placeholders = implode(',', array_fill(0, count($args['status']), '%s'));
                $where[] = "status IN ($placeholders)";
                $params = array_merge($params, $args['status']);
            } else {
                $where[] = 'status = %s';
                $params[] = $args['status'];
            }
        }

        if (!empty($args['source'])) {
            $where[] = 'source = %s';
            $params[] = $args['source'];
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}";

        if (!empty($params)) {
            return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Insert new email to queue
     */
    public function insert(array $data): int {
        global $wpdb;

        $defaults = [
            'status' => 'pending',
            'priority' => 5,
            'attempts' => 0,
            'max_attempts' => 3,
            'source' => 'wordpress',
            'created_at' => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        $wpdb->insert($this->table, $data);

        return (int) $wpdb->insert_id;
    }

    /**
     * Update queue item
     */
    public function update(int $id, array $data): bool {
        global $wpdb;

        return $wpdb->update($this->table, $data, ['id' => $id]) !== false;
    }

    /**
     * Delete queue item
     */
    public function delete(int $id): bool {
        global $wpdb;

        return $wpdb->delete($this->table, ['id' => $id], ['%d']) !== false;
    }

    /**
     * Get next batch to process
     */
    public function get_next_batch(int $limit = 50): array {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
            WHERE status = 'pending'
            AND (scheduled_at IS NULL OR scheduled_at <= NOW())
            ORDER BY priority ASC, created_at ASC
            LIMIT %d",
            $limit
        ));

        return array_map(fn($row) => QueuedEmail::from_row($row), $rows);
    }

    /**
     * Mark as processing
     */
    public function mark_processing(int $id): bool {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            ['status' => 'processing'],
            ['id' => $id, 'status' => 'pending']
        ) !== false;
    }

    /**
     * Mark as sent
     */
    public function mark_sent(int $id): bool {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            [
                'status' => 'sent',
                'sent_at' => current_time('mysql'),
            ],
            ['id' => $id]
        ) !== false;
    }

    /**
     * Mark as failed
     */
    public function mark_failed(int $id, string $error_message): bool {
        global $wpdb;

        $email = $this->find($id);
        if (!$email) {
            return false;
        }

        $new_status = ($email->attempts + 1 >= $email->max_attempts) ? 'failed' : 'pending';

        return $wpdb->update(
            $this->table,
            [
                'status' => $new_status,
                'attempts' => $email->attempts + 1,
                'error_message' => $error_message,
            ],
            ['id' => $id]
        ) !== false;
    }

    /**
     * Cancel queue item
     */
    public function cancel(int $id): bool {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            ['status' => 'cancelled'],
            ['id' => $id, 'status' => 'pending']
        ) !== false;
    }

    /**
     * Cancel all pending emails
     */
    public function cancel_all_pending(): int {
        global $wpdb;

        return $wpdb->query(
            "UPDATE {$this->table} SET status = 'cancelled' WHERE status IN ('pending', 'processing')"
        );
    }

    /**
     * Retry failed emails
     */
    public function retry_failed(): int {
        global $wpdb;

        return $wpdb->query(
            "UPDATE {$this->table}
            SET status = 'pending', error_message = NULL
            WHERE status = 'failed' AND attempts < max_attempts"
        );
    }

    /**
     * Clear old sent/cancelled emails
     */
    public function cleanup(int $days = 30): int {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table}
            WHERE status IN ('sent', 'cancelled')
            AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    /**
     * Get queue statistics
     */
    public function get_stats(): array {
        global $wpdb;

        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'pending'"
        );

        $processing = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'processing'"
        );

        $sent = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'sent'"
        );

        $failed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'failed'"
        );

        $sent_today = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}
            WHERE status = 'sent' AND sent_at >= CURDATE()"
        );

        $sent_week = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}
            WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        return [
            'pending' => $pending,
            'processing' => $processing,
            'sent' => $sent,
            'failed' => $failed,
            'sent_today' => $sent_today,
            'sent_this_week' => $sent_week,
        ];
    }

    /**
     * Cancel all pending for campaign
     */
    public function cancel_campaign_emails(int $campaign_id): int {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
            SET status = 'cancelled'
            WHERE campaign_id = %d AND status = 'pending'",
            $campaign_id
        ));
    }
}
