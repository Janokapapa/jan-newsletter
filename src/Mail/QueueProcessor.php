<?php

namespace JanNewsletter\Mail;

use JanNewsletter\Plugin;
use JanNewsletter\Models\QueuedEmail;
use JanNewsletter\Repositories\QueueRepository;
use JanNewsletter\Repositories\CampaignRepository;
use JanNewsletter\Repositories\StatsRepository;

/**
 * Queue processor - runs via cron
 */
class QueueProcessor {
    private QueueRepository $queue_repo;
    private CampaignRepository $campaign_repo;
    private StatsRepository $stats_repo;
    private SmtpTransport $smtp;

    public function __construct() {
        $this->queue_repo = new QueueRepository();
        $this->campaign_repo = new CampaignRepository();
        $this->stats_repo = new StatsRepository();
        $this->smtp = new SmtpTransport();
    }

    /**
     * Initialize cron hook
     */
    public function init(): void {
        add_action('jan_newsletter_process_queue', [$this, 'process']);
    }

    /**
     * Process the email queue
     */
    public function process(): void {
        // Record last run time
        update_option('jan_newsletter_cron_last_run', current_time('mysql'), false);

        // Check if processing is enabled
        if (!Plugin::get_option('smtp_enabled', false)) {
            return;
        }

        $batch_size = (int) Plugin::get_option('queue_batch_size', 50);

        // Get next batch
        $emails = $this->queue_repo->get_next_batch($batch_size);

        if (empty($emails)) {
            return;
        }

        $processed = 0;
        $sent = 0;
        $failed = 0;

        foreach ($emails as $email) {
            $processed++;

            // Mark as processing
            $this->queue_repo->mark_processing($email->id);

            // Send the email
            $result = $this->send_email($email);

            if ($result['success']) {
                $this->queue_repo->mark_sent($email->id);
                $this->log_email($email, 'sent', $result['response'] ?? '');
                $sent++;

                // Update campaign stats if applicable
                if ($email->campaign_id) {
                    $this->campaign_repo->increment_sent_count($email->campaign_id);

                    // Record stat
                    if ($email->subscriber_id) {
                        $this->stats_repo->record([
                            'campaign_id' => $email->campaign_id,
                            'subscriber_id' => $email->subscriber_id,
                            'email' => $email->to_email,
                            'event_type' => 'sent',
                        ]);
                    }
                }
            } else {
                $this->queue_repo->mark_failed($email->id, $result['message']);
                $this->log_email($email, 'failed', $result['message']);
                $failed++;
            }
        }

        // Check and finalize campaigns
        $this->finalize_campaigns();

        // Log processing results
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Jan Newsletter] Processed %d emails: %d sent, %d failed',
                $processed,
                $sent,
                $failed
            ));
        }
    }

    /**
     * Process queue manually (via admin)
     */
    public function process_now(): array {
        $start_time = microtime(true);

        $this->process();

        $duration = round(microtime(true) - $start_time, 2);

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %s: duration in seconds */
                __('Queue processed in %s seconds', 'jan-newsletter'),
                $duration
            ),
            'stats' => $this->queue_repo->get_stats(),
        ];
    }

    /**
     * Send a single email
     */
    private function send_email(QueuedEmail $email): array {
        $result = $this->smtp->send(
            $email->to_email,
            $email->subject,
            $email->body_html ?? '',
            $email->body_text,
            $email->from_email,
            $email->from_name,
            $email->get_headers_array(),
            $email->get_attachments_array()
        );

        if ($result) {
            return [
                'success' => true,
                'response' => $this->smtp->get_last_response(),
            ];
        }

        return [
            'success' => false,
            'message' => $this->smtp->get_last_error(),
        ];
    }

    /**
     * Log email result with full content
     */
    private function log_email(QueuedEmail $email, string $status, string $response): void {
        global $wpdb;

        $table = $wpdb->prefix . 'jan_nl_logs';

        $wpdb->insert($table, [
            'queue_id' => $email->id,
            'to_email' => $email->to_email,
            'from_email' => $email->from_email,
            'from_name' => $email->from_name,
            'subject' => $email->subject,
            'body_html' => $email->body_html,
            'body_text' => $email->body_text,
            'headers' => $email->headers,
            'status' => $status,
            'smtp_response' => $response,
            'source' => $email->source,
            'campaign_id' => $email->campaign_id,
            'sent_at' => current_time('mysql'),
        ]);
    }

    /**
     * Check and finalize campaigns that are done sending
     */
    private function finalize_campaigns(): void {
        $sending_campaigns = $this->campaign_repo->get_sending();

        foreach ($sending_campaigns as $campaign) {
            // Check if all emails for this campaign are processed
            $pending = $this->queue_repo->count([
                'campaign_id' => $campaign->id,
                'status' => ['pending', 'processing'],
            ]);

            if ($pending === 0) {
                $this->campaign_repo->update_status($campaign->id, 'sent');
            }
        }
    }
}
