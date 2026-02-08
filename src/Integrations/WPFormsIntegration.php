<?php

namespace JanNewsletter\Integrations;

use JanNewsletter\Repositories\SubscriberRepository;
use JanNewsletter\Repositories\ListRepository;

/**
 * WPForms Integration - Auto-subscribe form submissions
 */
class WPFormsIntegration {
    private SubscriberRepository $subscriber_repo;
    private ListRepository $list_repo;

    public function __construct() {
        $this->subscriber_repo = new SubscriberRepository();
        $this->list_repo = new ListRepository();
    }

    /**
     * Initialize the integration
     */
    public function init(): void {
        // Hook into WPForms submission
        add_action('wpforms_process_complete', [$this, 'handle_submission'], 10, 4);
    }

    /**
     * Handle WPForms submission
     */
    public function handle_submission(array $fields, array $entry, array $form_data, int $entry_id): void {
        // Extract email from form fields
        $email = $this->find_field_value($fields, 'email');

        if (empty($email) || !is_email($email)) {
            return;
        }

        // Extract name fields
        $name_data = $this->extract_name($fields);
        $first_name = $name_data['first_name'];
        $last_name = $name_data['last_name'];

        // Get or create list based on form name
        $form_name = $form_data['settings']['form_title'] ?? 'WPForms';
        $list_id = $this->get_or_create_list($form_name);

        if (!$list_id) {
            error_log('[Mail Newsletter] Failed to create list for form: ' . $form_name);
            return;
        }

        // Check if subscriber exists
        $existing = $this->subscriber_repo->find_by_email($email);

        if ($existing) {
            // Update existing subscriber if name fields are provided
            $update_data = [];
            if (!empty($first_name) && empty($existing->first_name)) {
                $update_data['first_name'] = $first_name;
            }
            if (!empty($last_name) && empty($existing->last_name)) {
                $update_data['last_name'] = $last_name;
            }
            if (!empty($update_data)) {
                $this->subscriber_repo->update($existing->id, $update_data);
            }

            // Add to list if not already
            $this->subscriber_repo->add_to_list($existing->id, $list_id);

            // Store form submission meta
            $this->subscriber_repo->update_meta($existing->id, 'wpforms_last_form', $form_name);
            $this->subscriber_repo->update_meta($existing->id, 'wpforms_last_entry_id', $entry_id);
        } else {
            // Create new subscriber
            $subscriber_id = $this->subscriber_repo->create([
                'email' => $email,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'status' => 'subscribed',
                'source' => 'wpforms',
                'ip_address' => $this->get_client_ip(),
            ]);

            if ($subscriber_id) {
                // Add to list
                $this->subscriber_repo->add_to_list($subscriber_id, $list_id);

                // Store form submission meta
                $this->subscriber_repo->update_meta($subscriber_id, 'wpforms_form_id', $form_data['id']);
                $this->subscriber_repo->update_meta($subscriber_id, 'wpforms_form_name', $form_name);
                $this->subscriber_repo->update_meta($subscriber_id, 'wpforms_entry_id', $entry_id);
            }
        }
    }

    /**
     * Find field value by type
     */
    private function find_field_value(array $fields, string $type): string {
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === $type) {
                return trim($field['value'] ?? '');
            }
        }
        return '';
    }

    /**
     * Extract first and last name from form fields
     */
    private function extract_name(array $fields): array {
        $first_name = '';
        $last_name = '';

        foreach ($fields as $field) {
            $type = $field['type'] ?? '';
            $label = strtolower($field['name'] ?? '');
            $value = trim($field['value'] ?? '');

            // Handle WPForms native name field
            if ($type === 'name') {
                if (!empty($field['first'])) {
                    $first_name = trim($field['first']);
                    $last_name = trim($field['last'] ?? '');
                } elseif (!empty($value)) {
                    $parts = explode(' ', $value, 2);
                    $first_name = $parts[0] ?? '';
                    $last_name = $parts[1] ?? '';
                }
                break;
            }

            // Handle text field labeled "Name" or similar
            if ($type === 'text' && !empty($value)) {
                if ($label === 'name' || strpos($label, 'name') === 0) {
                    $parts = explode(' ', $value, 2);
                    $first_name = $parts[0] ?? '';
                    $last_name = $parts[1] ?? '';
                }
                if (strpos($label, 'first') !== false && strpos($label, 'name') !== false) {
                    $first_name = $value;
                }
                if (strpos($label, 'last') !== false && strpos($label, 'name') !== false) {
                    $last_name = $value;
                }
            }
        }

        return [
            'first_name' => $first_name,
            'last_name' => $last_name,
        ];
    }

    /**
     * Get or create list based on form name
     */
    private function get_or_create_list(string $form_name): ?int {
        $slug = sanitize_title($form_name);

        // Check if list exists
        $existing = $this->list_repo->find_by_slug($slug);

        if ($existing) {
            return (int) $existing->id;
        }

        // Create new list
        $list_id = $this->list_repo->create([
            'name' => $form_name,
            'slug' => $slug,
            'description' => sprintf('Auto-created from WPForms: %s', $form_name),
            'double_optin' => 0,
        ]);

        if ($list_id) {
            $this->list_repo->update_meta($list_id, 'wpforms_source', 'auto');
        }

        return $list_id;
    }

    /**
     * Get client IP address
     */
    private function get_client_ip(): string {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '';
    }
}
