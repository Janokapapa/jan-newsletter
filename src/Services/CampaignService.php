<?php

namespace JanNewsletter\Services;

use JanNewsletter\Plugin;
use JanNewsletter\Models\Campaign;
use JanNewsletter\Repositories\CampaignRepository;
use JanNewsletter\Repositories\SubscriberRepository;
use JanNewsletter\Repositories\QueueRepository;
use JanNewsletter\Repositories\ListRepository;
use JanNewsletter\Mail\Mailer;
use JanNewsletter\Mail\TrackingPixel;

/**
 * Campaign service - business logic
 */
class CampaignService {
    private CampaignRepository $campaign_repo;
    private SubscriberRepository $subscriber_repo;
    private QueueRepository $queue_repo;
    private ListRepository $list_repo;
    private Mailer $mailer;
    private SubscriberService $subscriber_service;

    public function __construct() {
        $this->campaign_repo = new CampaignRepository();
        $this->subscriber_repo = new SubscriberRepository();
        $this->queue_repo = new QueueRepository();
        $this->list_repo = new ListRepository();
        $this->mailer = new Mailer();
        $this->subscriber_service = new SubscriberService();
    }

    /**
     * Create new campaign
     */
    public function create(array $data): array {
        $campaign = new Campaign();
        $campaign->name = sanitize_text_field($data['name'] ?? '');
        $campaign->subject = sanitize_text_field($data['subject'] ?? '');
        $campaign->body_html = $data['body_html'] ?? '';
        $campaign->body_text = $data['body_text'] ?? '';
        $campaign->from_name = sanitize_text_field($data['from_name'] ?? Plugin::get_option('from_name', ''));
        $campaign->from_email = sanitize_email($data['from_email'] ?? Plugin::get_option('from_email', ''));
        $campaign->list_id = !empty($data['list_id']) ? (int) $data['list_id'] : null;
        $campaign->status = 'draft';

        if (empty($campaign->name)) {
            return [
                'success' => false,
                'message' => __('Campaign name is required', 'jan-newsletter'),
            ];
        }

        $id = $this->campaign_repo->insert($campaign);
        $campaign->id = $id;

        return [
            'success' => true,
            'message' => __('Campaign created', 'jan-newsletter'),
            'campaign' => $this->campaign_repo->find($id),
        ];
    }

    /**
     * Update campaign
     */
    public function update(int $id, array $data): array {
        $campaign = $this->campaign_repo->find($id);

        if (!$campaign) {
            return [
                'success' => false,
                'message' => __('Campaign not found', 'jan-newsletter'),
            ];
        }

        if (!$campaign->can_edit()) {
            return [
                'success' => false,
                'message' => __('Campaign cannot be edited', 'jan-newsletter'),
            ];
        }

        // Update fields
        if (isset($data['name'])) {
            $campaign->name = sanitize_text_field($data['name']);
        }
        if (isset($data['subject'])) {
            $campaign->subject = sanitize_text_field($data['subject']);
        }
        if (isset($data['body_html'])) {
            $campaign->body_html = $data['body_html'];
        }
        if (isset($data['body_text'])) {
            $campaign->body_text = $data['body_text'];
        }
        if (isset($data['from_name'])) {
            $campaign->from_name = sanitize_text_field($data['from_name']);
        }
        if (isset($data['from_email'])) {
            $campaign->from_email = sanitize_email($data['from_email']);
        }
        if (isset($data['list_id'])) {
            $campaign->list_id = !empty($data['list_id']) ? (int) $data['list_id'] : null;
        }

        $this->campaign_repo->update($campaign);

        return [
            'success' => true,
            'message' => __('Campaign updated', 'jan-newsletter'),
            'campaign' => $this->campaign_repo->find($id),
        ];
    }

    /**
     * Delete campaign
     */
    public function delete(int $id): array {
        $campaign = $this->campaign_repo->find($id);

        if (!$campaign) {
            return [
                'success' => false,
                'message' => __('Campaign not found', 'jan-newsletter'),
            ];
        }

        // Cancel any pending emails
        if ($campaign->status === 'sending') {
            $this->queue_repo->cancel_campaign_emails($id);
        }

        $this->campaign_repo->delete($id);

        return [
            'success' => true,
            'message' => __('Campaign deleted', 'jan-newsletter'),
        ];
    }

