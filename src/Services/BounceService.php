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

        $subscriber = $this->subscriber_repo->find_by_email($email);

        if (!$subscriber) {
            return [
                'success' => true,
                'message' => __('Subscriber not found (ignored)', 'jan-newsletter'),
            ];
        }

        switch ($event_type) {
            case 'bounced':
            case 'failed':
                $severity = $event_data['severity'] ?? 'permanent';
                $bounce_type = ($severity === 'permanent') ? 'hard' : 'soft';
                $this->handle_bounce($subscriber, $bounce_type);
                break;

            case 'complained':
                $this->handle_complaint($subscriber);
                break;

            case 'unsubscribed':
                $this->handle_unsubscribe($subscriber);
                break;
        }

        return [
            'success' => true,
            'message' => __('Event processed', 'jan-newsletter'),
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
