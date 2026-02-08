<?php

namespace JanNewsletter\Mail;

use JanNewsletter\Plugin;
use JanNewsletter\Repositories\StatsRepository;
use JanNewsletter\Repositories\SubscriberRepository;

/**
 * Email tracking (opens and clicks)
 */
class TrackingPixel {
    /**
     * Inject tracking pixel into HTML email
     */
    public static function inject(string $html, int $campaign_id, int $subscriber_id): string {
        if (!Plugin::get_option('track_opens', true)) {
            return $html;
        }

        // Generate tracking URL
        $tracking_url = self::get_open_tracking_url($campaign_id, $subscriber_id);

        // Create tracking pixel
        $pixel = '<img src="' . esc_url($tracking_url) . '" width="1" height="1" alt="" style="display:none;width:1px;height:1px;border:0;" />';

        // Inject before </body> or at end
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</body>', $pixel . '</body>', $html);
        } else {
            $html .= $pixel;
        }

        return $html;
    }

    /**
     * Wrap links for click tracking
     */
    public static function wrap_links(string $html, int $campaign_id, int $subscriber_id): string {
        if (!Plugin::get_option('track_clicks', true)) {
            return $html;
        }

        // Find all links
        $pattern = '/<a([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i';

        $html = preg_replace_callback($pattern, function ($matches) use ($campaign_id, $subscriber_id) {
            $before = $matches[1];
            $url = $matches[2];
            $after = $matches[3];

            // Skip certain URLs
            if (
                strpos($url, '#') === 0 || // Anchor links
                strpos($url, 'mailto:') === 0 || // Email links
                strpos($url, 'tel:') === 0 || // Phone links
                strpos($url, 'unsubscribe') !== false // Unsubscribe links
            ) {
                return $matches[0];
            }

            // Generate tracking URL
            $tracking_url = self::get_click_tracking_url($campaign_id, $subscriber_id, $url);

            return '<a' . $before . 'href="' . esc_url($tracking_url) . '"' . $after . '>';
        }, $html);

        return $html;
    }

    /**
     * Get open tracking URL
     */
    public static function get_open_tracking_url(int $campaign_id, int $subscriber_id): string {
        $data = self::encode_tracking_data([
            'c' => $campaign_id,
            's' => $subscriber_id,
        ]);

        return home_url('/jan-newsletter/track/open/' . $data);
    }

    /**
     * Get click tracking URL
     */
    public static function get_click_tracking_url(int $campaign_id, int $subscriber_id, string $url): string {
        $data = self::encode_tracking_data([
            'c' => $campaign_id,
            's' => $subscriber_id,
            'u' => $url,
        ]);

        return home_url('/jan-newsletter/track/click/' . $data);
    }

    /**
     * Encode tracking data
     */
    private static function encode_tracking_data(array $data): string {
        $json = json_encode($data);
        $encoded = base64_encode($json);
        // URL-safe base64
        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    /**
     * Decode tracking data
     */
    public static function decode_tracking_data(string $data): ?array {
        // URL-safe base64 decode
        $data = strtr($data, '-_', '+/');
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode($data);
        if (!$json) {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Record open event
     */
    public static function record_open(int $campaign_id, int $subscriber_id): void {
        $stats_repo = new StatsRepository();

        // Prevent duplicate opens (unique per subscriber per campaign)
        if ($stats_repo->event_exists($campaign_id, $subscriber_id, 'open')) {
            return;
        }

        $subscriber_repo = new SubscriberRepository();
        $subscriber = $subscriber_repo->find($subscriber_id);

        $stats_repo->record([
            'campaign_id' => $campaign_id,
            'subscriber_id' => $subscriber_id,
            'email' => $subscriber?->email ?? '',
            'event_type' => 'open',
            'ip_address' => self::get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
    }

    /**
     * Record click event
     */
    public static function record_click(int $campaign_id, int $subscriber_id, string $url): void {
        $stats_repo = new StatsRepository();

        $subscriber_repo = new SubscriberRepository();
        $subscriber = $subscriber_repo->find($subscriber_id);

        $stats_repo->record([
            'campaign_id' => $campaign_id,
            'subscriber_id' => $subscriber_id,
            'email' => $subscriber?->email ?? '',
            'event_type' => 'click',
            'link_url' => $url,
            'ip_address' => self::get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);

        // Also record open if not already (clicked = opened)
        if (!$stats_repo->event_exists($campaign_id, $subscriber_id, 'open')) {
            $stats_repo->record([
                'campaign_id' => $campaign_id,
                'subscriber_id' => $subscriber_id,
                'email' => $subscriber?->email ?? '',
                'event_type' => 'open',
                'ip_address' => self::get_client_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);
        }
    }

    /**
     * Get client IP address
     */
    private static function get_client_ip(): string {
        $ip_headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
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

    /**
     * Serve 1x1 transparent GIF
     */
    public static function serve_pixel(): void {
        header('Content-Type: image/gif');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // 1x1 transparent GIF
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
}
