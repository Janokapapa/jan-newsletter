<?php

namespace JanNewsletter\Services;

use JanNewsletter\Plugin;
use JanNewsletter\Repositories\SubscriberRepository;
use JanNewsletter\Repositories\StatsRepository;

/**
 * Bounce handling service
 */
class BounceService {
    private SubscriberRepository $subscriber_repo;
    private StatsRepository $stats_repo;

    public function __construct() {
        $this->subscriber_repo = new SubscriberRepository();
        $this->stats_repo = new StatsRepository();
    }

    /**
     * Process Mailgun webhook
     */
    public function process_mailgun(array $data): array {
        // Verify signature
        if (!$this->verify_mailgun_signature($data)) {
            return [
                'success' => false,
                'message' => __('Invalid signature', 'jan-newsletter'),
            ];
        }

        $event_data = $data['event-data'] ?? $data;
        $event_type = $event_data['event'] ?? '';
        $email = $event_data['recipient'] ?? '';

        if (empty($email)) {
            return [
                'success' => false,
                'message' => __('No recipient email', 'jan-newsletter'),
            ];
        }

        // Extract custom variables (campaign_id, subscriber_id)
        $user_variables = $event_data['user-variables'] ?? [];
        $campaign_id = !empty($user_variables['campaign_id']) ? (int) $user_variables['campaign_id'] : null;
        $subscriber_id = !empty($user_variables['subscriber_id']) ? (int) $user_variables['subscriber_id'] : null;

        $subscriber = $this->subscriber_repo->find_by_email($email);

        if (!$subscriber) {
            return [
                'success' => true,
                'message' => __('Subscriber not found (ignored)', 'jan-newsletter'),
            ];
        }

        // Use subscriber ID from DB if not in metadata
        if (!$subscriber_id) {
            $subscriber_id = $subscriber->id;
        }

        switch ($event_type) {
            case 'bounced':
            case 'failed':
                $severity = $event_data['severity'] ?? 'permanent';
                $bounce_type = ($severity === 'permanent') ? 'hard' : 'soft';
                $this->handle_bounce($subscriber, $bounce_type);
                if ($campaign_id && $subscriber_id) {
                    $this->stats_repo->record([
                        'campaign_id' => $campaign_id,
                        'subscriber_id' => $subscriber_id,
                        'email' => $email,
                        'event_type' => 'bounce',
                    ]);
                }
                break;

            case 'complained':
                $this->handle_complaint($subscriber);
                if ($campaign_id && $subscriber_id) {
                    $this->stats_repo->record([
                        'campaign_id' => $campaign_id,
                        'subscriber_id' => $subscriber_id,
                        'email' => $email,
                        'event_type' => 'unsubscribe',
                    ]);
                }
                break;

            case 'unsubscribed':
                $this->handle_unsubscribe($subscriber);
                if ($campaign_id && $subscriber_id) {
                    $this->stats_repo->record([
                        'campaign_id' => $campaign_id,
                        'subscriber_id' => $subscriber_id,
                        'email' => $email,
                        'event_type' => 'unsubscribe',
                    ]);
                }
                break;

            case 'delivered':
                // Delivery confirmation — no subscriber status change needed
                break;

            case 'opened':
                // Fallback open tracking (if tracking pixel was blocked)
                if ($campaign_id && $subscriber_id) {
                    if (!$this->stats_repo->event_exists($campaign_id, $subscriber_id, 'open')) {
                        $this->stats_repo->record([
                            'campaign_id' => $campaign_id,
                            'subscriber_id' => $subscriber_id,
                            'email' => $email,
                            'event_type' => 'open',
                        ]);
                    }
                }
                break;

            case 'clicked':
                // Fallback click tracking
                if ($campaign_id && $subscriber_id) {
                    $url = $event_data['url'] ?? '';
                    $this->stats_repo->record([
                        'campaign_id' => $campaign_id,
                        'subscriber_id' => $subscriber_id,
                        'email' => $email,
                        'event_type' => 'click',
                        'link_url' => $url,
                    ]);
                    // Also record open if not yet
                    if (!$this->stats_repo->event_exists($campaign_id, $subscriber_id, 'open')) {
                        $this->stats_repo->record([
                            'campaign_id' => $campaign_id,
                            'subscriber_id' => $subscriber_id,
                            'email' => $email,
                            'event_type' => 'open',
                        ]);
                    }
                }
                break;
        }

        return [
            'success' => true,
            'message' => sprintf(__('Event "%s" processed', 'jan-newsletter'), $event_type),
        ];
    }

