<?php

namespace JanNewsletter\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use JanNewsletter\Repositories\CampaignRepository;
use JanNewsletter\Repositories\StatsRepository;
use JanNewsletter\Services\CampaignService;

/**
 * REST API Controller for Campaigns
 */
class CampaignsController extends WP_REST_Controller {
    protected $namespace = 'jan-newsletter/v1';
    protected $rest_base = 'campaigns';

    private CampaignRepository $repo;
    private CampaignService $service;
    private StatsRepository $stats_repo;

    public function __construct() {
        $this->repo = new CampaignRepository();
        $this->service = new CampaignService();
        $this->stats_repo = new StatsRepository();
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET/POST /campaigns
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

        // GET/PUT/DELETE /campaigns/{id}
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

        // POST /campaigns/{id}/send
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/send', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'send'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /campaigns/{id}/pause
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/pause', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'pause'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /campaigns/{id}/resume
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/resume', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'resume'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // POST /campaigns/{id}/schedule
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/schedule', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'schedule'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => [
                'scheduled_at' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        // POST /campaigns/{id}/test
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'send_test'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => [
                'email' => [
                    'required' => true,
                    'type' => 'string',
                    'format' => 'email',
                ],
            ],
        ]);

        // GET /campaigns/{id}/stats
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/stats', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // GET /campaigns/{id}/preview
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/preview', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_preview'],
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
     * Get campaigns list
     */
    public function get_items($request) {
        $args = [
            'page' => $request->get_param('page') ?: 1,
            'per_page' => $request->get_param('per_page') ?: 20,
            'status' => $request->get_param('status'),
            'order_by' => $request->get_param('order_by') ?: 'created_at',
            'order' => $request->get_param('order') ?: 'DESC',
        ];

        $campaigns = $this->repo->get_all($args);
        $total = $this->repo->count($args);

        $data = array_map(fn($campaign) => $campaign->to_api_response(), $campaigns);

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
     * Get single campaign
     */
    public function get_item($request) {
        $id = (int) $request->get_param('id');
        $campaign = $this->repo->find($id);

        if (!$campaign) {
            return new WP_Error('not_found', __('Campaign not found', 'jan-newsletter'), ['status' => 404]);
        }

        return new WP_REST_Response($campaign->to_api_response());
    }

    /**
     * Create campaign
     */
    public function create_item($request) {
        $result = $this->service->create([
            'name' => $request->get_param('name'),
            'subject' => $request->get_param('subject') ?? '',
            'body_html' => $request->get_param('body_html') ?? '',
            'body_text' => $request->get_param('body_text') ?? '',
            'from_name' => $request->get_param('from_name'),
            'from_email' => $request->get_param('from_email'),
            'list_id' => $request->get_param('list_id'),
        ]);

        if (!$result['success']) {
            return new WP_Error('create_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'message' => $result['message'],
            'campaign' => $result['campaign']->to_api_response(),
        ], 201);
    }

    /**
     * Update campaign
     */
    public function update_item($request) {
        $id = (int) $request->get_param('id');

        $data = [];
        foreach (['name', 'subject', 'body_html', 'body_text', 'from_name', 'from_email', 'list_id'] as $field) {
            if ($request->has_param($field)) {
                $data[$field] = $request->get_param($field);
            }
        }

        $result = $this->service->update($id, $data);

        if (!$result['success']) {
            return new WP_Error('update_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'message' => $result['message'],
            'campaign' => $result['campaign']->to_api_response(),
        ]);
    }

    /**
     * Delete campaign
     */
    public function delete_item($request) {
        $id = (int) $request->get_param('id');

        $result = $this->service->delete($id);

        if (!$result['success']) {
            return new WP_Error('delete_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response(['message' => $result['message']]);
    }

    /**
     * Send campaign
     */
    public function send(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');

        $result = $this->service->send($id);

        if (!$result['success']) {
            return new WP_Error('send_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'message' => $result['message'],
            'campaign' => $result['campaign']->to_api_response(),
            'queued' => $result['queued'],
        ]);
    }

    /**
     * Pause campaign
     */
    public function pause(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');

        $result = $this->service->pause($id);

        if (!$result['success']) {
            return new WP_Error('pause_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'message' => $result['message'],
            'campaign' => $result['campaign']->to_api_response(),
        ]);
    }

    /**
     * Resume campaign
     */
    public function resume(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');

        $result = $this->service->resume($id);

        if (!$result['success']) {
            return new WP_Error('resume_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'message' => $result['message'],
            'campaign' => $result['campaign']->to_api_response(),
        ]);
    }

    /**
     * Schedule campaign
     */
    public function schedule(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');
        $scheduled_at = $request->get_param('scheduled_at');

        $result = $this->service->schedule($id, $scheduled_at);

        if (!$result['success']) {
            return new WP_Error('schedule_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'message' => $result['message'],
            'campaign' => $result['campaign']->to_api_response(),
        ]);
    }

    /**
     * Send test email
     */
    public function send_test(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');
        $email = $request->get_param('email');

        $result = $this->service->send_test($id, $email);

        if (!$result['success']) {
            return new WP_Error('test_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response(['message' => $result['message']]);
    }

    /**
     * Get campaign stats
     */
    public function get_stats(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');
        $campaign = $this->repo->find($id);

        if (!$campaign) {
            return new WP_Error('not_found', __('Campaign not found', 'jan-newsletter'), ['status' => 404]);
        }

        $stats = $this->stats_repo->get_campaign_stats($id);
        $clicks = $this->stats_repo->get_campaign_clicks($id);
        $timeline = $this->stats_repo->get_campaign_timeline($id);

        return new WP_REST_Response([
            'campaign' => $campaign->to_api_response(),
            'stats' => $stats,
            'clicks' => $clicks,
            'timeline' => $timeline,
        ]);
    }

    /**
     * Get campaign preview HTML
     */
    public function get_preview(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');

        $preview = $this->service->get_preview($id);

        if ($preview === null) {
            return new WP_Error('not_found', __('Campaign not found', 'jan-newsletter'), ['status' => 404]);
        }

        return new WP_REST_Response(['html' => $preview]);
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
                'default' => 20,
                'maximum' => 100,
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['draft', 'scheduled', 'sending', 'sent', 'paused'],
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
            'name' => [
                'required' => true,
                'type' => 'string',
            ],
            'subject' => ['type' => 'string'],
            'body_html' => ['type' => 'string'],
            'body_text' => ['type' => 'string'],
            'from_name' => ['type' => 'string'],
            'from_email' => ['type' => 'string', 'format' => 'email'],
            'list_id' => ['type' => 'integer'],
        ];
    }

    /**
     * Get update params
     */
    private function get_update_params(): array {
        return $this->get_create_params();
    }
}
