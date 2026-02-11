<?php
/**
 * Plugin Name: Mail and Newsletter
 * Plugin URI: https://jandev.eu/mail-and-newsletter
 * Description: Complete email marketing platform with SMTP queue, subscriber management, campaign editor, and GetResponse sync.
 * Version: 1.1.8
 * Author: Jan Dev
 * Author URI: https://jandev.eu
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jan-newsletter
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

namespace JanNewsletter;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('JAN_NEWSLETTER_VERSION', '1.1.7');
define('JAN_NEWSLETTER_FILE', __FILE__);
define('JAN_NEWSLETTER_PATH', plugin_dir_path(__FILE__));
define('JAN_NEWSLETTER_URL', plugin_dir_url(__FILE__));
define('JAN_NEWSLETTER_BASENAME', plugin_basename(__FILE__));

// Composer autoloader
if (file_exists(JAN_NEWSLETTER_PATH . 'vendor/autoload.php')) {
    require_once JAN_NEWSLETTER_PATH . 'vendor/autoload.php';
}

// Manual autoloader for development
spl_autoload_register(function ($class) {
    $prefix = 'JanNewsletter\\';
    $base_dir = JAN_NEWSLETTER_PATH . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Add custom cron interval for queue processing
 */
add_filter('cron_schedules', function ($schedules) {
    $schedules['jan_newsletter_interval'] = [
        'interval' => 2 * 60, // 2 minutes
        'display' => __('Every 2 minutes', 'jan-newsletter'),
    ];
    return $schedules;
});

/**
 * Initialize the plugin
 */
function init(): void {
    // Load text domain for translations
    load_plugin_textdomain(
        'jan-newsletter',
        false,
        dirname(JAN_NEWSLETTER_BASENAME) . '/languages'
    );

    // Initialize main plugin class
    $plugin = Plugin::get_instance();
    $plugin->init();

    // Ensure cron is scheduled (handles cases where plugin was active before cron code was added)
    ensure_cron_scheduled();
}
add_action('plugins_loaded', __NAMESPACE__ . '\\init');

/**
 * Ensure cron jobs are scheduled
 * This runs on every page load to catch cases where cron was not scheduled
 */
function ensure_cron_scheduled(): void {
    if (!wp_next_scheduled('jan_newsletter_process_queue')) {
        wp_schedule_event(time(), 'jan_newsletter_interval', 'jan_newsletter_process_queue');
    }

    if (!wp_next_scheduled('jan_newsletter_cleanup_logs')) {
        wp_schedule_event(time(), 'daily', 'jan_newsletter_cleanup_logs');
    }
}

/**
 * Activation hook
 */
function activate(): void {
    Activator::activate();
}
register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate');

/**
 * Deactivation hook
 */
function deactivate(): void {
    Activator::deactivate();
}
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactivate');

/**
 * AJAX handler for async queue processing (non-blocking loopback)
 */
function handle_async_queue_process(): void {
    $token = $_POST['token'] ?? '';
    if (!hash_equals(wp_hash('jan_nl_process_queue'), $token)) {
        wp_die('Unauthorized', 403);
    }

    $processor = new Mail\QueueProcessor();
    $processor->process();
    wp_die('', 200);
}
add_action('wp_ajax_jan_nl_process_queue', __NAMESPACE__ . '\\handle_async_queue_process');
add_action('wp_ajax_nopriv_jan_nl_process_queue', __NAMESPACE__ . '\\handle_async_queue_process');

/**
 * Register REST API routes
 */
function register_rest_routes(): void {
    $controllers = [
        new Api\SubscribersController(),
        new Api\ListsController(),
        new Api\CampaignsController(),
        new Api\QueueController(),
        new Api\StatsController(),
        new Api\SettingsController(),
        new Api\WebhooksController(),
    ];

    foreach ($controllers as $controller) {
        $controller->register_routes();
    }
}
add_action('rest_api_init', __NAMESPACE__ . '\\register_rest_routes');
