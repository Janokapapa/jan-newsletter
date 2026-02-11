<?php

namespace JanNewsletter\Services;

use JanNewsletter\Models\Subscriber;
use JanNewsletter\Repositories\SubscriberRepository;
use JanNewsletter\Repositories\ListRepository;

/**
 * Import/Export service for subscribers
 */
class ImportExportService {
    private SubscriberRepository $subscriber_repo;
    private ListRepository $list_repo;

    public function __construct() {
        $this->subscriber_repo = new SubscriberRepository();
        $this->list_repo = new ListRepository();
    }

    /**
     * Import subscribers from CSV
     */
    public function import_csv(string $file_path, array $options = []): array {
        $defaults = [
            'list_id' => null,
            'status' => 'subscribed',
            'skip_existing' => true,
            'update_existing' => false,
        ];

        $options = wp_parse_args($options, $defaults);

        if (!file_exists($file_path) || !is_readable($file_path)) {
            return [
                'success' => false,
                'message' => __('Cannot read file', 'jan-newsletter'),
            ];
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return [
                'success' => false,
                'message' => __('Cannot open file', 'jan-newsletter'),
            ];
        }

        // Read header
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return [
                'success' => false,
                'message' => __('Empty or invalid CSV file', 'jan-newsletter'),
            ];
        }

        // Normalize header
        $header = array_map(function ($col) {
            return strtolower(trim($col));
        }, $header);

        // Find email column
        $email_col = $this->find_column($header, ['email', 'e-mail', 'email_address']);
        if ($email_col === false) {
            fclose($handle);
            return [
                'success' => false,
                'message' => __('No email column found in CSV', 'jan-newsletter'),
            ];
        }

        // Find other columns
        $first_name_col = $this->find_column($header, ['first_name', 'firstname', 'first', 'name']);
        $last_name_col = $this->find_column($header, ['last_name', 'lastname', 'last', 'surname']);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $row_num = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;

            if (count($row) <= $email_col) {
                $errors[] = sprintf(__('Row %d: Missing email', 'jan-newsletter'), $row_num);
                continue;
            }

            $email = sanitize_email(trim($row[$email_col]));

            if (!is_email($email)) {
                $errors[] = sprintf(__('Row %d: Invalid email "%s"', 'jan-newsletter'), $row_num, $row[$email_col]);
                $skipped++;
                continue;
            }

            // Check if exists
            $existing = $this->subscriber_repo->find_by_email($email);

            if ($existing) {
                if ($options['skip_existing']) {
                    $skipped++;
                    continue;
                }

                if ($options['update_existing']) {
                    if ($first_name_col !== false && !empty($row[$first_name_col])) {
                        $existing->first_name = sanitize_text_field($row[$first_name_col]);
                    }
                    if ($last_name_col !== false && !empty($row[$last_name_col])) {
                        $existing->last_name = sanitize_text_field($row[$last_name_col]);
                    }

                    $update_data = [];
                    if (isset($existing->first_name)) $update_data['first_name'] = $existing->first_name;
                    if (isset($existing->last_name)) $update_data['last_name'] = $existing->last_name;
                    if (!empty($update_data)) {
                        $this->subscriber_repo->update($existing->id, $update_data);
                    }

                    if ($options['list_id']) {
                        $this->subscriber_repo->add_to_list($existing->id, $options['list_id']);
                    }

                    $updated++;
                    continue;
                }

                $skipped++;
                continue;
            }

            // Create new subscriber
            $subscriber = new Subscriber();
            $subscriber->email = $email;
            $subscriber->status = $options['status'];
            $subscriber->source = 'import';

            if ($first_name_col !== false && isset($row[$first_name_col])) {
                $subscriber->first_name = sanitize_text_field($row[$first_name_col]);
            }
            if ($last_name_col !== false && isset($row[$last_name_col])) {
                $subscriber->last_name = sanitize_text_field($row[$last_name_col]);
            }

            $subscriber_id = $this->subscriber_repo->insert($subscriber);

            if ($options['list_id']) {
                $this->subscriber_repo->add_to_list($subscriber_id, $options['list_id']);
            }

            $imported++;
        }

        fclose($handle);

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: 1: imported count, 2: updated count, 3: skipped count */
                __('Import complete: %1$d imported, %2$d updated, %3$d skipped', 'jan-newsletter'),
                $imported,
                $updated,
                $skipped
            ),
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Export subscribers to CSV
     */
    public function export_csv(array $args = []): string {
        $defaults = [
            'list_id' => null,
            'status' => null,
        ];

        $args = wp_parse_args($args, $defaults);

        // Get subscribers
        $subscribers = $this->subscriber_repo->get_all([
            'page' => 1,
            'per_page' => 100000, // Export all
            'status' => $args['status'],
            'list_id' => $args['list_id'],
        ]);

        // Build CSV
        $output = fopen('php://temp', 'r+');

        // Header
        fputcsv($output, ['email', 'first_name', 'last_name', 'status', 'source', 'created_at']);

        // Data
        foreach ($subscribers as $subscriber) {
            fputcsv($output, [
                $subscriber->email,
                $subscriber->first_name,
                $subscriber->last_name,
                $subscriber->status,
                $subscriber->source,
                $subscriber->created_at,
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export subscribers for download (with headers)
     */
    public function download_csv(array $args = []): void {
        $csv = $this->export_csv($args);

        $filename = 'subscribers-' . date('Y-m-d-His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $csv;
        exit;
    }

    /**
     * Get export URL
     */
    public function get_export_url(array $args = []): string {
        return add_query_arg([
            'action' => 'jan_newsletter_export',
            'list_id' => $args['list_id'] ?? '',
            'status' => $args['status'] ?? '',
            '_wpnonce' => wp_create_nonce('jan_newsletter_export'),
        ], admin_url('admin-ajax.php'));
    }

    /**
     * Find column index by possible names
     */
    private function find_column(array $header, array $names): int|false {
        foreach ($names as $name) {
            $index = array_search($name, $header, true);
            if ($index !== false) {
                return $index;
            }
        }
        return false;
    }
}
