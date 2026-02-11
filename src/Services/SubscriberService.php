<?php

namespace JanNewsletter\Services;

use JanNewsletter\Plugin;
use JanNewsletter\Models\Subscriber;
use JanNewsletter\Repositories\SubscriberRepository;
use JanNewsletter\Repositories\ListRepository;
use JanNewsletter\Mail\Mailer;

/**
 * Subscriber service - business logic
 */
class SubscriberService {
    private SubscriberRepository $subscriber_repo;
    private ListRepository $list_repo;
    private Mailer $mailer;

    public function __construct() {
        $this->subscriber_repo = new SubscriberRepository();
        $this->list_repo = new ListRepository();
        $this->mailer = new Mailer();
    }

    /**
     * Subscribe a new email
     */
    public function subscribe(
        string $email,
        array $data = [],
        ?int $list_id = null,
        string $source = 'form'
    ): array {
        $email = sanitize_email($email);

        if (!is_email($email)) {
            return [
                'success' => false,
                'message' => __('Invalid email address', 'jan-newsletter'),
            ];
        }

        // Check if already exists
        $existing = $this->subscriber_repo->find_by_email($email);

        if ($existing) {
            // Already subscribed
            if ($existing->status === 'subscribed') {
                return [
                    'success' => true,
                    'message' => __('You are already subscribed', 'jan-newsletter'),
                    'subscriber' => $existing,
                ];
            }

            // Resubscribe (was unsubscribed/bounced)
            $existing->status = 'subscribed';
            $existing->bounce_status = 'none';
            $existing->bounce_count = 0;
            $this->subscriber_repo->update($existing->id, [
                'status' => 'subscribed',
                'bounce_status' => 'none',
                'bounce_count' => 0,
            ]);

            if ($list_id) {
                $this->subscriber_repo->add_to_list($existing->id, $list_id);
            }

            return [
                'success' => true,
                'message' => __('You have been resubscribed', 'jan-newsletter'),
                'subscriber' => $existing,
            ];
        }

        // Create new subscriber
        $subscriber = new Subscriber();
        $subscriber->email = $email;
        $subscriber->first_name = sanitize_text_field($data['first_name'] ?? '');
        $subscriber->last_name = sanitize_text_field($data['last_name'] ?? '');
        $subscriber->source = $source;
        $subscriber->ip_address = $this->get_client_ip();

        // Get list for double opt-in setting
        $list = $list_id ? $this->list_repo->find($list_id) : null;
        $double_optin = $list ? $list->double_optin : Plugin::get_option('double_optin', true);

        if ($double_optin) {
            $subscriber->status = 'pending';
            $subscriber->generate_confirmation_token();
        } else {
            $subscriber->status = 'subscribed';
            $subscriber->confirmed_at = current_time('mysql');
        }

        $subscriber_id = $this->subscriber_repo->insert($subscriber);
        $subscriber->id = $subscriber_id;

        // Add to list
        if ($list_id) {
            $this->subscriber_repo->add_to_list($subscriber_id, $list_id);
        }

        // Send confirmation email if double opt-in
        if ($double_optin) {
            $this->send_confirmation_email($subscriber);

            return [
                'success' => true,
                'message' => __('Please check your email to confirm your subscription', 'jan-newsletter'),
                'subscriber' => $subscriber,
                'pending_confirmation' => true,
            ];
        }

        return [
            'success' => true,
            'message' => __('You have been subscribed successfully', 'jan-newsletter'),
            'subscriber' => $subscriber,
        ];
    }

    /**
     * Confirm subscription
     */
    public function confirm(string $token): array {
        $subscriber = $this->subscriber_repo->find_by_token($token);

        if (!$subscriber) {
            return [
                'success' => false,
                'message' => __('Invalid or expired confirmation link', 'jan-newsletter'),
            ];
        }

        if ($subscriber->status === 'subscribed') {
            return [
                'success' => true,
                'message' => __('Your subscription is already confirmed', 'jan-newsletter'),
                'subscriber' => $subscriber,
            ];
        }

        $subscriber->status = 'subscribed';
        $subscriber->confirmation_token = null;
        $subscriber->confirmed_at = current_time('mysql');
        $this->subscriber_repo->update($subscriber->id, [
            'status' => 'subscribed',
            'confirmed_at' => $subscriber->confirmed_at,
        ]);

        return [
            'success' => true,
            'message' => __('Your subscription has been confirmed', 'jan-newsletter'),
            'subscriber' => $subscriber,
        ];
    }

    /**
     * Unsubscribe
     */
    public function unsubscribe(string $email, ?string $token = null): array {
        $subscriber = $this->subscriber_repo->find_by_email($email);

        if (!$subscriber) {
            return [
                'success' => false,
                'message' => __('Email not found', 'jan-newsletter'),
            ];
        }

        // Verify token if provided (for secure unsubscribe links)
        if ($token && $subscriber->confirmation_token !== $token) {
            // Generate unsubscribe token from email for verification
            $expected_token = $this->generate_unsubscribe_token($email);
            if ($token !== $expected_token) {
                return [
                    'success' => false,
                    'message' => __('Invalid unsubscribe link', 'jan-newsletter'),
                ];
            }
        }

        if ($subscriber->status === 'unsubscribed') {
            return [
                'success' => true,
                'message' => __('You are already unsubscribed', 'jan-newsletter'),
                'subscriber' => $subscriber,
            ];
        }

        $subscriber->status = 'unsubscribed';
        $this->subscriber_repo->update($subscriber->id, ['status' => 'unsubscribed']);

        return [
            'success' => true,
            'message' => __('You have been unsubscribed successfully', 'jan-newsletter'),
            'subscriber' => $subscriber,
        ];
    }

