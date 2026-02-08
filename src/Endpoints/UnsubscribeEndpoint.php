<?php

namespace JanNewsletter\Endpoints;

use JanNewsletter\Services\SubscriberService;
use JanNewsletter\Repositories\StatsRepository;

/**
 * Unsubscribe endpoint
 */
class UnsubscribeEndpoint {
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
            '^jan-newsletter/unsubscribe/([^/]+)/([^/]+)/?$',
            'index.php?jan_newsletter_action=unsubscribe&email=$matches[1]&token=$matches[2]',
            'top'
        );

        add_rewrite_tag('%jan_newsletter_action%', '([^&]+)');
        add_rewrite_tag('%email%', '([^&]+)');
        add_rewrite_tag('%token%', '([^&]+)');
    }

    /**
     * Handle unsubscribe request
     */
    public function handle_request(): void {
        $action = get_query_var('jan_newsletter_action');

        if ($action !== 'unsubscribe') {
            return;
        }

        $email = urldecode(get_query_var('email'));
        $token = get_query_var('token');

        if (empty($email)) {
            $this->render_error(__('Invalid unsubscribe link', 'jan-newsletter'));
            return;
        }

        // If POST request, process unsubscribe
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
            $result = $this->service->unsubscribe($email, $token);

            if ($result['success']) {
                // Record unsubscribe stat if campaign context exists
                $campaign_id = isset($_GET['campaign']) ? (int) $_GET['campaign'] : 0;
                if ($campaign_id && $result['subscriber']) {
                    $stats_repo = new StatsRepository();
                    $stats_repo->record([
                        'campaign_id' => $campaign_id,
                        'subscriber_id' => $result['subscriber']->id,
                        'email' => $email,
                        'event_type' => 'unsubscribe',
                        'ip_address' => $this->get_client_ip(),
                    ]);
                }

                $this->render_success();
            } else {
                $this->render_error($result['message']);
            }
            return;
        }

        // Show confirmation form
        $this->render_form($email);
    }

    /**
     * Render unsubscribe form
     */
    private function render_form(string $email): void {
        $site_name = esc_html(get_bloginfo('name'));
        $masked_email = $this->mask_email($email);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->get_template('form', [
            'site_name' => $site_name,
            'masked_email' => $masked_email,
        ]);
        exit;
    }

    /**
     * Render success message
     */
    private function render_success(): void {
        $site_name = esc_html(get_bloginfo('name'));

        header('Content-Type: text/html; charset=utf-8');
        echo $this->get_template('success', [
            'site_name' => $site_name,
        ]);
        exit;
    }

    /**
     * Render error message
     */
    private function render_error(string $message): void {
        $site_name = esc_html(get_bloginfo('name'));

        header('Content-Type: text/html; charset=utf-8');
        echo $this->get_template('error', [
            'site_name' => $site_name,
            'message' => esc_html($message),
        ]);
        exit;
    }

    /**
     * Get template HTML
     */
    private function get_template(string $type, array $vars): string {
        $title = match($type) {
            'form' => __('Unsubscribe', 'jan-newsletter'),
            'success' => __('Unsubscribed', 'jan-newsletter'),
            'error' => __('Error', 'jan-newsletter'),
        };

        $content = match($type) {
            'form' => $this->get_form_content($vars),
            'success' => $this->get_success_content($vars),
            'error' => $this->get_error_content($vars),
        };

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - {$vars['site_name']}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 400px; width: 100%; text-align: center; }
        h1 { font-size: 24px; margin-bottom: 16px; color: #111; }
        p { color: #6b7280; margin-bottom: 24px; line-height: 1.5; }
        .email { font-weight: 600; color: #111; }
        button { background: #dc2626; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; margin-bottom: 12px; }
        button:hover { background: #b91c1c; }
        .cancel { background: #e5e7eb; color: #374151; }
        .cancel:hover { background: #d1d5db; }
        .success { color: #059669; }
        .error { color: #dc2626; }
        .icon { font-size: 48px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="container">
        {$content}
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get form content
     */
    private function get_form_content(array $vars): string {
        $confirm_text = esc_html__('Unsubscribe', 'jan-newsletter');
        $cancel_text = esc_html__('Cancel', 'jan-newsletter');
        $heading = esc_html__('Unsubscribe from our mailing list?', 'jan-newsletter');
        $description = sprintf(
            /* translators: %s: masked email address */
            esc_html__('You are about to unsubscribe %s from all future emails.', 'jan-newsletter'),
            '<span class="email">' . esc_html($vars['masked_email']) . '</span>'
        );

        return <<<HTML
<div class="icon">📧</div>
<h1>{$heading}</h1>
<p>{$description}</p>
<form method="post">
    <button type="submit" name="confirm" value="1">{$confirm_text}</button>
</form>
<a href="javascript:history.back()"><button type="button" class="cancel">{$cancel_text}</button></a>
HTML;
    }

    /**
     * Get success content
     */
    private function get_success_content(array $vars): string {
        $heading = esc_html__('Successfully Unsubscribed', 'jan-newsletter');
        $description = esc_html__('You have been removed from our mailing list and will no longer receive emails from us.', 'jan-newsletter');

        return <<<HTML
<div class="icon">✓</div>
<h1 class="success">{$heading}</h1>
<p>{$description}</p>
HTML;
    }

    /**
     * Get error content
     */
    private function get_error_content(array $vars): string {
        $heading = esc_html__('Error', 'jan-newsletter');

        return <<<HTML
<div class="icon">✕</div>
<h1 class="error">{$heading}</h1>
<p>{$vars['message']}</p>
HTML;
    }

    /**
     * Mask email for display
     */
    private function mask_email(string $email): string {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***@***';
        }

        $name = $parts[0];
        $domain = $parts[1];

        $masked_name = strlen($name) > 2
            ? substr($name, 0, 2) . str_repeat('*', strlen($name) - 2)
            : str_repeat('*', strlen($name));

        return $masked_name . '@' . $domain;
    }

    /**
     * Get client IP
     */
    private function get_client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
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
