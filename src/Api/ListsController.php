<?php

namespace JanNewsletter\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use JanNewsletter\Models\SubscriberList;
use JanNewsletter\Repositories\ListRepository;

/**
 * REST API Controller for Lists
 */
class ListsController extends WP_REST_Controller {
    protected $namespace = 'jan-newsletter/v1';
    protected $rest_base = 'lists';

    private ListRepository $repo;

    public function __construct() {
        $this->repo = new ListRepository();
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET/POST /lists
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'admin_permissions_check'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_item'],
                'permission_callback' => [$this, 'admin_permissions_check'],
                'args' => $this->get_create_params(),
            ],
        ]);

        // GET/PUT/DELETE /lists/{id}
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
    }

    /**
     * Check admin permissions
     */
    public function admin_permissions_check(WP_REST_Request $request): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get all lists
     */
    public function get_items($request) {
        $lists = $this->repo->get_all();

        $data = array_map(fn($list) => $list->to_api_response(), $lists);

        return new WP_REST_Response([
            'data' => $data,
            'meta' => [
                'total' => count($lists),
            ],
        ]);
    }

    /**
     * Get single list
     */
    public function get_item($request) {
        $id = (int) $request->get_param('id');
        $list = $this->repo->find($id);

        if (!$list) {
            return new WP_Error('not_found', __('List not found', 'jan-newsletter'), ['status' => 404]);
        }

        return new WP_REST_Response($list->to_api_response());
    }

    /**
     * Create list
     */
    public function create_item($request) {
        $name = sanitize_text_field($request->get_param('name'));

        if (empty($name)) {
            return new WP_Error('invalid_name', __('List name is required', 'jan-newsletter'), ['status' => 400]);
        }

        $list = new SubscriberList();
        $list->name = $name;
        $list->slug = sanitize_title($request->get_param('slug') ?: $name);
        $list->description = sanitize_textarea_field($request->get_param('description') ?? '');
        $list->double_optin = (bool) ($request->get_param('double_optin') ?? true);

        $id = $this->repo->insert($list);
        $created_list = $this->repo->find($id);

        return new WP_REST_Response([
            'message' => __('List created', 'jan-newsletter'),
            'list' => $created_list->to_api_response(),
        ], 201);
    }

    /**
     * Update list
     */
    public function update_item($request) {
        $id = (int) $request->get_param('id');
        $list = $this->repo->find($id);

        if (!$list) {
            return new WP_Error('not_found', __('List not found', 'jan-newsletter'), ['status' => 404]);
        }

        if ($request->has_param('name')) {
            $list->name = sanitize_text_field($request->get_param('name'));
        }
        if ($request->has_param('slug')) {
            $list->slug = sanitize_title($request->get_param('slug'));
        }
        if ($request->has_param('description')) {
            $list->description = sanitize_textarea_field($request->get_param('description'));
        }
        if ($request->has_param('double_optin')) {
            $list->double_optin = (bool) $request->get_param('double_optin');
        }

        $this->repo->update($list);
        $updated_list = $this->repo->find($id);

        return new WP_REST_Response([
            'message' => __('List updated', 'jan-newsletter'),
            'list' => $updated_list->to_api_response(),
        ]);
    }

    /**
     * Delete list
     */
    public function delete_item($request) {
        $id = (int) $request->get_param('id');
        $list = $this->repo->find($id);

        if (!$list) {
            return new WP_Error('not_found', __('List not found', 'jan-newsletter'), ['status' => 404]);
        }

        $this->repo->delete($id);

        return new WP_REST_Response([
            'message' => __('List deleted', 'jan-newsletter'),
        ]);
    }

    /**
     * Get create params
     */
    private function get_create_params(): array {
        return [
            'name' => [
                'required' => true,
                'type' => 'string',
            ],
            'slug' => [
                'type' => 'string',
            ],
            'description' => [
                'type' => 'string',
            ],
            'double_optin' => [
                'type' => 'boolean',
                'default' => true,
            ],
        ];
    }

    /**
     * Get update params
     */
    private function get_update_params(): array {
        return [
            'name' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'double_optin' => ['type' => 'boolean'],
        ];
    }
}