    /**
     * Update subscriber
     */
    public function update(int $id, array $data): array {
        $subscriber = $this->subscriber_repo->find($id);

        if (!$subscriber) {
            return [
                'success' => false,
                'message' => __('Subscriber not found', 'jan-newsletter'),
            ];
        }

        // Check email change
        if (isset($data['email']) && $data['email'] !== $subscriber->email) {
            $new_email = sanitize_email($data['email']);

            if (!is_email($new_email)) {
                return [
                    'success' => false,
                    'message' => __('Invalid email address', 'jan-newsletter'),
                ];
            }

            if ($this->subscriber_repo->email_exists($new_email, $id)) {
                return [
                    'success' => false,
                    'message' => __('This email is already subscribed', 'jan-newsletter'),
                ];
            }

            $subscriber->email = $new_email;
        }

        // Update fields
        if (isset($data['first_name'])) {
            $subscriber->first_name = sanitize_text_field($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $subscriber->last_name = sanitize_text_field($data['last_name']);
        }
        if (isset($data['status'])) {
            $subscriber->status = $data['status'];
        }
        if (isset($data['custom_fields'])) {
            $subscriber->custom_fields = $data['custom_fields'];
        }

        $update_data = [];
        if (isset($data['first_name'])) $update_data['first_name'] = $subscriber->first_name;
        if (isset($data['last_name'])) $update_data['last_name'] = $subscriber->last_name;
        if (isset($data['status'])) $update_data['status'] = $subscriber->status;
        if (isset($data['email'])) $update_data['email'] = $subscriber->email;
        if (!empty($update_data)) {
            $this->subscriber_repo->update($id, $update_data);
        }

        // Update lists if provided
        if (isset($data['list_ids']) && is_array($data['list_ids'])) {
            $this->subscriber_repo->sync_lists($id, $data['list_ids']);
        }

        return [
            'success' => true,
            'message' => __('Subscriber updated successfully', 'jan-newsletter'),
            'subscriber' => $this->subscriber_repo->find($id),
        ];
    }

    /**
     * Send confirmation email
     */
    private function send_confirmation_email(Subscriber $subscriber): void {
        $confirm_url = home_url('/jan-newsletter/confirm/' . $subscriber->confirmation_token);
        $site_name = get_bloginfo('name');

        $subject = sprintf(
            /* translators: %s: site name */
            __('Confirm your subscription to %s', 'jan-newsletter'),
            $site_name
        );

        $body_html = $this->get_confirmation_email_html($subscriber, $confirm_url);
        $body_text = $this->get_confirmation_email_text($subscriber, $confirm_url);

        $this->mailer->queue(
            $subscriber->email,
            $subject,
            $body_html,
            $body_text,
            '', // from_email
            '', // from_name
            [], // headers
            [], // attachments
            1,  // priority (critical)
            'jan-newsletter',
            $subscriber->id
        );
    }

    /**
     * Generate unsubscribe token
     */
    public function generate_unsubscribe_token(string $email): string {
        $secret = wp_salt('auth');
        return substr(hash('sha256', $email . $secret), 0, 32);
    }

    /**
     * Get unsubscribe URL for subscriber
     */
    public function get_unsubscribe_url(Subscriber $subscriber): string {
        $token = $this->generate_unsubscribe_token($subscriber->email);
        return home_url('/jan-newsletter/unsubscribe/' . urlencode($subscriber->email) . '/' . $token);
    }

    /**
     * Get confirmation email HTML
     */
    private function get_confirmation_email_html(Subscriber $subscriber, string $confirm_url): string {
        $site_name = esc_html(get_bloginfo('name'));
        $name = esc_html($subscriber->get_full_name());
        $confirm_url = esc_url($confirm_url);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Confirm your subscription</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f3f4f6; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="background-color: #2563eb; color: #fff; padding: 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px;">Confirm Your Subscription</h1>
        </div>
        <div style="padding: 30px;">
            <p>Hi {$name},</p>
            <p>Thank you for subscribing to <strong>{$site_name}</strong>!</p>
            <p>Please click the button below to confirm your subscription:</p>
            <p style="text-align: center; margin: 30px 0;">
                <a href="{$confirm_url}" style="display: inline-block; background-color: #2563eb; color: #fff; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">Confirm Subscription</a>
            </p>
            <p style="color: #6b7280; font-size: 14px;">If you didn't subscribe to our list, you can safely ignore this email.</p>
            <p style="color: #6b7280; font-size: 14px;">If the button doesn't work, copy and paste this link into your browser:</p>
            <p style="color: #6b7280; font-size: 12px; word-break: break-all;">{$confirm_url}</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get confirmation email plain text
     */
    private function get_confirmation_email_text(Subscriber $subscriber, string $confirm_url): string {
        $site_name = get_bloginfo('name');
        $name = $subscriber->get_full_name();

        return <<<TEXT
Confirm Your Subscription

Hi {$name},

Thank you for subscribing to {$site_name}!

Please confirm your subscription by visiting this link:
{$confirm_url}

If you didn't subscribe to our list, you can safely ignore this email.
TEXT;
    }

    /**
     * Get client IP
     */
    private function get_client_ip(): string {
        $ip_headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($ip_headers as $header) {
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
