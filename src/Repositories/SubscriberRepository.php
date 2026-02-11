<?php

namespace JanNewsletter\Repositories;

use JanNewsletter\Models\Subscriber;

/**
 * Subscriber repository
 */
class SubscriberRepository {
    private string $table;
    private string $pivot_table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'jan_nl_subscribers';
        $this->pivot_table = $wpdb->prefix . 'jan_nl_list_subscriber';
    }

    /**
     * Find subscriber by ID
     */
    public function find(int $id): ?Subscriber {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ));

        return $row ? Subscriber::from_row($row) : null;
    }

    /**
     * Find subscriber by email
     */
    public function find_by_email(string $email): ?Subscriber {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE email = %s",
            $email
        ));

        return $row ? Subscriber::from_row($row) : null;
    }

    /**
     * Find subscriber by confirmation token
     */
    public function find_by_token(string $token): ?Subscriber {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE confirmation_token = %s",
            $token
        ));

        return $row ? Subscriber::from_row($row) : null;
    }

    /**
     * Get all subscribers with pagination and filters
     */
    public function get_all(array $args = []): array {
        global $wpdb;

        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'status' => null,
            'list_id' => null,
            'search' => null,
            'order_by' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $offset = ($args['page'] - 1) * $args['per_page'];

        $where = ['1=1'];
        $params = [];

        if ($args['status']) {
            $where[] = 's.status = %s';
            $params[] = $args['status'];
        }

        if ($args['list_id']) {
            $where[] = 'ls.list_id = %d';
            $params[] = $args['list_id'];
        }

        if ($args['search']) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $where_sql = implode(' AND ', $where);
        $order_by = sanitize_sql_orderby($args['order_by'] . ' ' . $args['order']) ?: 'created_at DESC';

        $join = '';
        if ($args['list_id']) {
            $join = "INNER JOIN {$this->pivot_table} ls ON s.id = ls.subscriber_id";
        }

        $sql = "SELECT DISTINCT s.* FROM {$this->table} s {$join} WHERE {$where_sql} ORDER BY s.{$order_by} LIMIT %d OFFSET %d";
        $params[] = $args['per_page'];
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        return array_map(fn($row) => Subscriber::from_row($row), $rows);
    }

    /**
     * Count subscribers with filters
     */
    public function count(array $args = []): int {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($args['status'])) {
            $where[] = 's.status = %s';
            $params[] = $args['status'];
        }

        if (!empty($args['list_id'])) {
            $where[] = 'ls.list_id = %d';
            $params[] = $args['list_id'];
        }

        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $where_sql = implode(' AND ', $where);

        $join = '';
        if (!empty($args['list_id'])) {
            $join = "INNER JOIN {$this->pivot_table} ls ON s.id = ls.subscriber_id";
        }

        $sql = "SELECT COUNT(DISTINCT s.id) FROM {$this->table} s {$join} WHERE {$where_sql}";

        if (!empty($params)) {
            return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Get subscribers for a list (for sending)
     */
    public function get_by_list(int $list_id, string $status = 'subscribed'): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT s.* FROM {$this->table} s
            INNER JOIN {$this->pivot_table} ls ON s.id = ls.subscriber_id
            WHERE ls.list_id = %d AND s.status = %s AND s.bounce_status != 'hard'",
            $list_id,
            $status
        );

        $rows = $wpdb->get_results($sql);
        return array_map(fn($row) => Subscriber::from_row($row), $rows);
    }

    /**
     * Insert new subscriber
     */
    public function insert(Subscriber $subscriber): int {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            $subscriber->to_array(),
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Create subscriber from array
     */
    public function create(array $data): int {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'email' => $data['email'] ?? '',
                'first_name' => $data['first_name'] ?? '',
                'last_name' => $data['last_name'] ?? '',
                'status' => $data['status'] ?? 'subscribed',
                'source' => $data['source'] ?? 'import',
                'confirmation_token' => $data['confirmation_token'] ?? null,
                'bounce_status' => $data['bounce_status'] ?? 'none',
                'bounce_count' => $data['bounce_count'] ?? 0,
                'custom_fields' => isset($data['custom_fields']) ? wp_json_encode($data['custom_fields']) : null,
                'ip_address' => $data['ip_address'] ?? '',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update subscriber (with Subscriber object)
     */
    public function update_model(Subscriber $subscriber): bool {
        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            $subscriber->to_array(),
            ['id' => $subscriber->id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Update subscriber by ID with array data
     */
    public function update(int $id, array $data): bool {
        global $wpdb;

        $update_data = [];
        $format = [];

        $allowed = ['first_name', 'last_name', 'status', 'source', 'bounce_status', 'bounce_count', 'ip_address'];

        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
                $format[] = is_int($data[$field]) ? '%d' : '%s';
            }
        }

        if (isset($data['email'])) {
            $update_data['email'] = $data['email'];
            $format[] = '%s';
        }

        if (isset($data['custom_fields'])) {
            $update_data['custom_fields'] = is_array($data['custom_fields'])
                ? wp_json_encode($data['custom_fields'])
                : $data['custom_fields'];
            $format[] = '%s';
        }

        $update_data['updated_at'] = current_time('mysql');
        $format[] = '%s';

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $this->table,
            $update_data,
            ['id' => $id],
            $format,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete subscriber
     */
    public function delete(int $id): bool {
        global $wpdb;

        // Remove from all lists first
        $wpdb->delete($this->pivot_table, ['subscriber_id' => $id], ['%d']);

        // Delete subscriber
        return $wpdb->delete($this->table, ['id' => $id], ['%d']) !== false;
    }

    /**
     * Bulk delete subscribers
     */
    public function bulk_delete(array $ids): int {
        global $wpdb;

        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Remove from all lists
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->pivot_table} WHERE subscriber_id IN ($placeholders)",
            ...$ids
        ));

        // Delete subscribers
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE id IN ($placeholders)",
            ...$ids
        ));
    }

    /**
     * Add subscriber to list
     */
    public function add_to_list(int $subscriber_id, int $list_id): bool {
        global $wpdb;

        $result = $wpdb->replace(
            $this->pivot_table,
            [
                'subscriber_id' => $subscriber_id,
                'list_id' => $list_id,
                'added_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s']
        );

        return $result !== false;
    }

    /**
     * Remove subscriber from list
     */
    public function remove_from_list(int $subscriber_id, int $list_id): bool {
        global $wpdb;

        return $wpdb->delete(
            $this->pivot_table,
            [
                'subscriber_id' => $subscriber_id,
                'list_id' => $list_id,
            ],
            ['%d', '%d']
        ) !== false;
    }

    /**
     * Get lists for subscriber
     */
    public function get_lists(int $subscriber_id): array {
        global $wpdb;

        $list_table = $wpdb->prefix . 'jan_nl_lists';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.* FROM {$list_table} l
            INNER JOIN {$this->pivot_table} ls ON l.id = ls.list_id
            WHERE ls.subscriber_id = %d",
            $subscriber_id
        ));
    }

    /**
     * Sync subscriber lists
     */
    public function sync_lists(int $subscriber_id, array $list_ids): void {
        global $wpdb;

        // Remove from all lists
        $wpdb->delete($this->pivot_table, ['subscriber_id' => $subscriber_id], ['%d']);

        // Add to new lists
        foreach ($list_ids as $list_id) {
            $this->add_to_list($subscriber_id, (int) $list_id);
        }
    }

    /**
     * Bulk add to list
     */
    public function bulk_add_to_list(array $subscriber_ids, int $list_id): int {
        global $wpdb;

        $count = 0;
        foreach ($subscriber_ids as $id) {
            if ($this->add_to_list((int) $id, $list_id)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Bulk remove subscribers from a list
     */
    public function bulk_remove_from_list(array $subscriber_ids, int $list_id): int {
        $count = 0;
        foreach ($subscriber_ids as $id) {
            if ($this->remove_from_list((int) $id, $list_id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Check if email exists
     */
    public function email_exists(string $email, ?int $exclude_id = null): bool {
        global $wpdb;

        if ($exclude_id) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE email = %s AND id != %d",
                $email,
                $exclude_id
            ));
        } else {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE email = %s",
                $email
            ));
        }

        return (int) $count > 0;
    }

    /**
     * Get statistics
     */
    public function get_stats(): array {
        global $wpdb;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        $subscribed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status = 'subscribed'");
        $unsubscribed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status = 'unsubscribed'");
        $bounced = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status = 'bounced'");
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status = 'pending'");

        // Growth (last 30 days)
        $new_last_30 = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        return [
            'total' => $total,
            'subscribed' => $subscribed,
            'unsubscribed' => $unsubscribed,
            'bounced' => $bounced,
            'pending' => $pending,
            'new_last_30_days' => $new_last_30,
        ];
    }

    // ========================================
    // META METHODS (subscriber_meta table)
    // ========================================

    /**
     * Get meta table name
     */
    private function get_meta_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'jan_nl_subscriber_meta';
    }

    /**
     * Get single meta value
     */
    public function get_meta(int $subscriber_id, string $key, $default = null) {
        global $wpdb;
        $meta_table = $this->get_meta_table();

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$meta_table} WHERE subscriber_id = %d AND meta_key = %s LIMIT 1",
            $subscriber_id,
            $key
        ));

        return $value !== null ? $value : $default;
    }

    /**
     * Get all meta for subscriber
     */
    public function get_all_meta(int $subscriber_id): array {
        global $wpdb;
        $meta_table = $this->get_meta_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$meta_table} WHERE subscriber_id = %d",
            $subscriber_id
        ));

        $meta = [];
        foreach ($rows as $row) {
            $meta[$row->meta_key] = $row->meta_value;
        }

        return $meta;
    }

    /**
     * Update or insert meta value
     */
    public function update_meta(int $subscriber_id, string $key, $value): bool {
        global $wpdb;
        $meta_table = $this->get_meta_table();

        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$meta_table} WHERE subscriber_id = %d AND meta_key = %s LIMIT 1",
            $subscriber_id,
            $key
        ));

        if ($existing) {
            $result = $wpdb->update(
                $meta_table,
                ['meta_value' => $value],
                ['meta_id' => $existing],
                ['%s'],
                ['%d']
            );
        } else {
            $result = $wpdb->insert(
                $meta_table,
                [
                    'subscriber_id' => $subscriber_id,
                    'meta_key' => $key,
                    'meta_value' => $value,
                ],
                ['%d', '%s', '%s']
            );
        }

        return $result !== false;
    }

    /**
     * Set multiple meta values at once
     */
    public function set_meta_batch(int $subscriber_id, array $meta): void {
        foreach ($meta as $key => $value) {
            if ($value !== null && $value !== '') {
                $this->update_meta($subscriber_id, $key, $value);
            }
        }
    }

    /**
     * Delete meta value
     */
    public function delete_meta(int $subscriber_id, string $key): bool {
        global $wpdb;
        $meta_table = $this->get_meta_table();

        return $wpdb->delete(
            $meta_table,
            ['subscriber_id' => $subscriber_id, 'meta_key' => $key],
            ['%d', '%s']
        ) !== false;
    }

    /**
     * Delete all meta for subscriber
     */
    public function delete_all_meta(int $subscriber_id): bool {
        global $wpdb;
        $meta_table = $this->get_meta_table();

        return $wpdb->delete(
            $meta_table,
            ['subscriber_id' => $subscriber_id],
            ['%d']
        ) !== false;
    }

    /**
     * Find subscribers by meta value
     */
    public function find_by_meta(string $key, string $value, string $compare = '='): array {
        global $wpdb;
        $meta_table = $this->get_meta_table();

        if ($compare === 'LIKE') {
            $sql = $wpdb->prepare(
                "SELECT s.* FROM {$this->table} s
                INNER JOIN {$meta_table} m ON s.id = m.subscriber_id
                WHERE m.meta_key = %s AND m.meta_value LIKE %s",
                $key,
                '%' . $wpdb->esc_like($value) . '%'
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT s.* FROM {$this->table} s
                INNER JOIN {$meta_table} m ON s.id = m.subscriber_id
                WHERE m.meta_key = %s AND m.meta_value = %s",
                $key,
                $value
            );
        }

        $rows = $wpdb->get_results($sql);
        return array_map(fn($row) => Subscriber::from_row($row), $rows);
    }

    /**
     * Count subscribers by meta value
     */
    public function count_by_meta(string $key, string $value): int {
        global $wpdb;
        $meta_table = $this->get_meta_table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT s.id) FROM {$this->table} s
            INNER JOIN {$meta_table} m ON s.id = m.subscriber_id
            WHERE m.meta_key = %s AND m.meta_value = %s",
            $key,
            $value
        ));
    }
}
