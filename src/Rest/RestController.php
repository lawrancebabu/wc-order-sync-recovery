<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Rest;

use WCOSR\Core\EnqueueResult;
use WCOSR\Core\HttpErpClient;
use WCOSR\Core\Job;
use WCOSR\Core\WebhookResult;
use WCOSR\Wp\Plugin;
use WCOSR\Wp\WcOrderGateway;

/**
 * REST API, namespace wcosr/v1.
 *
 * Two completely separate trust models:
 *  - POST /webhook is public but HMAC-authenticated (the ERP is not a WordPress user).
 *  - Everything else requires a logged-in user with `manage_woocommerce`. Cookie sessions
 *    are protected by the standard X-WP-Nonce check; Application Passwords also work.
 */
final class RestController
{
    public const NAMESPACE = 'wcosr/v1';

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/webhook', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'webhook'],
            'permission_callback' => [$this, 'authenticateWebhook'],
        ]);

        $admin = [$this, 'canManage'];

        register_rest_route(self::NAMESPACE, '/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'status'],
            'permission_callback' => $admin,
        ]);

        register_rest_route(self::NAMESPACE, '/jobs', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'listJobs'],
            'permission_callback' => $admin,
            'args'                => [
                'status'   => ['type' => 'string', 'enum' => Job::STATUSES],
                'order_id' => ['type' => 'integer', 'minimum' => 1],
                'page'     => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'getJob'],
            'permission_callback' => $admin,
            'args'                => ['id' => ['type' => 'integer', 'minimum' => 1]],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/retry', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'retryJob'],
            'permission_callback' => $admin,
            'args'                => ['id' => ['type' => 'integer', 'minimum' => 1]],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/retry-failed', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'retryFailed'],
            'permission_callback' => $admin,
        ]);

        register_rest_route(self::NAMESPACE, '/orders/(?P<id>\d+)/sync', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'syncOrder'],
            'permission_callback' => $admin,
            'args'                => [
                'id'    => ['type' => 'integer', 'minimum' => 1],
                'force' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/test-connection', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'testConnection'],
            'permission_callback' => $admin,
        ]);
    }

    // --- permissions -------------------------------------------------------

    /**
     * @return true|\WP_Error
     */
    public function canManage()
    {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        return new \WP_Error(
            'wcosr_forbidden',
            __('You are not allowed to manage order sync.', 'wc-order-sync-recovery'),
            ['status' => is_user_logged_in() ? 403 : 401]
        );
    }

    /**
     * Authenticates the ERP's signed request *before* the callback runs, using the raw
     * body exactly as received (re-encoding parsed JSON would break the signature).
     *
     * @return true|\WP_Error
     */
    public function authenticateWebhook(\WP_REST_Request $request)
    {
        $failure = $this->plugin->webhook()->authenticate($request->get_body(), [
            'x-wcosr-timestamp' => (string) $request->get_header('x-wcosr-timestamp'),
            'x-wcosr-signature' => (string) $request->get_header('x-wcosr-signature'),
        ]);

        if ($failure === null) {
            return true;
        }

        // Same generic message for every auth failure: don't tell an attacker which part was wrong.
        return new \WP_Error('wcosr_' . $failure->code, $failure->message, ['status' => $failure->status]);
    }

    // --- callbacks ---------------------------------------------------------

    public function webhook(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->fromWebhookResult($this->plugin->webhook()->apply($request->get_body()));
    }

    public function status(): \WP_REST_Response
    {
        $stats    = $this->plugin->jobs()->stats();
        $settings = $this->plugin->settings();

        return new \WP_REST_Response([
            'counts'               => $stats['counts'],
            'last_success_at'      => $stats['last_success_at'],
            'oldest_waiting_since' => $stats['oldest_waiting_since'],
            'now'                  => time(),
            'configured'           => $settings->isConfigured(),
            'enabled'              => (bool) $settings->get('enabled'),
            'next_recovery_at'     => wp_next_scheduled(\WCOSR\Wp\Installer::CRON_HOOK) ?: null,
            'webhook_url'          => rest_url(self::NAMESPACE . '/webhook'),
        ]);
    }

    public function listJobs(\WP_REST_Request $request): \WP_REST_Response
    {
        $filters = [];
        if ($request->get_param('status')) {
            $filters['status'] = (string) $request->get_param('status');
        }
        if ($request->get_param('order_id')) {
            $filters['order_id'] = (int) $request->get_param('order_id');
        }

        $perPage = (int) $request->get_param('per_page');
        $result  = $this->plugin->jobs()->list($filters, (int) $request->get_param('page'), $perPage);

        $response = new \WP_REST_Response(array_map(fn(Job $j): array => $this->present($j), $result['items']));
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($result['total'] / $perPage)));

        return $response;
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function getJob(\WP_REST_Request $request)
    {
        $job = $this->plugin->jobs()->find((int) $request['id']);

        return $job === null ? $this->notFound() : new \WP_REST_Response($this->present($job, true));
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function retryJob(\WP_REST_Request $request)
    {
        $id = (int) $request['id'];
        if ($this->plugin->jobs()->find($id) === null) {
            return $this->notFound();
        }

        if (! $this->plugin->service()->retry($id)) {
            return new \WP_Error('wcosr_not_failed', __('Only failed jobs can be retried.', 'wc-order-sync-recovery'), ['status' => 409]);
        }

        return new \WP_REST_Response(['retried' => true, 'job' => $this->present($this->plugin->jobs()->find($id))]);
    }

    public function retryFailed(): \WP_REST_Response
    {
        return new \WP_REST_Response(['retried' => $this->plugin->service()->retryAllFailed()]);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function syncOrder(\WP_REST_Request $request)
    {
        $result = $this->plugin->service()->enqueueOrder((int) $request['id'], (bool) $request->get_param('force'));

        if ($result->outcome === EnqueueResult::NOT_FOUND) {
            return new \WP_Error('wcosr_order_not_found', __('Order not found.', 'wc-order-sync-recovery'), ['status' => 404]);
        }

        return new \WP_REST_Response(
            ['outcome' => $result->outcome, 'job' => $result->job ? $this->present($result->job) : null],
            $result->outcome === EnqueueResult::CREATED ? 201 : 200
        );
    }

    public function testConnection(): \WP_REST_Response
    {
        $client = $this->plugin->erpClient();

        if (! $client instanceof HttpErpClient) {
            return new \WP_REST_Response([
                'ok'      => false,
                'message' => __('Set a valid ERP URL and a shared secret (16+ characters) first.', 'wc-order-sync-recovery'),
            ]);
        }

        $response = $client->ping();
        $ok       = $response->status >= 200 && $response->status < 300;

        if ($ok) {
            $message = __('Connected: the ERP accepted our signature.', 'wc-order-sync-recovery');
        } elseif ($response->status === 401 || $response->status === 403) {
            $message = __('The ERP rejected the signature: the shared secret differs on the two sides.', 'wc-order-sync-recovery');
        } elseif ($response->error !== null) {
            $message = sprintf(__('Could not reach the ERP: %s', 'wc-order-sync-recovery'), $response->error);
        } else {
            $message = sprintf(__('Unexpected ERP response: HTTP %d', 'wc-order-sync-recovery'), $response->status);
        }

        return new \WP_REST_Response(['ok' => $ok, 'status' => $response->status, 'message' => $message]);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function present(Job $job, bool $withPayload = false): array
    {
        $data               = $job->toArray($withPayload);
        $data['order_url']  = WcOrderGateway::editUrl($job->orderId);

        return $data;
    }

    private function fromWebhookResult(WebhookResult $result): \WP_REST_Response
    {
        return new \WP_REST_Response(
            ['code' => $result->code, 'message' => $result->message] + $result->data,
            $result->status
        );
    }

    private function notFound(): \WP_Error
    {
        return new \WP_Error('wcosr_not_found', __('Job not found.', 'wc-order-sync-recovery'), ['status' => 404]);
    }
}
