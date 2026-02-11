<?php

namespace JanNewsletter;

use JanNewsletter\Admin\AdminPage;
use JanNewsletter\Mail\WpMailInterceptor;
use JanNewsletter\Mail\QueueProcessor;
use JanNewsletter\Mail\LogCleaner;
use JanNewsletter\Endpoints\UnsubscribeEndpoint;
use JanNewsletter\Endpoints\ConfirmEndpoint;
use JanNewsletter\Endpoints\TrackingEndpoint;
use JanNewsletter\Integrations\WPFormsIntegration;

/**
 * Main plugin class (Singleton)
 */
class Plugin {
    private static ?Plugin $instance = null;
    private bool $initialized = false;

    private function __construct() {}

    public static function get_instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize all plugin components
     */
    public function init(): void {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        // Run migrations if needed
        $this->maybe_migrate();

        // Admin page
        if (is_admin()) {
            $admin_page = new AdminPage();
            $admin_page->init();
        }

        // wp_mail interceptor (if enabled)
        if ($this->is_wp_mail_intercept_enabled()) {
            $interceptor = new WpMailInterceptor();
            $interceptor->init();
        }

        // Queue processor cron
        $processor = new QueueProcessor();
        $processor->init();

        // Log cleaner cron (1 month retention)
        $log_cleaner = new LogCleaner();
        $log_cleaner->init();

        // Public endpoints
        $this->init_public_endpoints();

        // Integrations
        $this->init_integrations();

        // Enqueue frontend scripts if needed
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
    }

    /**
     * Initialize public endpoints (unsubscribe, confirm, tracking)
     */
    private function init_public_endpoints(): void {
        $unsubscribe = new UnsubscribeEndpoint();
        $unsubscribe->init();

        $confirm = new ConfirmEndpoint();
        $confirm->init();

        $tracking = new TrackingEndpoint();
        $tracking->init();
    }

    /**
     * Initialize third-party integrations
     */
    private function init_integrations(): void {
        // WPForms integration
        if (function_exists('wpforms')) {
            $wpforms = new WPFormsIntegration();
            $wpforms->init();
        }
    }

    /**
     * Run database migrations if version changed
     */
    private function maybe_migrate(): void {
        $db_version = get_option('jan_newsletter_db_version', '0');

        if (version_compare($db_version, '1.1.5', '<')) {
            global $wpdb;
            $table = $wpdb->prefix . 'jan_nl_queue';
            $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN status ENUM('pending','processing','sent','failed','cancelled','paused') DEFAULT 'pending'");
            update_option('jan_newsletter_db_version', JAN_NEWSLETTER_VERSION);
        }
    }

    /**
     * Check if wp_mail interception is enabled
     */
    private function is_wp_mail_intercept_enabled(): bool {
        return (bool) get_option('jan_newsletter_intercept_wp_mail', false);
    }

    /**
     * Enqueue frontend scripts (tracking pixel, etc.)
     */
    public function enqueue_frontend_scripts(): void {
        // Currently no frontend scripts needed
    }

    /**
     * Get plugin option with default
     */
    public static function get_option(string $key, mixed $default = null): mixed {
        $options = get_option('jan_newsletter_settings', []);
        return $options[$key] ?? $default;
    }

    /**
     * Wrap HTML body with email header/footer template
     */
    public static function wrap_with_template(string $body_html): string {
        $header = self::get_option('email_header', '');
        $footer = self::get_option('email_footer', '');
        if (empty($header) && empty($footer)) {
            return $body_html;
        }
        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#1a1a2e;">'
            . '<tr><td align="center"><table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">'
            . '<tr><td>' . $header . '</td></tr>'
            . '<tr><td style="font-family:Arial,Helvetica,sans-serif;">' . $body_html . '</td></tr>'
            . '<tr><td>' . $footer . '</td></tr>'
            . '</table></td></tr></table>';
    }

    /**
     * Update plugin option
     */
    public static function update_option(string $key, mixed $value): bool {
        $options = get_option('jan_newsletter_settings', []);
        $options[$key] = $value;
        return update_option('jan_newsletter_settings', $options);
    }

    /**
     * Get all settings
     */
    public static function get_all_settings(): array {
        return get_option('jan_newsletter_settings', self::get_default_settings());
    }

    /**
     * Default settings
     */
    public static function get_default_settings(): array {
        return [
            // General
            'from_name' => get_bloginfo('name'),
            'from_email' => get_option('admin_email'),
            'default_list_id' => null,
            'double_optin' => true,

            // SMTP
            'smtp_enabled' => false,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls', // tls, ssl, none
            'smtp_auth' => true,
            'smtp_username' => '',
            'smtp_password' => '',

            // Queue
            'intercept_wp_mail' => false,
            'queue_batch_size' => 50,
            'queue_interval' => 2, // minutes

            // Tracking
            'track_opens' => true,
            'track_clicks' => true,
            'one_click_unsubscribe' => true,

            // Mailgun API
            'mailgun_enabled' => false,
            'mailgun_api_key' => '',
            'mailgun_domain' => '',
            'mailgun_region' => 'eu', // eu | us

            // Webhooks
            'mailgun_signing_key' => '',
            'sendgrid_signing_key' => '',

            // API
            'api_enabled' => false,
            'api_key' => '',

            // Email Template
            'email_header' => '',
            'email_footer' => '',
        ];
    }
}
