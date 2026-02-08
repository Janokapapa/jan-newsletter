<?php

namespace JanNewsletter\Models;

/**
 * Campaign model
 */
class Campaign {
    public ?int $id = null;
    public string $name = '';
    public string $subject = '';
    public ?string $body_html = null;
    public ?string $body_text = null;
    public string $from_name = '';
    public string $from_email = '';
    public ?int $list_id = null;
    public string $status = 'draft'; // draft, scheduled, sending, sent, paused
    public ?string $scheduled_at = null;
    public ?string $started_at = null;
    public ?string $finished_at = null;
    public int $total_recipients = 0;
    public int $sent_count = 0;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    // Computed fields
    public ?string $list_name = null;
    public int $open_count = 0;
    public int $click_count = 0;

    /**
     * Create from database row
     */
    public static function from_row(object|array $row): self {
        $row = (object) $row;
        $campaign = new self();

        $campaign->id = (int) $row->id;
        $campaign->name = $row->name;
        $campaign->subject = $row->subject;
        $campaign->body_html = $row->body_html;
        $campaign->body_text = $row->body_text;
        $campaign->from_name = $row->from_name ?? '';
        $campaign->from_email = $row->from_email ?? '';
        $campaign->list_id = $row->list_id ? (int) $row->list_id : null;
        $campaign->status = $row->status ?? 'draft';
        $campaign->scheduled_at = $row->scheduled_at;
        $campaign->started_at = $row->started_at;
        $campaign->finished_at = $row->finished_at;
        $campaign->total_recipients = (int) ($row->total_recipients ?? 0);
        $campaign->sent_count = (int) ($row->sent_count ?? 0);
        $campaign->created_at = $row->created_at;
        $campaign->updated_at = $row->updated_at;

        // Computed fields if available
        if (isset($row->list_name)) {
            $campaign->list_name = $row->list_name;
        }
        if (isset($row->open_count)) {
            $campaign->open_count = (int) $row->open_count;
        }
        if (isset($row->click_count)) {
            $campaign->click_count = (int) $row->click_count;
        }

        return $campaign;
    }

    /**
     * Convert to array for database
     */
    public function to_array(): array {
        return [
            'name' => $this->name,
            'subject' => $this->subject,
            'body_html' => $this->body_html,
            'body_text' => $this->body_text,
            'from_name' => $this->from_name,
            'from_email' => $this->from_email,
            'list_id' => $this->list_id,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'total_recipients' => $this->total_recipients,
            'sent_count' => $this->sent_count,
        ];
    }

    /**
     * Get open rate
     */
    public function get_open_rate(): float {
        if ($this->sent_count === 0) {
            return 0.0;
        }
        return round(($this->open_count / $this->sent_count) * 100, 2);
    }

    /**
     * Get click rate
     */
    public function get_click_rate(): float {
        if ($this->sent_count === 0) {
            return 0.0;
        }
        return round(($this->click_count / $this->sent_count) * 100, 2);
    }

    /**
     * Check if campaign can be edited
     */
    public function can_edit(): bool {
        return in_array($this->status, ['draft', 'scheduled', 'paused']);
    }

    /**
     * Check if campaign can be sent
     */
    public function can_send(): bool {
        return in_array($this->status, ['draft', 'scheduled', 'paused']);
    }

    /**
     * Check if campaign can be paused
     */
    public function can_pause(): bool {
        return $this->status === 'sending';
    }

    /**
     * Generate plain text from HTML
     */
    public function generate_plain_text(): string {
        if (empty($this->body_html)) {
            return '';
        }

        $text = $this->body_html;

        // Convert links
        $text = preg_replace('/<a[^>]+href="([^"]*)"[^>]*>([^<]*)<\/a>/i', '$2 ($1)', $text);

        // Convert line breaks
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/div>/i', "\n", $text);
        $text = preg_replace('/<\/h[1-6]>/i', "\n\n", $text);

        // Remove remaining HTML tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // Clean up whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Convert to API response format
     */
    public function to_api_response(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subject' => $this->subject,
            'body_html' => $this->body_html,
            'body_text' => $this->body_text,
            'from_name' => $this->from_name,
            'from_email' => $this->from_email,
            'list_id' => $this->list_id,
            'list_name' => $this->list_name,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'total_recipients' => $this->total_recipients,
            'sent_count' => $this->sent_count,
            'open_count' => $this->open_count,
            'click_count' => $this->click_count,
            'open_rate' => $this->get_open_rate(),
            'click_rate' => $this->get_click_rate(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
