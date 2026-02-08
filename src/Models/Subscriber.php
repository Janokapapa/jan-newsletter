<?php

namespace JanNewsletter\Models;

/**
 * Subscriber model
 */
class Subscriber {
    public ?int $id = null;
    public string $email = '';
    public string $first_name = '';
    public string $last_name = '';
    public string $status = 'pending'; // subscribed, unsubscribed, bounced, pending
    public string $source = '';
    public ?string $confirmation_token = null;
    public ?string $confirmed_at = null;
    public string $bounce_status = 'none'; // none, soft, hard, complaint
    public int $bounce_count = 0;
    public ?array $custom_fields = null;
    public string $ip_address = '';
    public ?string $created_at = null;
    public ?string $updated_at = null;

    // Related data (not stored directly)
    public array $lists = [];

    /**
     * Create from database row
     */
    public static function from_row(object|array $row): self {
        $row = (object) $row;
        $subscriber = new self();

        $subscriber->id = (int) $row->id;
        $subscriber->email = $row->email;
        $subscriber->first_name = $row->first_name ?? '';
        $subscriber->last_name = $row->last_name ?? '';
        $subscriber->status = $row->status ?? 'pending';
        $subscriber->source = $row->source ?? '';
        $subscriber->confirmation_token = $row->confirmation_token;
        $subscriber->confirmed_at = $row->confirmed_at;
        $subscriber->bounce_status = $row->bounce_status ?? 'none';
        $subscriber->bounce_count = (int) ($row->bounce_count ?? 0);
        $subscriber->custom_fields = !empty($row->custom_fields) ? json_decode($row->custom_fields, true) : null;
        $subscriber->ip_address = $row->ip_address ?? '';
        $subscriber->created_at = $row->created_at;
        $subscriber->updated_at = $row->updated_at;

        return $subscriber;
    }

    /**
     * Convert to array for database
     */
    public function to_array(): array {
        return [
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'status' => $this->status,
            'source' => $this->source,
            'confirmation_token' => $this->confirmation_token,
            'confirmed_at' => $this->confirmed_at,
            'bounce_status' => $this->bounce_status,
            'bounce_count' => $this->bounce_count,
            'custom_fields' => $this->custom_fields ? json_encode($this->custom_fields) : null,
            'ip_address' => $this->ip_address,
        ];
    }

    /**
     * Get full name
     */
    public function get_full_name(): string {
        $name = trim($this->first_name . ' ' . $this->last_name);
        return $name ?: $this->email;
    }

    /**
     * Generate confirmation token
     */
    public function generate_confirmation_token(): string {
        $this->confirmation_token = bin2hex(random_bytes(32));
        return $this->confirmation_token;
    }

    /**
     * Check if subscriber can receive emails
     */
    public function can_receive_email(): bool {
        return $this->status === 'subscribed' && $this->bounce_status !== 'hard';
    }

    /**
     * Mark as bounced
     */
    public function mark_bounced(string $type = 'soft'): void {
        $this->bounce_status = $type;
        $this->bounce_count++;

        if ($type === 'hard' || $this->bounce_count >= 3) {
            $this->status = 'bounced';
        }
    }

    /**
     * Get custom field value
     */
    public function get_custom_field(string $key, mixed $default = null): mixed {
        return $this->custom_fields[$key] ?? $default;
    }

    /**
     * Set custom field value
     */
    public function set_custom_field(string $key, mixed $value): void {
        if ($this->custom_fields === null) {
            $this->custom_fields = [];
        }
        $this->custom_fields[$key] = $value;
    }
}
