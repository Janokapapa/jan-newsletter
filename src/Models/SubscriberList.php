<?php

namespace JanNewsletter\Models;

/**
 * Subscriber List model
 */
class SubscriberList {
    public ?int $id = null;
    public string $name = '';
    public string $slug = '';
    public string $description = '';
    public bool $double_optin = true;
    public ?string $created_at = null;

    // Computed fields
    public int $subscriber_count = 0;
    public int $active_count = 0;

    /**
     * Create from database row
     */
    public static function from_row(object|array $row): self {
        $row = (object) $row;
        $list = new self();

        $list->id = (int) $row->id;
        $list->name = $row->name;
        $list->slug = $row->slug;
        $list->description = $row->description ?? '';
        $list->double_optin = (bool) ($row->double_optin ?? true);
        $list->created_at = $row->created_at;

        // Computed fields if available
        if (isset($row->subscriber_count)) {
            $list->subscriber_count = (int) $row->subscriber_count;
        }
        if (isset($row->active_count)) {
            $list->active_count = (int) $row->active_count;
        }

        return $list;
    }

    /**
     * Convert to array for database
     */
    public function to_array(): array {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'double_optin' => $this->double_optin ? 1 : 0,
        ];
    }

    /**
     * Generate slug from name
     */
    public function generate_slug(): string {
        $this->slug = sanitize_title($this->name);
        return $this->slug;
    }

    /**
     * Convert to API response format
     */
    public function to_api_response(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'double_optin' => $this->double_optin,
            'subscriber_count' => $this->subscriber_count,
            'active_count' => $this->active_count,
            'created_at' => $this->created_at,
        ];
    }
}
