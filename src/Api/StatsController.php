<?php

namespace JanNewsletter\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use JanNewsletter\Repositories\SubscriberRepository;
use JanNewsletter\Repositories\ListRepository;
use JanNewsletter\Repositories\CampaignRepository;
use JanNewsletter\Repositories\QueueRepository;
use JanNewsletter\Repositories\StatsRepository;

/**
 * REST API Controller for Dashboard Stats
 */
class StatsController extends WP_REST_Controller {
    protected $namespace = 'jan-newsletter/v1';
    protected $rest_base = 'stats';

    private SubscriberRepository $subscriber_repo;
    private ListRepository $list_repo;
    private CampaignRepository $campaign_repo;
    private QueueRepository $queue_repo;
    private StatsRepository $stats_repo;

    public function __construct() {
        $this->subscriber_repo = new SubscriberRepository();
        $this->list_repo = new ListRepository();
        $this->campaign_repo = new CampaignRepository();
        $this->queue_repo = new QueueRepository();
        $this->stats_repo = new StatsRepository();
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /stats
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_dashboard_stats'],
            'permission_callback' => [$this, 'admin_permissions_check'],
        ]);

        // GET /stats/daily
        register_rest_route($this->namespace, '/' . $this->rest_base . '/daily', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_daily_stats'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => [
                'days' => [
                    'type' => 'integer',
                    'default' => 30,
                    'minimum' => 7,
                    'maximum' => 365,
                ],
            ],
        ]);

        // GET /stats/logs
        register_rest_route($this->namespace, '/' . $this->rest_base . '/logs', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_logs'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => [
                'page' => [
                    'type' => 'integer',
                    'default' => 1,
                ],
                'per_page' => [
                    'type' => 'integer',
                    'default' => 50,
                    'maximum' => 100,
                ],
                'status' => ['type' => 'string'],
            ],
        ]);

        // GET /stats/logs/{id}
        register_rest_route($this->namespace, '/' . $this->rest_base . '/logs/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_log_detail'],
            'permission_callback' => [$this, 'admin_permissions_check'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
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
     * Get dashboard statistics
     */
    public function get_dashboard_stats(WP_REST_Request $request): WP_REST_Response {
        $subscriber_stats = $this->subscriber_repo->get_stats();
        $queue_stats = $this->queue_repo->get_stats();
        $email_stats = $this->stats_repo->get_global_stats();
        $recent_campaigns = $this->campaign_repo->get_recent(5);

        return new WP_REST_Response([
            'subscribers' => $subscriber_stats,
            'queue' => $queue_stats,
            'emails' => $email_stats,
            'lists' => [
                'total' => $this->list_repo->count(),
            ],
            'campaigns' => [
                'total' => $this->campaign_repo->count(),
                'draft' => $this->campaign_repo->count(['status' => 'draft']),
                'sending' => $this->campaign_repo->count(['status' => 'sending']),
                'sent' => $this->campaign_repo->count(['status' => 'sent']),
            ],
            'recent_campaigns' => array_map(fn($c) => $c->to_api_response(), $recent_campaigns),
        ]);
    }

    /**
     * Get daily stats for charts
     */
    public function get_daily_stats(WP_REST_Request $request): WP_REST_Response {
        $days = (int) $request->get_param('days');

        $stats = $this->stats_repo->get_daily_stats($days);

        return new WP_REST_Response([
            'data' => $stats,
        ]);
    }

    /**
     * Get email logs
     */
    public function get_logs(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $page = (int) $request->get_param('page');
        $per_page = (int) $request->get_param('per_page');
        $status = $request->get_param('status');

        $offset = ($page - 1) * $per_page;
        $table = $wpdb->prefix . 'jan_nl_logs';

        $where = '1=1';
        $params = [];

        if ($status) {
            $where .= ' AND status = %s';
            $params[] = $status;
        }

        // Get total
        $total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($total_sql, ...$params))
            : (int) $wpdb->get_var($total_sql);

        // Get data
        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY sent_at DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $logs = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        return new WP_REST_Response([
            'data' => $logs,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page),
            ],
        ]);
    }

    /**
     * Get single log entry with full details
     */
    public function get_log_detail(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'jan_nl_logs';

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));

        if (!$log) {
            return new WP_REST_Response([
                'message' => __('Log entry not found', 'jan-newsletter'),
            ], 404);
        }

        return new WP_REST_Response([
            'data' => $log,
        ]);
    }
}
