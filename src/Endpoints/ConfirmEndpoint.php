<?php

namespace JanNewsletter\Endpoints;

use JanNewsletter\Services\SubscriberService;

/**
 * Confirmation endpoint for double opt-in
 */
class ConfirmEndpoint {
    private SubscriberService $service;

    public function __construct() {
        $this->service = new SubscriberService();
    }

    /**
     * Initialize endpoint
     */
    public function init(): void {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_action('template_redirect', [$this, 'handle_request']);
    }

    /**
     * Add rewrite rules
     */
    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^jan-newsletter/confirm/([^/]+)/?$',
            'index.php?jan_newsletter_action=confirm&token=$matches[1]',
            'top'
        );

        add_rewrite_tag('%jan_newsletter_action%', '([^&]+)');
        add_rewrite_tag('%token%', '([^&]+)');
    }

    /**
     * Handle confirmation request
     */
    public function handle_request(): void {
        $action = get_query_var('jan_newsletter_action');

        if ($action !== 'confirm') {
            return;
        }

        $token = get_query_var('token');

        if (empty($token)) {
            $this->render_error(__('Invalid confirmation link', 'jan-newsletter'));
            return;
        }

        $result = $this->service->confirm($token);

        if ($result['success']) {
            $this->render_success();
        } else {
            $this->render_error($result['message']);
        }
    }

    /**
     * Render success message
     */
    private function render_success(): void {
        $site_name = esc_html(get_bloginfo('name'));

        header('Content-Type: text/html; charset=utf-8');

        $heading = esc_html__('Subscription Confirmed!', 'jan-newsletter');
        $description = esc_html__('Thank you for confirming your subscription. You will now receive our updates.', 'jan-newsletter');
        $title = esc_html__('Confirmed', 'jan-newsletter');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - {$site_name}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 400px; width: 100%; text-align: center; }
        h1 { font-size: 24px; margin-bottom: 16px; color: #059669; }
        p { color: #6b7280; margin-bottom: 24px; line-height: 1.5; }
        .icon { font-size: 48px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">✓</div>
        <h1>{$heading}</h1>
        <p>{$description}</p>
    </div>
</body>
</html>
HTML;
        exit;
    }

    /**
     * Render error message
     */
    private function render_error(string $message): void {
        $site_name = esc_html(get_bloginfo('name'));

        header('Content-Type: text/html; charset=utf-8');

        $heading = esc_html__('Confirmation Failed', 'jan-newsletter');
        $title = esc_html__('Error', 'jan-newsletter');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - {$site_name}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 400px; width: 100%; text-align: center; }
        h1 { font-size: 24px; margin-bottom: 16px; color: #dc2626; }
        p { color: #6b7280; margin-bottom: 24px; line-height: 1.5; }
        .icon { font-size: 48px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">✕</div>
        <h1>{$heading}</h1>
        <p>{$message}</p>
    </div>
</body>
</html>
HTML;
        exit;
    }
}
