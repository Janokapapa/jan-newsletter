<?php

namespace JanNewsletter\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use JanNewsletter\Repositories\QueueRepository;
use JanNewsletter\Mail\QueueProcessor;

/**
 * REST API Controller for Queue
 */
class QueueController extends WP_REST_Controller {
    protected $namespace = 'jan-newsletter/v1';
    protected $rest_base = 'queue';

    private QueueRepository $repo;

    public function __construct() {
        $this->repo = new QueueRepository();
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /queue
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_items'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => $this->get_collection_params(),
        ]);

        // GET /queue/stats
        register_rest_route($this->namespace, '/' . $this->rest_base . '/stats', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /queue/process
        register_rest_route($this->namespace, '/' . $this->rest_base . '/process', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'process'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /queue/retry-failed
        register_rest_route($this->namespace, '/' . $this->rest_base . '/retry-failed', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'retry_failed'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /queue/cancel-all-pending
        register_rest_route($this->namespace, '/' . $this->rest_base . '/cancel-all-pending', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'cancel_all_pending'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /queue/{id}/cancel
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/cancel', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'cancel'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /queue/{id}/retry
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/retry', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'retry'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // DELETE /queue/{id}
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete_item'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);
    }

    /**
     * Check admin permissions
     */
    public function admin_permissions_check(WP_REST_Request $request): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get queue list
     */
    public function get_items($request) {
        $args = [
            'page' => $request->get_param('page') ?: 1,
            'per_page' => $request->get_param('per_page') ?: 50,
            'status' => $request->get_param('status'),
            'source' => $request->get_param('source'),
            'order_by' => $request->get_param('order_by') ?: 'created_at',
            'order' => $request->get_param('order') ?: 'DESC',
        ];

        $emails = $this->repo->get_all($args);
        $total = $this->repo->count($args);

        $data = array_map(fn($email) => $email->to_api_response(), $emails);

        return new WP_REST_Response([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => (int) $args['page'],
                'per_page' => (int) $args['per_page'],
                'total_pages' => ceil($total / $args['per_page']),
            ],
        ]);
    }

    /**
     * Get queue stats
     */
    public function get_stats(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response($this->repo->get_stats());
    }

    /**
     * Process queue now
     */
    public function process(WP_REST_Request $request): WP_REST_Response {
        $processor = new QueueProcessor();
        $result = $processor->process_now();

        return new WP_REST_Response($result);
    }

    /**
     * Retry all failed emails
     */
    public function retry_failed(WP_REST_Request $request): WP_REST_Response {
        $count = $this->repo->retry_failed();

        return new WP_REST_Response([
            'message' => sprintf(
                /* translators: %d: number of emails */
                __('%d failed emails queued for retry', 'jan-newsletter'),
                $count
            ),
            'count' => $count,
        ]);
    }

    /**
     * Cancel all pending emails
     */
    public function cancel_all_pending(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $cancelled = $this->repo->cancel_all_pending();

        // Reset any campaigns stuck in "sending" status back to "paused"
        $campaigns_table = $wpdb->prefix . 'jan_nl_campaigns';
        $wpdb->query("UPDATE {$campaigns_table} SET status = 'paused' WHERE status = 'sending'");

        return new WP_REST_Response([
            'message' => sprintf(
                __('%d emails cancelled', 'jan-newsletter'),
                $cancelled
            ),
            'cancelled' => $cancelled,
        ]);
    }

    /**
     * Cancel single email
     */
    public function cancel(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');
        $email = $this->repo->find($id);

        if (!$email) {
            return new WP_Error('not_found', __('Queue item not found', 'jan-newsletter'), ['status' => 404]);
        }

        if ($email->status !== 'pending') {
            return new WP_Error('cannot_cancel', __('Only pending emails can be cancelled', 'jan-newsletter'), ['status' => 400]);
        }

        $this->repo->cancel($id);

        return new WP_REST_Response([
            'message' => __('Email cancelled', 'jan-newsletter'),
        ]);
    }

    /**
     * Retry single email
     */
    public function retry(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');
        $email = $this->repo->find($id);

        if (!$email) {
            return new WP_Error('not_found', __('Queue item not found', 'jan-newsletter'), ['status' => 404]);
        }

        if ($email->status !== 'failed') {
            return new WP_Error('cannot_retry', __('Only failed emails can be retried', 'jan-newsletter'), ['status' => 400]);
        }

        $this->repo->update($id, [
            'status' => 'pending',
            'error_message' => null,
        ]);

        return new WP_REST_Response([
            'message' => __('Email queued for retry', 'jan-newsletter'),
        ]);
    }

    /**
     * Delete queue item
     */
    public function delete_item($request) {
        $id = (int) $request->get_param('id');
        $email = $this->repo->find($id);

        if (!$email) {
            return new WP_Error('not_found', __('Queue item not found', 'jan-newsletter'), ['status' => 404]);
        }

        $this->repo->delete($id);

        return new WP_REST_Response([
            'message' => __('Queue item deleted', 'jan-newsletter'),
        ]);
    }

    /**
     * Get collection params
     */
    public function get_collection_params(): array {
        return [
            'page' => [
                'type' => 'integer',
                'default' => 1,
            ],
            'per_page' => [
                'type' => 'integer',
                'default' => 50,
                'maximum' => 100,
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['pending', 'processing', 'sent', 'failed', 'cancelled', 'paused'],
            ],
            'source' => [
                'type' => 'string',
            ],
            'order_by' => [
                'type' => 'string',
                'default' => 'created_at',
            ],
            'order' => [
                'type' => 'string',
                'enum' => ['ASC', 'DESC'],
                'default' => 'DESC',
            ],
        ];
    }
}
