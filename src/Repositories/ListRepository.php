<?php

namespace JanNewsletter\Repositories;

use JanNewsletter\Models\SubscriberList;

/**
 * List repository
 */
class ListRepository {
    private string $table;
    private string $pivot_table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'jan_nl_lists';
        $this->pivot_table = $wpdb->prefix . 'jan_nl_list_subscriber';
    }

    /**
     * Find list by ID
     */
    public function find(int $id): ?SubscriberList {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, COUNT(ls.subscriber_id) as subscriber_count
            FROM {$this->table} l
            LEFT JOIN {$this->pivot_table} ls ON l.id = ls.list_id
            WHERE l.id = %d
            GROUP BY l.id",
            $id
        ));

        return $row ? SubscriberList::from_row($row) : null;
    }

    /**
     * Find list by slug
     */
    public function find_by_slug(string $slug): ?SubscriberList {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, COUNT(ls.subscriber_id) as subscriber_count
            FROM {$this->table} l
            LEFT JOIN {$this->pivot_table} ls ON l.id = ls.list_id
            WHERE l.slug = %s
            GROUP BY l.id",
            $slug
        ));

        return $row ? SubscriberList::from_row($row) : null;
    }

    /**
     * Get all lists
     */
    public function get_all(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT l.*, COUNT(ls.subscriber_id) as subscriber_count
            FROM {$this->table} l
            LEFT JOIN {$this->pivot_table} ls ON l.id = ls.list_id
            GROUP BY l.id
            ORDER BY l.name ASC"
        );

        return array_map(fn($row) => SubscriberList::from_row($row), $rows);
    }

    /**
     * Count lists
     */
    public function count(): int {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }

    /**
     * Insert new list
     */
    public function insert(SubscriberList $list): int {
        global $wpdb;

        // Ensure unique slug
        $list->slug = $this->ensure_unique_slug($list->slug);

        $wpdb->insert(
            $this->table,
            $list->to_array(),
            ['%s', '%s', '%s', '%d']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update list
     */
    public function update(SubscriberList $list): bool {
        global $wpdb;

        // Ensure unique slug (excluding current)
        $list->slug = $this->ensure_unique_slug($list->slug, $list->id);

        $result = $wpdb->update(
            $this->table,
            $list->to_array(),
            ['id' => $list->id],
            ['%s', '%s', '%s', '%d'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete list
     */
    public function delete(int $id): bool {
        global $wpdb;

        // Remove all subscribers from list first
        $wpdb->delete($this->pivot_table, ['list_id' => $id], ['%d']);

        // Delete list
        return $wpdb->delete($this->table, ['id' => $id], ['%d']) !== false;
    }

    /**
     * Check if slug exists
     */
    public function slug_exists(string $slug, ?int $exclude_id = null): bool {
        global $wpdb;

        if ($exclude_id) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE slug = %s AND id != %d",
                $slug,
                $exclude_id
            ));
        } else {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE slug = %s",
                $slug
            ));
        }

        return (int) $count > 0;
    }

    /**
     * Ensure unique slug
     */
    private function ensure_unique_slug(string $slug, ?int $exclude_id = null): string {
        $original_slug = $slug;
        $counter = 1;

        while ($this->slug_exists($slug, $exclude_id)) {
            $slug = $original_slug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get subscriber count for list
     */
    public function get_subscriber_count(int $list_id): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->pivot_table} WHERE list_id = %d",
            $list_id
        ));
    }

    /**
     * Get active subscriber count for list (subscribed status)
     */
    public function get_active_subscriber_count(int $list_id): int {
        global $wpdb;

        $subscriber_table = $wpdb->prefix . 'jan_nl_subscribers';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
            FROM {$this->pivot_table} ls
            INNER JOIN {$subscriber_table} s ON ls.subscriber_id = s.id
            WHERE ls.list_id = %d AND s.status = 'subscribed'",
            $list_id
        ));
    }

    /**
     * Create list from array
     */
    public function create(array $data): int {
        global $wpdb;

        $slug = $data['slug'] ?? sanitize_title($data['name'] ?? '');
        $slug = $this->ensure_unique_slug($slug);

        $wpdb->insert(
            $this->table,
            [
                'name' => $data['name'] ?? '',
                'slug' => $slug,
                'description' => $data['description'] ?? '',
                'double_optin' => $data['double_optin'] ?? 0,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update list by ID with array data
     */
    public function update_by_id(int $id, array $data): bool {
        global $wpdb;

        $update_data = [];
        $format = [];

        if (isset($data['name'])) {
            $update_data['name'] = $data['name'];
            $format[] = '%s';
        }

        if (isset($data['slug'])) {
            $update_data['slug'] = $this->ensure_unique_slug($data['slug'], $id);
            $format[] = '%s';
        }

        if (isset($data['description'])) {
            $update_data['description'] = $data['description'];
            $format[] = '%s';
        }

        if (isset($data['double_optin'])) {
            $update_data['double_optin'] = $data['double_optin'];
            $format[] = '%d';
        }

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
     * Get meta value for list
     */
    public function get_meta(int $list_id, string $key): ?string {
        $option_key = "jan_nl_list_{$list_id}_{$key}";
        $value = get_option($option_key, null);
        return $value !== null ? (string) $value : null;
    }

    /**
     * Update meta value for list
     */
    public function update_meta(int $list_id, string $key, string $value): bool {
        $option_key = "jan_nl_list_{$list_id}_{$key}";
        return update_option($option_key, $value);
    }

    /**
     * Delete meta value for list
     */
    public function delete_meta(int $list_id, string $key): bool {
        $option_key = "jan_nl_list_{$list_id}_{$key}";
        return delete_option($option_key);
    }

    /**
     * Find list by meta value
     */
    public function find_by_meta(string $key, string $value): ?SubscriberList {
        global $wpdb;

        // Get all lists and check their meta
        $rows = $wpdb->get_results("SELECT * FROM {$this->table}");

        foreach ($rows as $row) {
            $meta_value = $this->get_meta((int) $row->id, $key);
            if ($meta_value === $value) {
                return SubscriberList::from_row($row);
            }
        }

        return null;
    }

    /**
     * Get all lists that have a specific meta key
     */
    public function get_all_with_meta(string $key): array {
        global $wpdb;

        $rows = $wpdb->get_results("SELECT * FROM {$this->table}");
        $lists = [];

        foreach ($rows as $row) {
            $meta_value = $this->get_meta((int) $row->id, $key);
            if ($meta_value !== null) {
                $lists[] = SubscriberList::from_row($row);
            }
        }

        return $lists;
    }
}
