<?php

namespace JanNewsletter\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use JanNewsletter\Repositories\SubscriberRepository;
use JanNewsletter\Services\SubscriberService;
use JanNewsletter\Services\ImportExportService;

/**
 * REST API Controller for Subscribers
 */
class SubscribersController extends WP_REST_Controller {
    protected $namespace = 'jan-newsletter/v1';
    protected $rest_base = 'subscribers';

    private SubscriberRepository $repo;
    private SubscriberService $service;

    public function __construct() {
        $this->repo = new SubscriberRepository();
        $this->service = new SubscriberService();
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /subscribers
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'admin_permissions_check'],
                'args' => $this->get_collection_params(),
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_item'],
                'permission_callback' => [$this, 'admin_permissions_check'],
                'args' => $this->get_create_params(),
            ],
        ]);

        // GET/PUT/DELETE /subscribers/{id}
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_item'],
                'permission_callback' => [$this, 'admin_permissions_check'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_item'],
                'permission_callback' => [$this, 'admin_permissions_check'],
                'args' => $this->get_update_params(),
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_item'],
                'permission_callback' => [$this, 'admin_permissions_check'],
            ],
        ]);

        // POST /subscribers/bulk-delete
        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk-delete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulk_delete'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => [
                'ids' => [
                    'required' => true,
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
        ]);

        // POST /subscribers/bulk-add-to-list
        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk-add-to-list', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulk_add_to_list'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => [
                'ids' => [
                    'required' => true,
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
                'list_id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
            ],
        ]);

        // GET /subscribers/export
        register_rest_route($this->namespace, '/' . $this->rest_base . '/export', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'export'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => [
                'list_id' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
            ],
        ]);

        // POST /subscribers/import
        register_rest_route($this->namespace, '/' . $this->rest_base . '/import', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'import'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // Public subscribe endpoint
        register_rest_route($this->namespace, '/public/subscribe', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'public_subscribe'],
            'permission_callback' => '__return_true',
            'args' => [
                'email' => [
                    'required' => true,
                    'type' => 'string',
                    'format' => 'email',
                ],
                'first_name' => ['type' => 'string'],
                'last_name' => ['type' => 'string'],
                'list_id' => ['type' => 'integer'],
            ],
        ]);
    }

    /**
     * Check admin permissions
     */
    public function admin_permissions_check(WP_REST_Request $request): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get subscribers list
     */
    public function get_items($request) {
        $args = [
            'page' => $request->get_param('page') ?: 1,
            'per_page' => $request->get_param('per_page') ?: 20,
            'status' => $request->get_param('status'),
            'list_id' => $request->get_param('list_id'),
            'search' => $request->get_param('search'),
            'order_by' => $request->get_param('order_by') ?: 'created_at',
            'order' => $request->get_param('order') ?: 'DESC',
        ];

        $subscribers = $this->repo->get_all($args);
        $total = $this->repo->count($args);

        $data = array_map(function ($subscriber) {
            $lists = $this->repo->get_lists($subscriber->id);
            return [
                'id' => $subscriber->id,
                'email' => $subscriber->email,
                'first_name' => $subscriber->first_name,
                'last_name' => $subscriber->last_name,
                'status' => $subscriber->status,
                'source' => $subscriber->source,
                'bounce_status' => $subscriber->bounce_status,
                'lists' => array_map(fn($l) => ['id' => $l->id, 'name' => $l->name], $lists),
                'created_at' => $subscriber->created_at,
            ];
        }, $subscribers);

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
     * Get single subscriber
     */
    public function get_item($request) {
        $id = (int) $request->get_param('id');
        $subscriber = $this->repo->find($id);

        if (!$subscriber) {
            return new WP_Error('not_found', __('Subscriber not found', 'jan-newsletter'), ['status' => 404]);
        }

        $lists = $this->repo->get_lists($id);
        $meta = $this->repo->get_all_meta($id);

        return new WP_REST_Response([
            'id' => $subscriber->id,
            'email' => $subscriber->email,
            'first_name' => $subscriber->first_name,
            'last_name' => $subscriber->last_name,
            'status' => $subscriber->status,
            'source' => $subscriber->source,
            'bounce_status' => $subscriber->bounce_status,
            'bounce_count' => $subscriber->bounce_count,
            'custom_fields' => $subscriber->custom_fields,
            'ip_address' => $subscriber->ip_address,
            'confirmed_at' => $subscriber->confirmed_at,
            'lists' => array_map(fn($l) => ['id' => (int)$l->id, 'name' => $l->name], $lists),
            'meta' => $meta,
            'created_at' => $subscriber->created_at,
            'updated_at' => $subscriber->updated_at,
        ]);
    }

    /**
     * Create subscriber
     */
    public function create_item($request) {
        $result = $this->service->subscribe(
            $request->get_param('email'),
            [
                'first_name' => $request->get_param('first_name') ?? '',
                'last_name' => $request->get_param('last_name') ?? '',
            ],
            $request->get_param('list_id'),
            'admin'
        );

        if (!$result['success']) {
            return new WP_Error('create_failed', $result['message'], ['status' => 400]);
        }

        // Add to additional lists
        $list_ids = $request->get_param('list_ids');
        if ($list_ids && is_array($list_ids) && $result['subscriber']) {
            foreach ($list_ids as $list_id) {
                $this->repo->add_to_list($result['subscriber']->id, (int) $list_id);
            }
        }

        return new WP_REST_Response([
            'message' => $result['message'],
            'subscriber' => $result['subscriber'],
        ], 201);
    }

    /**
     * Update subscriber
     */
    public function update_item($request) {
        $id = (int) $request->get_param('id');

        $result = $this->service->update($id, [
            'email' => $request->get_param('email'),
            'first_name' => $request->get_param('first_name'),
            'last_name' => $request->get_param('last_name'),
            'status' => $request->get_param('status'),
            'custom_fields' => $request->get_param('custom_fields'),
            'list_ids' => $request->get_param('list_ids'),
        ]);

        if (!$result['success']) {
            return new WP_Error('update_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'message' => $result['message'],
            'subscriber' => $result['subscriber'],
        ]);
    }

    /**
     * Delete subscriber
     */
    public function delete_item($request) {
        $id = (int) $request->get_param('id');

        $subscriber = $this->repo->find($id);
        if (!$subscriber) {
            return new WP_Error('not_found', __('Subscriber not found', 'jan-newsletter'), ['status' => 404]);
        }

        $this->repo->delete($id);

        return new WP_REST_Response([
            'message' => __('Subscriber deleted', 'jan-newsletter'),
        ]);
    }

    /**
     * Bulk delete subscribers
     */
    public function bulk_delete(WP_REST_Request $request): WP_REST_Response {
        $ids = $request->get_param('ids');
        $deleted = $this->repo->bulk_delete($ids);

        return new WP_REST_Response([
            'message' => sprintf(
                /* translators: %d: number of deleted subscribers */
                __('%d subscribers deleted', 'jan-newsletter'),
                $deleted
            ),
            'deleted' => $deleted,
        ]);
    }

    /**
     * Bulk add to list
     */
    public function bulk_add_to_list(WP_REST_Request $request): WP_REST_Response {
        $ids = $request->get_param('ids');
        $list_id = (int) $request->get_param('list_id');

        $added = $this->repo->bulk_add_to_list($ids, $list_id);

        return new WP_REST_Response([
            'message' => sprintf(
                /* translators: %d: number of subscribers */
                __('%d subscribers added to list', 'jan-newsletter'),
                $added
            ),
            'added' => $added,
        ]);
    }

    /**
     * Export subscribers
     */
    public function export(WP_REST_Request $request): WP_REST_Response {
        $export_service = new ImportExportService();

        $csv = $export_service->export_csv([
            'list_id' => $request->get_param('list_id'),
            'status' => $request->get_param('status'),
        ]);

        return new WP_REST_Response([
            'csv' => $csv,
            'filename' => 'subscribers-' . date('Y-m-d') . '.csv',
        ]);
    }

    /**
     * Import subscribers
     */
    public function import(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $files = $request->get_file_params();

        if (empty($files['file'])) {
            return new WP_Error('no_file', __('No file uploaded', 'jan-newsletter'), ['status' => 400]);
        }

        $file = $files['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', __('File upload error', 'jan-newsletter'), ['status' => 400]);
        }

        $import_service = new ImportExportService();

        $result = $import_service->import_csv($file['tmp_name'], [
            'list_id' => $request->get_param('list_id'),
            'status' => $request->get_param('status') ?: 'subscribed',
            'skip_existing' => (bool) $request->get_param('skip_existing'),
            'update_existing' => (bool) $request->get_param('update_existing'),
        ]);

        if (!$result['success']) {
            return new WP_Error('import_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response($result);
    }

    /**
     * Public subscribe endpoint
     */
    public function public_subscribe(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->service->subscribe(
            $request->get_param('email'),
            [
                'first_name' => $request->get_param('first_name') ?? '',
                'last_name' => $request->get_param('last_name') ?? '',
            ],
            $request->get_param('list_id'),
            'api'
        );

        if (!$result['success']) {
            return new WP_Error('subscribe_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'message' => $result['message'],
            'pending_confirmation' => $result['pending_confirmation'] ?? false,
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
                'minimum' => 1,
            ],
            'per_page' => [
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['subscribed', 'unsubscribed', 'bounced', 'pending'],
            ],
            'list_id' => [
                'type' => 'integer',
            ],
            'search' => [
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

    /**
     * Get create params
     */
    private function get_create_params(): array {
        return [
            'email' => [
                'required' => true,
                'type' => 'string',
                'format' => 'email',
            ],
            'first_name' => ['type' => 'string'],
            'last_name' => ['type' => 'string'],
            'list_id' => ['type' => 'integer'],
            'list_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * Get update params
     */
    private function get_update_params(): array {
        return [
            'email' => [
                'type' => 'string',
                'format' => 'email',
            ],
            'first_name' => ['type' => 'string'],
            'last_name' => ['type' => 'string'],
            'status' => [
                'type' => 'string',
                'enum' => ['subscribed', 'unsubscribed', 'bounced', 'pending'],
            ],
            'custom_fields' => ['type' => 'object'],
            'list_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
            ],
        ];
    }
}
