<?php

namespace JanNewsletter\Mail;

use JanNewsletter\Plugin;
use JanNewsletter\Repositories\QueueRepository;

/**
 * Intercepts wp_mail() calls and queues them
 */
class WpMailInterceptor {
    private QueueRepository $queue_repo;
    private static bool $bypass = false;

    public function __construct() {
        $this->queue_repo = new QueueRepository();
    }

    /**
     * Initialize the interceptor
     */
    public function init(): void {
        add_filter('pre_wp_mail', [$this, 'intercept_wp_mail'], 1, 2);
    }

    /**
     * Temporarily bypass interception
     */
    public static function bypass(bool $bypass = true): void {
        self::$bypass = $bypass;
    }

    /**
     * Intercept wp_mail calls
     */
    public function intercept_wp_mail($null, array $args): ?bool {
        // Check if bypassing
        if (self::$bypass) {
            return null;
        }

        $to = $args['to'] ?? '';
        $subject = $args['subject'] ?? '';
        $message = $args['message'] ?? '';
        $headers = $args['headers'] ?? [];
        $attachments = $args['attachments'] ?? [];

        // Detect priority from subject and context
        $priority = $this->detect_priority($subject, $message);

        // Detect source from backtrace
        $source = $this->detect_source();

        // Parse headers
        $parsed_headers = $this->parse_headers($headers);

        // Always use our configured from address (ignore From set by other plugins)
        $from_email = Plugin::get_option('from_email', get_option('admin_email'));
        $from_name = Plugin::get_option('from_name', get_bloginfo('name'));

        // Strip headers that we override (From, MIME-Version, Content-Type)
        $strip_headers = ['From', 'MIME-Version', 'Content-Type'];
        if (is_string($headers)) {
            $lines = explode("\n", $headers);
            $filtered = array_filter($lines, function ($line) use ($strip_headers) {
                foreach ($strip_headers as $h) {
                    if (stripos(trim($line), $h . ':') === 0) {
                        return false;
                    }
                }
                return true;
            });
            $headers = implode("\n", $filtered);
        } elseif (is_array($headers)) {
            $headers = array_filter($headers, function ($header) use ($strip_headers) {
                if (!is_string($header)) return true;
                foreach ($strip_headers as $h) {
                    if (stripos(trim($header), $h . ':') === 0) {
                        return false;
                    }
                }
                return true;
            });
            $headers = array_values($headers);
        }

        // Handle multiple recipients
        $recipients = is_array($to) ? $to : explode(',', $to);
        $to_string = implode(', ', array_map('trim', $recipients));

        // Detect if HTML
        $content_type = $parsed_headers['Content-Type'] ?? '';
        $is_html = stripos($content_type, 'text/html') !== false;

        // If not HTML but looks like HTML, treat it as HTML
        if (!$is_html && (stripos($message, '<html') !== false || stripos($message, '<body') !== false)) {
            $is_html = true;
        }

        // Queue the email
        $this->queue_repo->insert([
            'to_email' => $to_string,
            'from_email' => $from_email,
            'from_name' => $from_name,
            'subject' => $subject,
            'body_html' => $is_html ? $message : null,
            'body_text' => $is_html ? null : $message,
            'headers' => !empty($headers) ? (is_array($headers) ? json_encode($headers) : $headers) : null,
            'attachments' => !empty($attachments) ? json_encode($attachments) : null,
            'priority' => $priority,
            'source' => $source,
            'status' => 'pending',
        ]);

        // Trigger immediate async queue processing for critical/high priority
        if ($priority <= 3) {
            $this->trigger_async_processing();
        }

        // Return true to prevent wp_mail from sending
        return true;
    }

    /**
     * Detect email priority from subject and content
     */
    private function detect_priority(string $subject, string $message): int {
        $subject_lower = strtolower($subject);
        $message_lower = strtolower($message);

        // Critical (1): Password reset, account verification, appointments
        $critical_keywords = ['password', 'reset', 'verify', 'activate', 'confirm your', 'appointment', 'booking', 'reservation'];
        foreach ($critical_keywords as $keyword) {
            if (strpos($subject_lower, $keyword) !== false) {
                return 1;
            }
        }

        // High (3): Order confirmations, invoices
        $high_keywords = ['order', 'invoice', 'receipt', 'payment', 'purchase', 'shipping'];
        foreach ($high_keywords as $keyword) {
            if (strpos($subject_lower, $keyword) !== false) {
                return 3;
            }
        }

        // Bulk (10): Newsletter, campaign, multiple recipients
        $bulk_keywords = ['newsletter', 'campaign', 'digest', 'weekly', 'monthly'];
        foreach ($bulk_keywords as $keyword) {
            if (strpos($subject_lower, $keyword) !== false) {
                return 10;
            }
        }

        // Default (5): Normal priority
        return 5;
    }

    /**
     * Detect email source from backtrace
     */
    private function detect_source(): string {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';

            // WooCommerce
            if (strpos($file, 'woocommerce') !== false) {
                return 'woocommerce';
            }

            // WPForms
            if (strpos($file, 'wpforms') !== false) {
                return 'wpforms';
            }

            // Contact Form 7
            if (strpos($file, 'contact-form-7') !== false) {
                return 'contact-form-7';
            }

            // Gravity Forms
            if (strpos($file, 'gravityforms') !== false) {
                return 'gravityforms';
            }

            // Appointments+
            if (strpos($file, 'appointments') !== false) {
                return 'appointments';
            }

            // Our own plugin
            if (strpos($file, 'jan-newsletter') !== false) {
                // Check if campaign
                if (isset($frame['class']) && strpos($frame['class'], 'Campaign') !== false) {
                    return 'campaign';
                }
                return 'jan-newsletter';
            }
        }

        return 'wordpress';
    }

    /**
     * Trigger async queue processing via non-blocking loopback request
     */
    private function trigger_async_processing(): void {
        $url = admin_url('admin-ajax.php');
        $token = wp_hash('jan_nl_process_queue');
        wp_remote_post($url, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false,
            'body' => [
                'action' => 'jan_nl_process_queue',
                'token' => $token,
            ],
        ]);
    }

    /**
     * Parse headers into array
     */
    private function parse_headers($headers): array {
        if (is_array($headers)) {
            $result = [];
            foreach ($headers as $header) {
                if (is_string($header) && strpos($header, ':') !== false) {
                    list($name, $value) = explode(':', $header, 2);
                    $result[trim($name)] = trim($value);
                }
            }
            return $result;
        }

        if (is_string($headers)) {
            $result = [];
            $lines = explode("\n", $headers);
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, ':') !== false) {
                    list($name, $value) = explode(':', $line, 2);
                    $result[trim($name)] = trim($value);
                }
            }
            return $result;
        }

        return [];
    }
}
