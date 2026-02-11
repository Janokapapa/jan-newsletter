<?php

namespace JanNewsletter\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use JanNewsletter\Plugin;
use JanNewsletter\Mail\Mailer;
use JanNewsletter\Mail\MailgunTransport;
use JanNewsletter\Services\GetResponseService;

/**
 * REST API Controller for Settings
 */
class SettingsController extends WP_REST_Controller {
    protected $namespace = 'jan-newsletter/v1';
    protected $rest_base = 'settings';

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /settings
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_settings'],
                'permission_callback' => [$this, 'admin_permissions_check'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_settings'],
                'permission_callback' => [$this, 'admin_permissions_check'],
            ],
        ]);

        // POST /settings/test-smtp
        register_rest_route($this->namespace, '/' . $this->rest_base . '/test-smtp', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_smtp'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /settings/test-mailgun
        register_rest_route($this->namespace, '/' . $this->rest_base . '/test-mailgun', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_mailgun'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /settings/test-email
        register_rest_route($this->namespace, '/' . $this->rest_base . '/test-email', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_email'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => [
                'email' => [
                    'required' => true,
                    'type' => 'string',
                    'format' => 'email',
                ],
            ],
        ]);

        // POST /settings/generate-api-key
        register_rest_route($this->namespace, '/' . $this->rest_base . '/generate-api-key', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'generate_api_key'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // GET /settings/cron-status
        register_rest_route($this->namespace, '/' . $this->rest_base . '/cron-status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_cron_status'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /settings/getresponse/test
        register_rest_route($this->namespace, '/' . $this->rest_base . '/getresponse/test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_getresponse'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /settings/getresponse/sync-lists
        register_rest_route($this->namespace, '/' . $this->rest_base . '/getresponse/sync-lists', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'sync_getresponse_lists'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /settings/getresponse/sync-subscribers
        register_rest_route($this->namespace, '/' . $this->rest_base . '/getresponse/sync-subscribers', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'sync_getresponse_subscribers'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => [
                'list_id' => [
                    'required' => false,
                    'type' => 'integer',
                ],
                'deep' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        // POST /settings/getresponse/full-sync
        register_rest_route($this->namespace, '/' . $this->rest_base . '/getresponse/full-sync', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'full_sync_getresponse'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // GET /settings/getresponse/campaigns - Get list of GR campaigns
        register_rest_route($this->namespace, '/' . $this->rest_base . '/getresponse/campaigns', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_getresponse_campaigns'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /settings/getresponse/sync-campaign - Sync single campaign/list
        register_rest_route($this->namespace, '/' . $this->rest_base . '/getresponse/sync-campaign', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'sync_getresponse_campaign'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => [
                'campaign_id' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'campaign_name' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'deep' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ]);
    }

    /**
     * Check admin permissions
     */
    public function admin_permissions_check(WP_REST_Request $request): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get all settings
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        $settings = Plugin::get_all_settings();

        // Don't expose password in response (show masked version)
        if (!empty($settings['smtp_password'])) {
            $settings['smtp_password_masked'] = str_repeat('*', strlen($settings['smtp_password']));
            $settings['smtp_password'] = ''; // Clear actual password
        }

        // Don't expose API key fully
        if (!empty($settings['api_key'])) {
            $settings['api_key_masked'] = substr($settings['api_key'], 0, 8) . '...' . substr($settings['api_key'], -4);
        }

        // Don't expose Mailgun API key fully
        if (!empty($settings['mailgun_api_key'])) {
            $settings['mailgun_api_key_masked'] = substr($settings['mailgun_api_key'], 0, 8) . '...' . substr($settings['mailgun_api_key'], -4);
            $settings['mailgun_api_key'] = '';
        }

        // Don't expose GetResponse API key fully
        if (!empty($settings['getresponse_api_key'])) {
            $settings['getresponse_api_key_masked'] = substr($settings['getresponse_api_key'], 0, 8) . '...' . substr($settings['getresponse_api_key'], -4);
            $settings['getresponse_api_key'] = '';
        }

        return new WP_REST_Response($settings);
    }

    /**
     * Update settings
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response {
        $current = Plugin::get_all_settings();
        $body = $request->get_json_params();

        // Define allowed settings and their sanitization
        $allowed = [
            'from_name' => 'sanitize_text_field',
            'from_email' => 'sanitize_email',
            'default_list_id' => 'absint',
            'double_optin' => 'rest_sanitize_boolean',

            'smtp_enabled' => 'rest_sanitize_boolean',
            'smtp_host' => 'sanitize_text_field',
            'smtp_port' => 'absint',
            'smtp_encryption' => fn($v) => in_array($v, ['tls', 'ssl', 'none']) ? $v : 'tls',
            'smtp_auth' => 'rest_sanitize_boolean',
            'smtp_username' => 'sanitize_text_field',
            'smtp_password' => fn($v) => $v, // Don't sanitize password

            'intercept_wp_mail' => 'rest_sanitize_boolean',
            'queue_batch_size' => fn($v) => max(1, min(200, absint($v))),
            'queue_interval' => fn($v) => max(1, min(60, absint($v))),

            'track_opens' => 'rest_sanitize_boolean',
            'track_clicks' => 'rest_sanitize_boolean',
            'one_click_unsubscribe' => 'rest_sanitize_boolean',

            'mailgun_enabled' => 'rest_sanitize_boolean',
            'mailgun_api_key' => fn($v) => $v, // Don't sanitize API key
            'mailgun_domain' => 'sanitize_text_field',
            'mailgun_region' => fn($v) => in_array($v, ['eu', 'us']) ? $v : 'eu',

            'mailgun_signing_key' => 'sanitize_text_field',
            'sendgrid_signing_key' => 'sanitize_text_field',

            'api_enabled' => 'rest_sanitize_boolean',
            'api_key' => fn($v) => $v, // Don't sanitize API key

            'getresponse_api_key' => fn($v) => $v, // Don't sanitize GetResponse API key
        ];

        $updated = $current;

        foreach ($allowed as $key => $sanitizer) {
            if (isset($body[$key])) {
                // Skip empty password (preserve existing)
                if ($key === 'smtp_password' && $body[$key] === '') {
                    continue;
                }
                // Skip empty API key (preserve existing)
                if ($key === 'api_key' && $body[$key] === '') {
                    continue;
                }
                // Skip empty GetResponse API key (preserve existing)
                if ($key === 'getresponse_api_key' && $body[$key] === '') {
                    continue;
                }
                // Skip empty Mailgun API key (preserve existing)
                if ($key === 'mailgun_api_key' && $body[$key] === '') {
                    continue;
                }

                $updated[$key] = is_callable($sanitizer) ? $sanitizer($body[$key]) : call_user_func($sanitizer, $body[$key]);
            }
        }

        update_option('jan_newsletter_settings', $updated);

        // Also update the intercept option separately (used for quick check)
        update_option('jan_newsletter_intercept_wp_mail', $updated['intercept_wp_mail']);

        return new WP_REST_Response([
            'message' => __('Settings saved', 'jan-newsletter'),
            'settings' => $this->mask_sensitive($updated),
        ]);
    }

    /**
     * Test SMTP connection
     */
    public function test_smtp(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $mailer = new Mailer();
        $result = $mailer->test_smtp();

        if ($result['success']) {
            return new WP_REST_Response([
                'success' => true,
                'message' => $result['message'],
            ]);
        }

        return new WP_Error('smtp_test_failed', $result['message'], ['status' => 400]);
    }

    /**
     * Test Mailgun connection
     */
    public function test_mailgun(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $mailgun = new MailgunTransport();
        $result = $mailgun->test();

        if ($result['success']) {
            return new WP_REST_Response([
                'success' => true,
                'message' => $result['message'],
            ]);
        }

        return new WP_Error('mailgun_test_failed', $result['message'], ['status' => 400]);
    }

    /**
     * Send test email
     */
    public function test_email(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $email = $request->get_param('email');

        // Trim whitespace and sanitize
        $email = trim(sanitize_email($email));

        if (empty($email) || !is_email($email)) {
            return new WP_Error(
                'invalid_email',
                sprintf(
                    /* translators: %s: the received email value */
                    __('Invalid email address: "%s"', 'jan-newsletter'),
                    esc_html($request->get_param('email') ?? '')
                ),
                ['status' => 400]
            );
        }

        $mailer = new Mailer();
        $result = $mailer->send_test($email);

        if ($result['success']) {
            return new WP_REST_Response([
                'success' => true,
                'message' => sprintf(
                    /* translators: %s: email address */
                    __('Test email sent to %s', 'jan-newsletter'),
                    $email
                ),
            ]);
        }

        return new WP_Error('send_failed', $result['message'], ['status' => 400]);
    }

    /**
     * Generate new API key
     */
    public function generate_api_key(WP_REST_Request $request): WP_REST_Response {
        $key = 'jn_' . bin2hex(random_bytes(24));

        Plugin::update_option('api_key', $key);

        return new WP_REST_Response([
            'message' => __('API key generated', 'jan-newsletter'),
            'api_key' => $key,
        ]);
    }

    /**
     * Mask sensitive data
     */
    private function mask_sensitive(array $settings): array {
        if (!empty($settings['smtp_password'])) {
            $settings['smtp_password_masked'] = str_repeat('*', strlen($settings['smtp_password']));
            $settings['smtp_password'] = '';
        }

        if (!empty($settings['api_key'])) {
            $settings['api_key_masked'] = substr($settings['api_key'], 0, 8) . '...' . substr($settings['api_key'], -4);
        }

        if (!empty($settings['mailgun_api_key'])) {
            $settings['mailgun_api_key_masked'] = substr($settings['mailgun_api_key'], 0, 8) . '...' . substr($settings['mailgun_api_key'], -4);
            $settings['mailgun_api_key'] = '';
        }

        if (!empty($settings['getresponse_api_key'])) {
            $settings['getresponse_api_key_masked'] = substr($settings['getresponse_api_key'], 0, 8) . '...' . substr($settings['getresponse_api_key'], -4);
            $settings['getresponse_api_key'] = '';
        }

        return $settings;
    }

    /**
     * Test GetResponse connection
     */
    public function test_getresponse(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $gr_service = new GetResponseService();

        if (!$gr_service->is_configured()) {
            return new WP_Error('not_configured', __('GetResponse API key not configured', 'jan-newsletter'), ['status' => 400]);
        }

        $result = $gr_service->test_connection();

        if ($result['success']) {
            return new WP_REST_Response([
                'success' => true,
                'message' => $result['message'],
                'account' => $result['account'] ?? null,
            ]);
        }

        return new WP_Error('connection_failed', $result['message'], ['status' => 400]);
    }

    /**
     * Sync lists from GetResponse
     */
    public function sync_getresponse_lists(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $gr_service = new GetResponseService();

        if (!$gr_service->is_configured()) {
            return new WP_Error('not_configured', __('GetResponse API key not configured', 'jan-newsletter'), ['status' => 400]);
        }

        $result = $gr_service->sync_lists();

        if ($result['success']) {
            return new WP_REST_Response($result);
        }

        return new WP_Error('sync_failed', $result['message'], ['status' => 400]);
    }

    /**
     * Sync subscribers from GetResponse
     */
    public function sync_getresponse_subscribers(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $gr_service = new GetResponseService();

        if (!$gr_service->is_configured()) {
            return new WP_Error('not_configured', __('GetResponse API key not configured', 'jan-newsletter'), ['status' => 400]);
        }

        $list_id = $request->get_param('list_id');
        $deep = (bool) $request->get_param('deep');
        $result = $gr_service->sync_subscribers($list_id ? (int) $list_id : null, $deep);

        if ($result['success']) {
            return new WP_REST_Response($result);
        }

        return new WP_Error('sync_failed', $result['message'], ['status' => 400]);
    }

    /**
     * Full sync from GetResponse (lists + subscribers)
     */
    public function full_sync_getresponse(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $gr_service = new GetResponseService();

        if (!$gr_service->is_configured()) {
            return new WP_Error('not_configured', __('GetResponse API key not configured', 'jan-newsletter'), ['status' => 400]);
        }

        $result = $gr_service->full_sync();

        if ($result['success']) {
            return new WP_REST_Response($result);
        }

        return new WP_Error('sync_failed', $result['message'], ['status' => 400]);
    }

    /**
     * Get GetResponse campaigns (for step-by-step sync UI)
     */
    public function get_getresponse_campaigns(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $gr_service = new GetResponseService();

        if (!$gr_service->is_configured()) {
            return new WP_Error('not_configured', __('GetResponse API key not configured', 'jan-newsletter'), ['status' => 400]);
        }

        $result = $gr_service->get_campaigns();

        if (!$result['success']) {
            return new WP_Error('fetch_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'campaigns' => $result['campaigns'],
            'total' => count($result['campaigns']),
        ]);
    }

    /**
     * Sync single GetResponse campaign (list + subscribers)
     */
    public function sync_getresponse_campaign(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $gr_service = new GetResponseService();

        if (!$gr_service->is_configured()) {
            return new WP_Error('not_configured', __('GetResponse API key not configured', 'jan-newsletter'), ['status' => 400]);
        }

        $campaign_id = $request->get_param('campaign_id');
        $campaign_name = $request->get_param('campaign_name') ?? '';
        $deep = (bool) $request->get_param('deep');

        $result = $gr_service->sync_single_campaign($campaign_id, $campaign_name, $deep);

        if ($result['success']) {
            return new WP_REST_Response($result);
        }

        return new WP_Error('sync_failed', $result['message'], ['status' => 400]);
    }

    /**
     * Get cron status
     */
    public function get_cron_status(WP_REST_Request $request): WP_REST_Response {
        $queue_hook = 'jan_newsletter_process_queue';
        $cleanup_hook = 'jan_newsletter_cleanup_logs';

        $queue_next = wp_next_scheduled($queue_hook);
        $cleanup_next = wp_next_scheduled($cleanup_hook);

        // Check last run from option
        $last_run = get_option('jan_newsletter_cron_last_run', null);

        // Check if DISABLE_WP_CRON is set
        $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        return new WP_REST_Response([
            'queue_scheduled' => $queue_next !== false,
            'queue_next_run' => $queue_next ? gmdate('Y-m-d H:i:s', $queue_next) : null,
            'queue_next_run_relative' => $queue_next ? human_time_diff($queue_next) : null,
            'cleanup_scheduled' => $cleanup_next !== false,
            'cleanup_next_run' => $cleanup_next ? gmdate('Y-m-d H:i:s', $cleanup_next) : null,
            'last_run' => $last_run,
            'wp_cron_disabled' => $wp_cron_disabled,
            'crontab_command' => '*/2 * * * * cd ' . ABSPATH . ' && /usr/bin/php wp-cron.php > /dev/null 2>&1',
        ]);
    }
}
