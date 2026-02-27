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
    private MailgunTransport $mailgun;

    public function __construct() {
        $this->queue_repo = new QueueRepository();
        $this->campaign_repo = new CampaignRepository();
        $this->stats_repo = new StatsRepository();
        $this->smtp = new SmtpTransport();
        $this->mailgun = new MailgunTransport();
    }

    /**
     * Get active transport type
     */
    private function get_transport(): string {
        if (Plugin::get_option('mailgun_enabled', false)) {
            return 'mailgun';
        }
        if (Plugin::get_option('smtp_enabled', false)) {
            return 'smtp';
        }
        return 'wp_mail';
    }

    /**
     * Initialize cron hook
     */
    public function init(): void {
        add_action('jan_newsletter_process_queue', [$this, 'process']);
    }

    /**
     * Process the email queue.
     *
     * @return bool False if another process holds the lock, true if processing ran.
     */
    public function process(): bool {
        // Coarse process-level lock — prevents redundant concurrent executions.
        // Per-row atomic claim in mark_processing() is the definitive safety net.
        if (get_transient('jan_newsletter_queue_lock')) {
            return false;
        }
        set_transient('jan_newsletter_queue_lock', true, 5 * MINUTE_IN_SECONDS);

        try {
            return $this->do_process();
        } finally {
            delete_transient('jan_newsletter_queue_lock');
        }
    }

    /**
     * Internal: perform the actual queue processing (called within lock).
     */
    private function do_process(): bool {
        // Record last run time
        update_option('jan_newsletter_cron_last_run', current_time('mysql'), false);

        // Check if a transport is enabled
        $transport = $this->get_transport();
        if ($transport === 'wp_mail') {
            return true;
        }

        // Recover emails stuck in 'processing' from crashed prior runs
        $this->queue_repo->recover_stale_processing();

        $batch_size = (int) Plugin::get_option('queue_batch_size', 50);

        // Get next batch
        $emails = $this->queue_repo->get_next_batch($batch_size);

        if (empty($emails)) {
            return true;
        }

        $processed = 0;
        $sent = 0;
        $failed = 0;

        foreach ($emails as $email) {
            // Atomically claim this email — skip if another process already claimed it
            if (!$this->queue_repo->mark_processing($email->id)) {
                continue;
            }

            $processed++;

            // Send the email
            $result = $this->send_email($email);

            if ($result['success']) {
                if ($this->queue_repo->mark_sent($email->id)) {
                    $this->log_email($email, 'sent', $result['response'] ?? '');
                    $sent++;

                    // Update campaign stats if applicable
                    if ($email->campaign_id) {
                        $this->campaign_repo->increment_sent_count($email->campaign_id);

                        // Record stat — guard against duplicate entries (same pattern as TrackingPixel)
                        if ($email->subscriber_id) {
                            if (!$this->stats_repo->event_exists($email->campaign_id, $email->subscriber_id, 'sent')) {
                                $this->stats_repo->record([
                                    'campaign_id' => $email->campaign_id,
                                    'subscriber_id' => $email->subscriber_id,
                                    'email' => $email->to_email,
                                    'event_type' => 'sent',
                                ]);
                            }
                        }
                    }
                }
                // If mark_sent() returned false, another process already handled this row
            } else {
                if ($this->queue_repo->mark_failed($email->id, $result['message'])) {
                    $this->log_email($email, 'failed', $result['message']);
                    $failed++;
                }
                // If mark_failed() returned false, another process already handled this row
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

        return true;
    }

    /**
     * Process queue manually (via admin)
     */
    public function process_now(): array {
        $start_time = microtime(true);

        $ran = $this->process();

        if (!$ran) {
            return [
                'success' => false,
                'message' => __('Queue is already being processed', 'jan-newsletter'),
                'locked' => true,
                'stats' => $this->queue_repo->get_stats(),
            ];
        }

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
        $transport = $this->get_transport();

        if ($transport === 'mailgun') {
            $metadata = [];
            if ($email->campaign_id) {
                $metadata['campaign_id'] = $email->campaign_id;
            }
            if ($email->subscriber_id) {
                $metadata['subscriber_id'] = $email->subscriber_id;
            }

            $result = $this->mailgun->send(
                $email->to_email,
                $email->subject,
                $email->body_html ?? '',
                $email->body_text,
                $email->from_email,
                $email->from_name,
                $email->get_headers_array(),
                $email->get_attachments_array(),
                $metadata
            );

            if ($result) {
                return [
                    'success' => true,
                    'response' => $this->mailgun->get_last_response(),
                ];
            }

            return [
                'success' => false,
                'message' => $this->mailgun->get_last_error(),
            ];
        }

        // SMTP
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
