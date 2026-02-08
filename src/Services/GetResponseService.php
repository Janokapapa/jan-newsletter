<?php

namespace JanNewsletter\Services;

use JanNewsletter\Repositories\ListRepository;
use JanNewsletter\Repositories\SubscriberRepository;
use JanNewsletter\Plugin;

/**
 * GetResponse API Integration Service
 */
class GetResponseService {
    private string $api_key;
    private string $api_url = 'https://api.getresponse.com/v3';
    private ListRepository $list_repo;
    private SubscriberRepository $subscriber_repo;

    public function __construct() {
        $this->api_key = Plugin::get_option('getresponse_api_key', '');
        $this->list_repo = new ListRepository();
        $this->subscriber_repo = new SubscriberRepository();
    }

    /**
     * Check if API key is configured
     */
    public function is_configured(): bool {
        return !empty($this->api_key);
    }

    /**
     * Set API key (for testing connection)
     */
    public function set_api_key(string $key): void {
        $this->api_key = $key;
    }

    /**
     * Make API request to GetResponse
     */
    private function api_request(string $endpoint, string $method = 'GET', array $data = []): array {
        if (empty($this->api_key)) {
            return ['success' => false, 'message' => __('GetResponse API key not configured', 'jan-newsletter')];
        }

        $url = $this->api_url . $endpoint;

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'X-Auth-Token' => 'api-key ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($data) && in_array($method, ['POST', 'PUT'])) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code >= 400) {
            $error_message = $decoded['message'] ?? __('API request failed', 'jan-newsletter');
            return [
                'success' => false,
                'message' => $error_message,
                'code' => $code,
            ];
        }

