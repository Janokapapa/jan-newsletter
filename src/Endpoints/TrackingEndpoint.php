<?php

namespace JanNewsletter\Endpoints;

use JanNewsletter\Mail\TrackingPixel;

/**
 * Tracking endpoint for opens and clicks
 */
class TrackingEndpoint {
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
        // Open tracking
        add_rewrite_rule(
            '^jan-newsletter/track/open/([^/]+)/?$',
            'index.php?jan_newsletter_action=track_open&data=$matches[1]',
            'top'
        );

        // Click tracking
        add_rewrite_rule(
            '^jan-newsletter/track/click/([^/]+)/?$',
            'index.php?jan_newsletter_action=track_click&data=$matches[1]',
            'top'
        );

        add_rewrite_tag('%jan_newsletter_action%', '([^&]+)');
        add_rewrite_tag('%data%', '([^&]+)');
    }

    /**
     * Handle tracking request
     */
    public function handle_request(): void {
        $action = get_query_var('jan_newsletter_action');

        if (!in_array($action, ['track_open', 'track_click'])) {
            return;
        }

        $data = get_query_var('data');

        if (empty($data)) {
            $this->serve_fallback($action);
            return;
        }

        $decoded = TrackingPixel::decode_tracking_data($data);

        if (!$decoded) {
            $this->serve_fallback($action);
            return;
        }

        $campaign_id = $decoded['c'] ?? 0;
        $subscriber_id = $decoded['s'] ?? 0;

        if (!$campaign_id || !$subscriber_id) {
            $this->serve_fallback($action);
            return;
        }

        if ($action === 'track_open') {
            // Record open and serve pixel
            TrackingPixel::record_open($campaign_id, $subscriber_id);
            TrackingPixel::serve_pixel();
        } else {
            // Record click and redirect
            $url = $decoded['u'] ?? '';

            if (empty($url)) {
                wp_redirect(home_url());
                exit;
            }

            TrackingPixel::record_click($campaign_id, $subscriber_id, $url);

            // Redirect to original URL
            wp_redirect($url);
            exit;
        }
    }

    /**
     * Serve fallback response
     */
    private function serve_fallback(string $action): void {
        if ($action === 'track_open') {
            // Still serve pixel even if tracking fails
            TrackingPixel::serve_pixel();
        } else {
            // Redirect to home for invalid click
            wp_redirect(home_url());
            exit;
        }
    }
}