    /**
     * Schedule campaign
     */
    public function schedule(int $id, string $scheduled_at): array {
        $campaign = $this->campaign_repo->find($id);

        if (!$campaign) {
            return [
                'success' => false,
                'message' => __('Campaign not found', 'jan-newsletter'),
            ];
        }

        if (!$campaign->can_send()) {
            return [
                'success' => false,
                'message' => __('Campaign cannot be scheduled', 'jan-newsletter'),
            ];
        }

        $validation = $this->validate_for_sending($campaign);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['message'],
            ];
        }

        $campaign->status = 'scheduled';
        $campaign->scheduled_at = $scheduled_at;
        $this->campaign_repo->update($campaign);

        return [
            'success' => true,
            'message' => __('Campaign scheduled', 'jan-newsletter'),
            'campaign' => $this->campaign_repo->find($id),
        ];
    }

    /**
     * Send campaign immediately
     */
    public function send(int $id): array {
        $campaign = $this->campaign_repo->find($id);

        if (!$campaign) {
            return [
                'success' => false,
                'message' => __('Campaign not found', 'jan-newsletter'),
            ];
        }

        if (!$campaign->can_send()) {
            return [
                'success' => false,
                'message' => __('Campaign cannot be sent', 'jan-newsletter'),
            ];
        }

        $validation = $this->validate_for_sending($campaign);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['message'],
            ];
        }

        // Get subscribers
        $subscribers = $this->subscriber_repo->get_by_list($campaign->list_id);

        if (empty($subscribers)) {
            return [
                'success' => false,
                'message' => __('No active subscribers in this list', 'jan-newsletter'),
            ];
        }

        // Update campaign status
        $campaign->status = 'sending';
        $campaign->total_recipients = count($subscribers);
        $campaign->sent_count = 0;
        $campaign->started_at = current_time('mysql');
        $this->campaign_repo->update($campaign);

        // Queue emails
        $queued = $this->queue_campaign_emails($campaign, $subscribers);

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %d: number of emails */
                __('%d emails queued for sending', 'jan-newsletter'),
                $queued
            ),
            'campaign' => $this->campaign_repo->find($id),
            'queued' => $queued,
        ];
    }

    /**
     * Pause campaign
     */
    public function pause(int $id): array {
        $campaign = $this->campaign_repo->find($id);

        if (!$campaign) {
            return [
                'success' => false,
                'message' => __('Campaign not found', 'jan-newsletter'),
            ];
        }

        if (!$campaign->can_pause()) {
            return [
                'success' => false,
                'message' => __('Campaign cannot be paused', 'jan-newsletter'),
            ];
        }

        $campaign->status = 'paused';
        $this->campaign_repo->update($campaign);

        // Cancel pending emails
        $cancelled = $this->queue_repo->cancel_campaign_emails($id);

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %d: number of cancelled emails */
                __('Campaign paused. %d pending emails cancelled.', 'jan-newsletter'),
                $cancelled
            ),
            'campaign' => $this->campaign_repo->find($id),
        ];
    }

    /**
     * Resume paused campaign
     */
    public function resume(int $id): array {
        $campaign = $this->campaign_repo->find($id);

        if (!$campaign) {
            return [
                'success' => false,
                'message' => __('Campaign not found', 'jan-newsletter'),
            ];
        }

        if ($campaign->status !== 'paused') {
            return [
                'success' => false,
                'message' => __('Campaign is not paused', 'jan-newsletter'),
            ];
        }

        // Re-queue remaining emails
        return $this->send($id);
    }

    /**
     * Send test email
     */
    public function send_test(int $id, string $test_email): array {
        $campaign = $this->campaign_repo->find($id);

        if (!$campaign) {
            return [
                'success' => false,
                'message' => __('Campaign not found', 'jan-newsletter'),
            ];
        }

        if (!is_email($test_email)) {
            return [
                'success' => false,
                'message' => __('Invalid email address', 'jan-newsletter'),
            ];
        }

        $subject = sprintf(
            /* translators: %s: campaign subject */
            __('[TEST] %s', 'jan-newsletter'),
            $campaign->subject
        );

        // Prepare body (without real tracking)
        $body_html = $campaign->body_html ?? '';

        // Add unsubscribe link placeholder
        $body_html = $this->add_unsubscribe_link($body_html, '[unsubscribe_link]');

        $result = $this->mailer->send_now(
            $test_email,
            $subject,
            $body_html,
            $campaign->body_text,
            $campaign->from_email,
            $campaign->from_name
        );

        if ($result['success']) {
            return [
                'success' => true,
                'message' => sprintf(
                    /* translators: %s: email address */
                    __('Test email sent to %s', 'jan-newsletter'),
                    $test_email
                ),
            ];
        }

        return [
            'success' => false,
            'message' => $result['message'],
        ];
    }

    /**
     * Queue campaign emails for all subscribers
     */
    private function queue_campaign_emails(Campaign $campaign, array $subscribers): int {
        $queued = 0;

        foreach ($subscribers as $subscriber) {
            // Prepare personalized content
            $body_html = $this->personalize_content($campaign->body_html ?? '', $subscriber);
            $body_text = $this->personalize_content($campaign->body_text ?? '', $subscriber);

            // Add tracking
            $body_html = TrackingPixel::inject($body_html, $campaign->id, $subscriber->id);
            $body_html = TrackingPixel::wrap_links($body_html, $campaign->id, $subscriber->id);

            // Add unsubscribe link
            $unsubscribe_url = $this->subscriber_service->get_unsubscribe_url($subscriber);
            $body_html = $this->add_unsubscribe_link($body_html, $unsubscribe_url);
            $body_text = $this->add_unsubscribe_text($body_text, $unsubscribe_url);

            // RFC 8058 one-click unsubscribe headers
            $headers = [];
            if (Plugin::get_option('one_click_unsubscribe', true)) {
                $headers['List-Unsubscribe'] = '<' . $unsubscribe_url . '>';
                $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
            }

            // Queue email
            $this->mailer->queue(
                $subscriber->email,
                $campaign->subject,
                $body_html,
                $body_text,
                $campaign->from_email,
                $campaign->from_name,
                $headers,
                [],
                10, // Bulk priority
                'campaign',
                $subscriber->id,
                $campaign->id
            );

            $queued++;
        }

        return $queued;
    }

    /**
     * Personalize content with subscriber data
     */
    private function personalize_content(string $content, $subscriber): string {
        $replacements = [
            '{{email}}' => $subscriber->email,
            '{{first_name}}' => $subscriber->first_name,
            '{{last_name}}' => $subscriber->last_name,
            '{{name}}' => $subscriber->get_full_name(),
            '{email}' => $subscriber->email,
            '{first_name}' => $subscriber->first_name,
            '{last_name}' => $subscriber->last_name,
            '{name}' => $subscriber->get_full_name(),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );
    }

    /**
     * Add unsubscribe link to HTML
     */
    private function add_unsubscribe_link(string $html, string $url): string {
        // Replace placeholder if exists
        if (strpos($html, '[unsubscribe_link]') !== false) {
            return str_replace('[unsubscribe_link]', $url, $html);
        }

        // Otherwise, add before </body> or at end
        $unsubscribe_html = '<p style="text-align:center;font-size:12px;color:#6b7280;margin-top:30px;">'
            . '<a href="' . esc_url($url) . '" style="color:#6b7280;">'
            . esc_html__('Unsubscribe', 'jan-newsletter')
            . '</a></p>';

        if (stripos($html, '</body>') !== false) {
            return str_ireplace('</body>', $unsubscribe_html . '</body>', $html);
        }

        return $html . $unsubscribe_html;
    }

    /**
     * Add unsubscribe text to plain text email
     */
    private function add_unsubscribe_text(string $text, string $url): string {
        if (strpos($text, '[unsubscribe_link]') !== false) {
            return str_replace('[unsubscribe_link]', $url, $text);
        }

        return $text . "\n\n---\n" . __('Unsubscribe:', 'jan-newsletter') . ' ' . $url;
    }

    /**
     * Validate campaign is ready for sending
     */
    private function validate_for_sending(Campaign $campaign): array {
        if (empty($campaign->subject)) {
            return [
                'valid' => false,
                'message' => __('Campaign subject is required', 'jan-newsletter'),
            ];
        }

        if (empty($campaign->body_html) && empty($campaign->body_text)) {
            return [
                'valid' => false,
                'message' => __('Campaign content is required', 'jan-newsletter'),
            ];
        }

        if (!$campaign->list_id) {
            return [
                'valid' => false,
                'message' => __('Please select a subscriber list', 'jan-newsletter'),
            ];
        }

        $list = $this->list_repo->find($campaign->list_id);
        if (!$list) {
            return [
                'valid' => false,
                'message' => __('Selected list not found', 'jan-newsletter'),
            ];
        }

        return ['valid' => true];
    }

    /**
     * Get campaign preview HTML
     */
    public function get_preview(int $id): ?string {
        $campaign = $this->campaign_repo->find($id);

        if (!$campaign) {
            return null;
        }

        // Create dummy subscriber for preview
        $dummy = (object) [
            'email' => 'preview@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ];
        $dummy->get_full_name = fn() => 'John Doe';

        $html = $this->personalize_content($campaign->body_html ?? '', $dummy);
        $html = $this->add_unsubscribe_link($html, '#unsubscribe');

        return $html;
    }
}