    /**
     * Process SendGrid webhook
     */
    public function process_sendgrid(array $events): array {
        $processed = 0;

        foreach ($events as $event) {
            $event_type = $event['event'] ?? '';
            $email = $event['email'] ?? '';

            if (empty($email)) {
                continue;
            }

            $subscriber = $this->subscriber_repo->find_by_email($email);

            if (!$subscriber) {
                continue;
            }

            switch ($event_type) {
                case 'bounce':
                    $type = $event['type'] ?? '';
                    $bounce_type = ($type === 'blocked') ? 'soft' : 'hard';
                    $this->handle_bounce($subscriber, $bounce_type);
                    $processed++;
                    break;

                case 'dropped':
                    $this->handle_bounce($subscriber, 'hard');
                    $processed++;
                    break;

                case 'spamreport':
                    $this->handle_complaint($subscriber);
                    $processed++;
                    break;

                case 'unsubscribe':
                    $this->handle_unsubscribe($subscriber);
                    $processed++;
                    break;
            }
        }

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %d: number of processed events */
                __('%d events processed', 'jan-newsletter'),
                $processed
            ),
            'processed' => $processed,
        ];
    }

    /**
     * Handle bounce
     */
    private function handle_bounce($subscriber, string $type): void {
        $subscriber->mark_bounced($type);
        $this->subscriber_repo->update($subscriber->id, [
            'bounce_status' => $subscriber->bounce_status,
            'bounce_count' => $subscriber->bounce_count,
            'status' => $subscriber->status,
        ]);
    }

    /**
     * Handle spam complaint
     */
    private function handle_complaint($subscriber): void {
        $subscriber->bounce_status = 'complaint';
        $subscriber->status = 'unsubscribed';
        $this->subscriber_repo->update($subscriber->id, [
            'bounce_status' => 'complaint',
            'status' => 'unsubscribed',
        ]);
    }

    /**
     * Handle unsubscribe (from email provider)
     */
    private function handle_unsubscribe($subscriber): void {
        $subscriber->status = 'unsubscribed';
        $this->subscriber_repo->update($subscriber->id, ['status' => 'unsubscribed']);
    }

    /**
     * Verify Mailgun webhook signature
     */
    private function verify_mailgun_signature(array $data): bool {
        $signing_key = Plugin::get_option('mailgun_signing_key', '');

        if (empty($signing_key)) {
            // If no key configured, skip verification (not recommended for production)
            return true;
        }

        $signature = $data['signature'] ?? [];
        $timestamp = $signature['timestamp'] ?? '';
        $token = $signature['token'] ?? '';
        $received_signature = $signature['signature'] ?? '';

        if (empty($timestamp) || empty($token) || empty($received_signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $signing_key);

        return hash_equals($expected, $received_signature);
    }

    /**
     * Verify SendGrid webhook signature
     */
    public function verify_sendgrid_signature(string $payload, string $signature, string $timestamp): bool {
        $signing_key = Plugin::get_option('sendgrid_signing_key', '');

        if (empty($signing_key)) {
            return true;
        }

        $data = $timestamp . $payload;
        $expected = base64_encode(hash_hmac('sha256', $data, base64_decode($signing_key), true));

        return hash_equals($expected, $signature);
    }

    /**
     * Manually mark email as bounced
     */
    public function mark_bounced(string $email, string $type = 'hard'): array {
        $subscriber = $this->subscriber_repo->find_by_email($email);

        if (!$subscriber) {
            return [
                'success' => false,
                'message' => __('Subscriber not found', 'jan-newsletter'),
            ];
        }

        $subscriber->mark_bounced($type);
        $this->subscriber_repo->update($subscriber->id, [
            'bounce_status' => $subscriber->bounce_status,
            'bounce_count' => $subscriber->bounce_count,
            'status' => $subscriber->status,
        ]);

        return [
            'success' => true,
            'message' => __('Subscriber marked as bounced', 'jan-newsletter'),
        ];
    }

    /**
     * Clear bounce status
     */
    public function clear_bounce(string $email): array {
        $subscriber = $this->subscriber_repo->find_by_email($email);

        if (!$subscriber) {
            return [
                'success' => false,
                'message' => __('Subscriber not found', 'jan-newsletter'),
            ];
        }

        $subscriber->bounce_status = 'none';
        $subscriber->bounce_count = 0;
        $subscriber->status = 'subscribed';
        $this->subscriber_repo->update($subscriber->id, [
            'bounce_status' => 'none',
            'bounce_count' => 0,
            'status' => 'subscribed',
        ]);

        return [
            'success' => true,
            'message' => __('Bounce status cleared', 'jan-newsletter'),
        ];
    }
}
