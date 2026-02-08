<?php

namespace JanNewsletter\Models;

/**
 * Queued Email model
 */
class QueuedEmail {
    public ?int $id = null;
    public string $to_email = '';
    public string $from_email = '';
    public string $from_name = '';
    public string $subject = '';
    public ?string $body_html = null;
    public ?string $body_text = null;
    public ?string $headers = null;
    public ?string $attachments = null;
    public string $status = 'pending'; // pending, processing, sent, failed, cancelled
    public int $priority = 5; // 1=critical, 3=high, 5=medium, 10=bulk
    public int $attempts = 0;
    public int $max_attempts = 3;
    public ?string $error_message = null;
    public string $source = 'wordpress'; // campaign, wordpress, woocommerce, wpforms
    public ?int $subscriber_id = null;
    public ?int $campaign_id = null;
    public ?string $scheduled_at = null;
    public ?string $sent_at = null;
    public ?string $created_at = null;

    /**
     * Create from database row
     */
    public static function from_row(object|array $row): self {
        $row = (object) $row;
        $email = new self();

        $email->id = (int) $row->id;
        $email->to_email = $row->to_email;
        $email->from_email = $row->from_email;
        $email->from_name = $row->from_name ?? '';
        $email->subject = $row->subject;
        $email->body_html = $row->body_html;
        $email->body_text = $row->body_text;
        $email->headers = $row->headers;
        $email->attachments = $row->attachments;
        $email->status = $row->status ?? 'pending';
        $email->priority = (int) ($row->priority ?? 5);
        $email->attempts = (int) ($row->attempts ?? 0);
        $email->max_attempts = (int) ($row->max_attempts ?? 3);
        $email->error_message = $row->error_message;
        $email->source = $row->source ?? 'wordpress';
        $email->subscriber_id = $row->subscriber_id ? (int) $row->subscriber_id : null;
        $email->campaign_id = $row->campaign_id ? (int) $row->campaign_id : null;
        $email->scheduled_at = $row->scheduled_at;
        $email->sent_at = $row->sent_at;
        $email->created_at = $row->created_at;

        return $email;
    }

    /**
     * Convert to array for database
     */
    public function to_array(): array {
        return [
            'to_email' => $this->to_email,
            'from_email' => $this->from_email,
            'from_name' => $this->from_name,
            'subject' => $this->subject,
            'body_html' => $this->body_html,
            'body_text' => $this->body_text,
            'headers' => $this->headers,
            'attachments' => $this->attachments,
            'status' => $this->status,
            'priority' => $this->priority,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'error_message' => $this->error_message,
            'source' => $this->source,
            'subscriber_id' => $this->subscriber_id,
            'campaign_id' => $this->campaign_id,
            'scheduled_at' => $this->scheduled_at,
            'sent_at' => $this->sent_at,
        ];
    }

    /**
     * Get parsed headers array
     */
    public function get_headers_array(): array {
        if (empty($this->headers)) {
            return [];
        }

        // Try JSON first
        $decoded = json_decode($this->headers, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Parse string headers
        $headers = [];
        $lines = explode("\n", $this->headers);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        return $headers;
    }

    /**
     * Get parsed attachments array
     */
    public function get_attachments_array(): array {
        if (empty($this->attachments)) {
            return [];
        }

        $decoded = json_decode($this->attachments, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Check if can retry
     */
    public function can_retry(): bool {
        return $this->status === 'failed' && $this->attempts < $this->max_attempts;
    }

    /**
     * Check if ready to send
     */
    public function is_ready(): bool {
        if ($this->status !== 'pending') {
            return false;
        }

        if ($this->scheduled_at && strtotime($this->scheduled_at) > time()) {
            return false;
        }

        return true;
    }

    /**
     * Get priority label
     */
    public function get_priority_label(): string {
        return match ($this->priority) {
            1 => __('Critical', 'jan-newsletter'),
            3 => __('High', 'jan-newsletter'),
            5 => __('Medium', 'jan-newsletter'),
            10 => __('Bulk', 'jan-newsletter'),
            default => __('Normal', 'jan-newsletter'),
        };
    }

    /**
     * Convert to API response format
     */
    public function to_api_response(): array {
        return [
            'id' => $this->id,
            'to_email' => $this->to_email,
            'from_email' => $this->from_email,
            'from_name' => $this->from_name,
            'subject' => $this->subject,
            'status' => $this->status,
            'priority' => $this->priority,
            'priority_label' => $this->get_priority_label(),
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'error_message' => $this->error_message,
            'source' => $this->source,
            'subscriber_id' => $this->subscriber_id,
            'campaign_id' => $this->campaign_id,
            'scheduled_at' => $this->scheduled_at,
            'sent_at' => $this->sent_at,
            'created_at' => $this->created_at,
        ];
    }
}
