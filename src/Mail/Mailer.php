<?php

namespace JanNewsletter\Mail;

use JanNewsletter\Plugin;
use JanNewsletter\Repositories\QueueRepository;

/**
 * Main mailer interface
 */
class Mailer {
    private SmtpTransport $smtp;
    private MailgunTransport $mailgun;
    private QueueRepository $queue_repo;

    public function __construct() {
        $this->smtp = new SmtpTransport();
        $this->mailgun = new MailgunTransport();
        $this->queue_repo = new QueueRepository();
    }

    /**
     * Get active transport type
     * Priority: Mailgun API > SMTP > wp_mail
     */
    private function get_transport(): string {
        if (Plugin::get_option('mailgun_enabled', false)) {
            return 'mailgun';
        }
        if (Plugin::get_option('smtp_enabled', false)) {
            return 'smtp';
        }
        return 'wp_mail';
    }

    /**
     * Send email immediately (bypassing queue)
     */
    public function send_now(
        string $to,
        string $subject,
        string $body_html,
        ?string $body_text = null,
        string $from_email = '',
        string $from_name = '',
        array $headers = [],
        array $attachments = []
    ): array {
        $transport = $this->get_transport();

        if ($transport === 'wp_mail') {
            return $this->send_via_wp_mail($to, $subject, $body_html, $headers, $attachments);
        }

        if ($transport === 'mailgun') {
            $result = $this->mailgun->send(
                $to, $subject, $body_html, $body_text,
                $from_email, $from_name, $headers, $attachments
            );

            if ($result) {
                return [
                    'success' => true,
                    'message' => __('Email sent via Mailgun', 'jan-newsletter'),
                    'response' => $this->mailgun->get_last_response(),
                ];
            }

            return [
                'success' => false,
                'message' => $this->mailgun->get_last_error(),
            ];
        }

        // SMTP
        $result = $this->smtp->send(
            $to, $subject, $body_html, $body_text,
            $from_email, $from_name, $headers, $attachments
        );

        if ($result) {
            return [
                'success' => true,
                'message' => __('Email sent successfully', 'jan-newsletter'),
                'response' => $this->smtp->get_last_response(),
            ];
        }

        return [
            'success' => false,
            'message' => $this->smtp->get_last_error(),
        ];
    }

    /**
     * Queue email for sending
     */
    public function queue(
        string $to,
        string $subject,
        string $body_html,
        ?string $body_text = null,
        string $from_email = '',
        string $from_name = '',
        array $headers = [],
        array $attachments = [],
        int $priority = 5,
        string $source = 'wordpress',
        ?int $subscriber_id = null,
        ?int $campaign_id = null,
        ?string $scheduled_at = null
    ): int {
        return $this->queue_repo->insert([
            'to_email' => $to,
            'from_email' => $from_email ?: Plugin::get_option('from_email', get_option('admin_email')),
            'from_name' => $from_name ?: Plugin::get_option('from_name', get_bloginfo('name')),
            'subject' => $subject,
            'body_html' => $body_html,
            'body_text' => $body_text,
            'headers' => !empty($headers) ? json_encode($headers) : null,
            'attachments' => !empty($attachments) ? json_encode($attachments) : null,
            'priority' => $priority,
            'source' => $source,
            'subscriber_id' => $subscriber_id,
            'campaign_id' => $campaign_id,
            'scheduled_at' => $scheduled_at,
        ]);
    }

    /**
     * Send test email
     */
    public function send_test(string $to): array {
        $subject = sprintf(
            /* translators: %s: site name */
            __('[%s] Mail and Newsletter Test Email', 'jan-newsletter'),
            get_bloginfo('name')
        );

        $body_html = $this->get_test_email_html();
        $body_text = $this->get_test_email_text();

        $result = $this->send_now($to, $subject, $body_html, $body_text);

        // Log the test email
        $this->log_email(
            $to,
            $subject,
            $result['success'] ? 'sent' : 'failed',
            $result['response'] ?? $result['message'] ?? '',
            'test'
        );

        return $result;
    }

    /**
     * Log an email to the logs table
     */
    private function log_email(
        string $to_email,
        string $subject,
        string $status,
        string $response,
        string $source,
        ?int $queue_id = null,
        ?int $campaign_id = null
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'jan_nl_logs';

        $wpdb->insert($table, [
            'queue_id' => $queue_id,
            'to_email' => $to_email,
            'subject' => $subject,
            'status' => $status,
            'smtp_response' => $response,
            'source' => $source,
            'campaign_id' => $campaign_id,
            'sent_at' => current_time('mysql'),
        ]);
    }

    /**
     * Test SMTP connection
     */
    public function test_smtp(): array {
        return $this->smtp->test();
    }

    /**
     * Test Mailgun connection
     */
    public function test_mailgun(): array {
        return $this->mailgun->test();
    }

    /**
     * Fallback to wp_mail
     */
    private function send_via_wp_mail(
        string $to,
        string $subject,
        string $body_html,
        array $headers = [],
        array $attachments = []
    ): array {
        // Set content type to HTML
        $wp_headers = ['Content-Type: text/html; charset=UTF-8'];

        foreach ($headers as $name => $value) {
            $wp_headers[] = "{$name}: {$value}";
        }

        // Temporarily remove our filter to avoid recursion
        remove_filter('pre_wp_mail', [WpMailInterceptor::class, 'intercept_wp_mail'], 1);

        $result = wp_mail($to, $subject, $body_html, $wp_headers, $attachments);

        // Re-add filter
        add_filter('pre_wp_mail', [WpMailInterceptor::class, 'intercept_wp_mail'], 1, 2);

        if ($result) {
            return [
                'success' => true,
                'message' => __('Email sent via wp_mail', 'jan-newsletter'),
            ];
        }

        global $phpmailer;
        $error = '';
        if (isset($phpmailer) && $phpmailer->ErrorInfo) {
            $error = $phpmailer->ErrorInfo;
        }

        return [
            'success' => false,
            'message' => $error ?: __('wp_mail failed', 'jan-newsletter'),
        ];
    }

    /**
     * Get test email HTML
     */
    private function get_test_email_html(): string {
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test Email</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1 style="color: #2563eb;">Test Email from Mail and Newsletter</h1>
        <p>Congratulations! Your SMTP settings are working correctly.</p>
        <p>This email was sent from <strong>{$site_name}</strong> ({$site_url}).</p>
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;">
        <p style="color: #6b7280; font-size: 14px;">
            This is a test email to verify your SMTP configuration.
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get test email plain text
     */
    private function get_test_email_text(): string {
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        return <<<TEXT
Test Email from Mail and Newsletter

Congratulations! Your SMTP settings are working correctly.

This email was sent from {$site_name} ({$site_url}).

---

This is a test email to verify your SMTP configuration.
TEXT;
    }
}