        return [
            'success' => true,
            'data' => $decoded,
        ];
    }

    /**
     * Test API connection
     */
    public function test_connection(): array {
        $result = $this->api_request('/accounts');

        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %s: account email */
                __('Connected to GetResponse account: %s', 'jan-newsletter'),
                $result['data']['email'] ?? 'Unknown'
            ),
            'account' => $result['data'],
        ];
    }

    /**
     * Get all campaigns (lists) from GetResponse
     */
    public function get_campaigns(): array {
        $result = $this->api_request('/campaigns?perPage=1000');

        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'campaigns' => $result['data'],
        ];
    }

    /**
     * Get contacts from a specific campaign
     */
    public function get_contacts(string $campaign_id, int $page = 1, int $per_page = 1000): array {
        // Request contacts - customFieldValues are included automatically if contact has any
        $result = $this->api_request("/contacts?query[campaignId]={$campaign_id}&page={$page}&perPage={$per_page}");

        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'contacts' => $result['data'],
        ];
    }

    /**
     * Get single contact with full details (deep fetch)
     * Returns ALL data including customFieldValues, geolocation, tags
     */
    public function get_contact(string $contact_id): array {
        $result = $this->api_request("/contacts/{$contact_id}");

        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'contact' => $result['data'],
        ];
    }

    /**
     * Sync campaigns (lists) from GetResponse to local lists
     */
    public function sync_lists(): array {
        $result = $this->get_campaigns();

        if (!$result['success']) {
            return $result;
        }

        $campaigns = $result['campaigns'];
        $synced = 0;
        $created = 0;
        $updated = 0;

        foreach ($campaigns as $campaign) {
            $gr_id = $campaign['campaignId'];
            $name = $campaign['name'];
            $slug = sanitize_title($name);

            // Check if list already exists by getresponse_id
            $existing = $this->list_repo->find_by_meta('getresponse_id', $gr_id);

            if ($existing) {
                // Update existing list
                $this->list_repo->update_by_id($existing->id, [
                    'name' => $name,
                    'description' => $campaign['description'] ?? '',
                ]);
                $updated++;
            } else {
                // Check by slug to avoid duplicates
                $by_slug = $this->list_repo->find_by_slug($slug);

                if ($by_slug) {
                    // Update existing and link to GetResponse
                    $this->list_repo->update_by_id($by_slug->id, [
                        'name' => $name,
                        'description' => $campaign['description'] ?? '',
                    ]);
                    $this->list_repo->update_meta($by_slug->id, 'getresponse_id', $gr_id);
                    $updated++;
                } else {
                    // Create new list
                    $list_id = $this->list_repo->create([
                        'name' => $name,
                        'slug' => $slug,
                        'description' => $campaign['description'] ?? '',
                        'double_optin' => 0,
                    ]);

                    if ($list_id) {
                        $this->list_repo->update_meta($list_id, 'getresponse_id', $gr_id);
                        $created++;
                    }
                }
            }

            $synced++;
        }

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %1$d: synced, %2$d: created, %3$d: updated */
                __('Synced %1$d lists (%2$d created, %3$d updated)', 'jan-newsletter'),
                $synced,
                $created,
                $updated
            ),
            'synced' => $synced,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * Sync subscribers from GetResponse campaigns to local lists
     * @param int|null $list_id Specific list to sync, or null for all
     * @param bool $deep If true, fetch each contact individually for full data
     */
    public function sync_subscribers(?int $list_id = null, bool $deep = false): array {
        // If specific list, sync only that one
        if ($list_id) {
            $lists = [$this->list_repo->find($list_id)];
            if (!$lists[0]) {
                return ['success' => false, 'message' => __('List not found', 'jan-newsletter')];
            }
        } else {
            // Sync all lists that have getresponse_id
            $lists = $this->list_repo->get_all_with_meta('getresponse_id');
        }

        $total_synced = 0;
        $total_created = 0;
        $total_updated = 0;
        $errors = [];

        foreach ($lists as $list) {
            $gr_id = $this->list_repo->get_meta($list->id, 'getresponse_id');

            if (!$gr_id) {
                continue;
            }

            $page = 1;
            $has_more = true;

            while ($has_more) {
                $result = $this->get_contacts($gr_id, $page);

                if (!$result['success']) {
                    $errors[] = sprintf(
                        /* translators: %1$s: list name, %2$s: error message */
                        __('Failed to fetch contacts for list "%1$s": %2$s', 'jan-newsletter'),
                        $list->name,
                        $result['message']
                    );
                    break;
                }

                $contacts = $result['contacts'];

                if (empty($contacts)) {
                    $has_more = false;
                    continue;
                }

                foreach ($contacts as $contact) {
                    // Deep sync: fetch full contact data individually
                    $contact_data = $contact;
                    if ($deep && !empty($contact['contactId'])) {
                        $full_contact = $this->get_contact($contact['contactId']);
                        if ($full_contact['success']) {
                            $contact_data = $full_contact['contact'];
                        }
                    }

                    $sync_result = $this->sync_contact($contact_data, $list->id);

                    if ($sync_result['created']) {
                        $total_created++;
                    } elseif ($sync_result['updated']) {
                        $total_updated++;
                    }

                    $total_synced++;
                }

                // GetResponse returns max 1000 per page
                if (count($contacts) < 1000) {
                    $has_more = false;
                } else {
                    $page++;
                }
            }
        }

        $message = sprintf(
            /* translators: %1$d: synced, %2$d: created, %3$d: updated */
            __('Synced %1$d subscribers (%2$d created, %3$d updated)', 'jan-newsletter'),
            $total_synced,
            $total_created,
            $total_updated
        );

        if (!empty($errors)) {
            $message .= "\n" . implode("\n", $errors);
        }

        return [
            'success' => empty($errors),
            'message' => $message,
            'synced' => $total_synced,
            'created' => $total_created,
            'updated' => $total_updated,
            'errors' => $errors,
        ];
    }

    /**
     * Sync single contact to local subscriber
     * Stores ALL GetResponse fields in subscriber_meta table for fast searching
     */
    private function sync_contact(array $contact, int $list_id): array {
        $email = $contact['email'] ?? '';

        if (empty($email)) {
            return ['created' => false, 'updated' => false];
        }

        // Parse name
        $name = $contact['name'] ?? '';
        $name_parts = explode(' ', $name, 2);
        $first_name = $name_parts[0] ?? '';
        $last_name = $name_parts[1] ?? '';

        // Build meta array with ALL GetResponse fields
        $meta = [
            // Core GetResponse fields
            'gr_contact_id' => $contact['contactId'] ?? null,
            'gr_origin' => $contact['origin'] ?? null,
            'gr_note' => $contact['note'] ?? null,
            'gr_day_of_cycle' => $contact['dayOfCycle'] ?? null,
            'gr_changed_on' => $contact['changedOn'] ?? null,
            'gr_created_on' => $contact['createdOn'] ?? null,
            'gr_timezone' => $contact['timeZone'] ?? null,
            'gr_scoring' => $contact['scoring'] ?? null,
            'gr_engagement_score' => $contact['engagementScore'] ?? null,
            'gr_campaign_id' => $contact['campaign']['campaignId'] ?? null,
            'gr_campaign_name' => $contact['campaign']['name'] ?? null,
        ];

        // Parse GetResponse custom field values (birthdate, city, phone, etc.)
        if (!empty($contact['customFieldValues'])) {
            foreach ($contact['customFieldValues'] as $field) {
                $field_name = $field['name'] ?? '';
                // Value can be array or string
                $field_value = is_array($field['value'] ?? null)
                    ? ($field['value'][0] ?? implode(', ', $field['value']))
                    : ($field['value'] ?? '');
                if ($field_name) {
                    $meta['gr_' . $field_name] = $field_value;
                }
            }
        }

        // Store geolocation data (from deep sync)
        if (!empty($contact['geolocation'])) {
            $geo = $contact['geolocation'];
            $meta['gr_geo_country'] = $geo['country'] ?? null;
            $meta['gr_geo_country_code'] = $geo['countryCode'] ?? null;
            $meta['gr_geo_city'] = $geo['city'] ?? null;
            $meta['gr_geo_region'] = $geo['region'] ?? null;
            $meta['gr_geo_postal_code'] = $geo['postalCode'] ?? null;
            $meta['gr_geo_latitude'] = $geo['latitude'] ?? null;
            $meta['gr_geo_longitude'] = $geo['longitude'] ?? null;
        }

        // Store tags (from deep sync)
        if (!empty($contact['tags'])) {
            $tag_names = array_map(fn($tag) => $tag['name'] ?? $tag['tagId'] ?? '', $contact['tags']);
            $meta['gr_tags'] = implode(', ', array_filter($tag_names));
        }

        // Remove null/empty values but keep 0 and false
        $meta = array_filter($meta, fn($v) => $v !== null && $v !== '');

        // Get IP address
        $ip_address = $contact['ipAddress'] ?? '';

        // Check if subscriber exists
        $existing = $this->subscriber_repo->find_by_email($email);

        if ($existing) {
            // Update subscriber
            $this->subscriber_repo->update($existing->id, [
                'first_name' => $first_name ?: $existing->first_name,
                'last_name' => $last_name ?: $existing->last_name,
                'ip_address' => $ip_address ?: $existing->ip_address,
            ]);

            // Save all meta fields
            $this->subscriber_repo->set_meta_batch($existing->id, $meta);

            // Add to list if not already
            $this->subscriber_repo->add_to_list($existing->id, $list_id);

            return ['created' => false, 'updated' => true];
        } else {
            // Create new subscriber
            $subscriber_id = $this->subscriber_repo->create([
                'email' => $email,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'status' => 'subscribed',
                'source' => 'getresponse',
                'ip_address' => $ip_address,
            ]);

            if ($subscriber_id) {
                // Save all meta fields
                $this->subscriber_repo->set_meta_batch($subscriber_id, $meta);

                // Add to list
                $this->subscriber_repo->add_to_list($subscriber_id, $list_id);
                return ['created' => true, 'updated' => false];
            }
        }

        return ['created' => false, 'updated' => false];
    }

    /**
     * Sync single campaign (create/update list + sync all subscribers)
     * @param string $campaign_id GetResponse campaign ID
     * @param string $campaign_name Campaign name (optional)
     * @param bool $deep If true, fetch each contact individually for full data
     */
    public function sync_single_campaign(string $campaign_id, string $campaign_name = '', bool $deep = false): array {
        // First, create or update the local list
        $slug = sanitize_title($campaign_name ?: $campaign_id);

        // Check if list already exists by getresponse_id
        $existing = $this->list_repo->find_by_meta('getresponse_id', $campaign_id);
        $list_id = null;

        if ($existing) {
            $list_id = $existing->id;
            if ($campaign_name) {
                $this->list_repo->update_by_id($list_id, ['name' => $campaign_name]);
            }
        } else {
            // Check by slug
            $by_slug = $this->list_repo->find_by_slug($slug);
            if ($by_slug) {
                $list_id = $by_slug->id;
                $this->list_repo->update_meta($list_id, 'getresponse_id', $campaign_id);
            } else {
                // Create new list
                $list_id = $this->list_repo->create([
                    'name' => $campaign_name ?: $campaign_id,
                    'slug' => $slug,
                    'description' => '',
                    'double_optin' => 0,
                ]);
                if ($list_id) {
                    $this->list_repo->update_meta($list_id, 'getresponse_id', $campaign_id);
                }
            }
        }

        if (!$list_id) {
            return [
                'success' => false,
                'message' => __('Failed to create local list', 'jan-newsletter'),
            ];
        }

        // Now sync all subscribers from this campaign
        $total_synced = 0;
        $total_created = 0;
        $total_updated = 0;
        $page = 1;
        $has_more = true;

        while ($has_more) {
            $result = $this->get_contacts($campaign_id, $page);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => $result['message'],
                ];
            }

            $contacts = $result['contacts'];

            if (empty($contacts)) {
                $has_more = false;
                continue;
            }

            foreach ($contacts as $contact) {
                // Deep sync: fetch full contact data individually
                $contact_data = $contact;
                if ($deep && !empty($contact['contactId'])) {
                    $full_contact = $this->get_contact($contact['contactId']);
                    if ($full_contact['success']) {
                        $contact_data = $full_contact['contact'];
                    }
                }

                $sync_result = $this->sync_contact($contact_data, $list_id);

                if ($sync_result['created']) {
                    $total_created++;
                } elseif ($sync_result['updated']) {
                    $total_updated++;
                }

                $total_synced++;
            }

            // GetResponse returns max 1000 per page
            if (count($contacts) < 1000) {
                $has_more = false;
            } else {
                $page++;
            }
        }

        return [
            'success' => true,
            'message' => sprintf(
                __('Synced %d subscribers (%d new, %d updated)', 'jan-newsletter'),
                $total_synced,
                $total_created,
                $total_updated
            ),
            'list_id' => $list_id,
            'synced' => $total_synced,
            'created' => $total_created,
            'updated' => $total_updated,
        ];
    }

    /**
     * Full sync: lists + subscribers
     */
    public function full_sync(): array {
        // First sync lists
        $lists_result = $this->sync_lists();

        if (!$lists_result['success']) {
            return $lists_result;
        }

        // Then sync subscribers
        $subscribers_result = $this->sync_subscribers();

        return [
            'success' => $subscribers_result['success'],
            'message' => $lists_result['message'] . "\n" . $subscribers_result['message'],
            'lists' => [
                'synced' => $lists_result['synced'],
                'created' => $lists_result['created'],
                'updated' => $lists_result['updated'],
            ],
            'subscribers' => [
                'synced' => $subscribers_result['synced'],
                'created' => $subscribers_result['created'],
                'updated' => $subscribers_result['updated'],
            ],
        ];
    }
}
