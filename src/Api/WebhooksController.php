<?php

namespace JanNewsletter\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use JanNewsletter\Services\BounceService;

/**
 * REST API Controller for Webhooks (Mailgun, SendGrid)
 */
class WebhooksController extends WP_REST_Controller {
    protected $namespace = 'jan-newsletter/v1';
    protected $rest_base = 'webhooks';

    private BounceService $bounce_service;

    public function __construct() {
        $this->bounce_service = new BounceService();
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        // POST /webhooks/mailgun
        register_rest_route($this->namespace, '/' . $this->rest_base . '/mailgun', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_mailgun'],
            'permission_callback' => '__return_true', // Public endpoint
        ]);

        // POST /webhooks/sendgrid
        register_rest_route($this->namespace, '/' . $this->rest_base . '/sendgrid', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_sendgrid'],
            'permission_callback' => '__return_true', // Public endpoint
        ]);
    }

    /**
     * Handle Mailgun webhook
     */
    public function handle_mailgun(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $data = $request->get_json_params();

        if (empty($data)) {
            $data = $request->get_body_params();
        }

        if (empty($data)) {
            return new WP_Error('invalid_request', __('Empty request body', 'jan-newsletter'), ['status' => 400]);
        }

        // Log webhook for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Jan Newsletter] Mailgun webhook: ' . wp_json_encode($data));
        }

        $result = $this->bounce_service->process_mailgun($data);

        if (!$result['success']) {
            return new WP_Error('webhook_error', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response(['message' => $result['message']]);
    }

    /**
     * Handle SendGrid webhook
     */
    public function handle_sendgrid(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $events = $request->get_json_params();

        if (!is_array($events) || empty($events)) {
            return new WP_Error('invalid_request', __('Empty or invalid request body', 'jan-newsletter'), ['status' => 400]);
        }

        // Verify signature if provided
        $signature = $request->get_header('X-Twilio-Email-Event-Webhook-Signature');
        $timestamp = $request->get_header('X-Twilio-Email-Event-Webhook-Timestamp');

        if ($signature && $timestamp) {
            $body = $request->get_body();
            if (!$this->bounce_service->verify_sendgrid_signature($body, $signature, $timestamp)) {
                return new WP_Error('invalid_signature', __('Invalid webhook signature', 'jan-newsletter'), ['status' => 401]);
            }
        }

        // Log webhook for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Jan Newsletter] SendGrid webhook: ' . wp_json_encode($events));
        }

        $result = $this->bounce_service->process_sendgrid($events);

        if (!$result['success']) {
            return new WP_Error('webhook_error', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'message' => $result['message'],
            'processed' => $result['processed'],
        ]);
    }
}
