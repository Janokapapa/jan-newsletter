<?php

namespace JanNewsletter\Mail;

use JanNewsletter\Plugin;

/**
 * Mailgun HTTP API Transport for sending emails
 */
class MailgunTransport {
    private string $api_key;
    private string $domain;
    private string $region; // 'eu' or 'us'

    private string $last_error = '';
    private string $last_response = '';

    public function __construct() {
        $this->api_key = Plugin::get_option('mailgun_api_key', '');
        $this->domain = Plugin::get_option('mailgun_domain', '');
        $this->region = Plugin::get_option('mailgun_region', 'eu');
    }

    /**
     * Get Mailgun API base URL
     */
    private function get_api_url(): string {
        $base = $this->region === 'eu'
            ? 'https://api.eu.mailgun.net'
            : 'https://api.mailgun.net';

        return $base . '/v3/' . $this->domain . '/messages';
    }

    /**
     * Send email via Mailgun API
     */
    public function send(
        string $to,
        string $subject,
        string $body_html,
        ?string $body_text = null,
        string $from_email = '',
        string $from_name = '',
        array $headers = [],
        array $attachments = []
    ): bool {
        if (empty($this->api_key) || empty($this->domain)) {
            $this->last_error = __('Mailgun API key or domain not configured', 'jan-newsletter');
            return false;
        }

        if (empty($from_email)) {
            $from_email = Plugin::get_option('from_email', get_option('admin_email'));
        }
        if (empty($from_name)) {
            $from_name = Plugin::get_option('from_name', get_bloginfo('name'));
        }

        if (empty($body_text)) {
            $body_text = $this->html_to_text($body_html);
        }

        $from = !empty($from_name)
            ? "{$from_name} <{$from_email}>"
            : $from_email;

        $body = [
            'from'    => $from,
            'to'      => $to,
            'subject' => $subject,
            'html'    => $body_html,
            'text'    => $body_text,
        ];

        // Add custom headers
        foreach ($headers as $name => $value) {
            $body["h:{$name}"] = $value;
        }

        $response = wp_remote_post($this->get_api_url(), [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode('api:' . $this->api_key),
            ],
            'body'    => $body,
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $this->last_response = $response_body;

        if ($code === 200) {
            return true;
        }

        $decoded = json_decode($response_body, true);
        $this->last_error = $decoded['message'] ?? "Mailgun API error (HTTP {$code})";
        return false;
    }

    /**
     * Test Mailgun API connection (validates API key + domain)
     */
    public function test(): array {
        if (empty($this->api_key) || empty($this->domain)) {
            return [
                'success' => false,
                'message' => __('Mailgun API key or domain not configured', 'jan-newsletter'),
            ];
        }

        $base = $this->region === 'eu'
            ? 'https://api.eu.mailgun.net'
            : 'https://api.mailgun.net';

        $url = $base . '/v3/' . $this->domain;

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode('api:' . $this->api_key),
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && isset($body['domain'])) {
            return [
                'success' => true,
                'message' => sprintf(
                    __('Mailgun connected: %s (%s)', 'jan-newsletter'),
                    $body['domain']['name'] ?? $this->domain,
                    $body['domain']['state'] ?? 'unknown'
                ),
            ];
        }

        return [
            'success' => false,
            'message' => $body['message'] ?? "Mailgun API error (HTTP {$code})",
        ];
    }

    /**
     * Get last error
     */
    public function get_last_error(): string {
        return $this->last_error;
    }

    /**
     * Get last API response
     */
    public function get_last_response(): string {
        return $this->last_response;
    }

    /**
     * Convert HTML to plain text
     */
    private function html_to_text(string $html): string {
        $text = $html;
        $text = preg_replace('/<a[^>]+href="([^"]*)"[^>]*>([^<]*)<\/a>/i', '$2 ($1)', $text);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/div>/i', "\n", $text);
        $text = preg_replace('/<\/h[1-6]>/i', "\n\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
}
