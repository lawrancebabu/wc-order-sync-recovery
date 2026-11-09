<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Wp;

use WCOSR\Admin\AdminPage;
use WCOSR\Core\ErpClient;
use WCOSR\Core\EndpointValidator;
use WCOSR\Core\HttpErpClient;
use WCOSR\Core\NotConfiguredErpClient;
use WCOSR\Core\PayloadBuilder;
use WCOSR\Core\RetryPolicy;
use WCOSR\Core\SyncService;
use WCOSR\Core\WebhookHandler;
use WCOSR\Rest\RestController;

/**
 * Wires the framework-free core to WordPress/WooCommerce. Services are built lazily
 * so a request that never syncs pays nothing.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private ?Settings $settings = null;
    private ?WpJobRepository $jobs = null;
    private ?WpEventStore $events = null;
    private ?SyncService $service = null;
    private ?WebhookHandler $webhook = null;
    private ?WcLogger $logger = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        add_filter('cron_schedules', [$this, 'cronSchedules']);
        add_action('init', [Installer::class, 'maybeMigrate']);
        add_action('init', [Installer::class, 'scheduleRecovery']);

        if (! class_exists('WooCommerce')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>'
                    . esc_html__('WooCommerce Order Sync & Recovery requires WooCommerce to be active.', 'wc-order-sync-recovery')
                    . '</p></div>';
            });

            return;
        }

        // Background work.
        add_action(ActionSchedulerScheduler::HOOK, [$this, 'runJob'], 10, 1);
        add_action(Installer::CRON_HOOK, [$this, 'runRecovery']);

        // Triggers.
        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 20, 3);
        add_filter('woocommerce_order_actions', [$this, 'addOrderAction']);
        add_action('woocommerce_order_action_wcosr_sync', [$this, 'handleOrderAction']);

        (new RestController($this))->register();

        if (is_admin()) {
            (new AdminPage($this))->register();
        }
    }

    /**
     * @param array<string,array<string,mixed>> $schedules
     * @return array<string,array<string,mixed>>
     */
    public function cronSchedules(array $schedules): array
    {
        $schedules['wcosr_five_minutes'] = [
            'interval' => 300,
            'display'  => __('Every five minutes', 'wc-order-sync-recovery'),
        ];

        return $schedules;
    }

    // --- service accessors -------------------------------------------------

    public function settings(): Settings
    {
        return $this->settings ??= new Settings();
    }

    public function jobs(): WpJobRepository
    {
        return $this->jobs ??= new WpJobRepository();
    }

    public function events(): WpEventStore
    {
        return $this->events ??= new WpEventStore();
    }

    public function logger(): WcLogger
    {
        return $this->logger ??= new WcLogger();
    }

    public function siteId(): string
    {
        $parts = wp_parse_url(home_url());

        return strtolower(($parts['host'] ?? 'site') . ($parts['path'] ?? ''));
    }

    public function erpClient(): ErpClient
    {
        $s = $this->settings();

        if (! $s->isConfigured()) {
            return new NotConfiguredErpClient();
        }

        return new HttpErpClient(
            (string) $s->get('erp_url'),
            $s->secret(),
            new WpTransport(),
            (int) $s->get('timeout'),
            null,
            '/api/orders',
            '/api/ping',
            WCOSR_VERSION
        );
    }

    public function service(): SyncService
    {
        return $this->service ??= new SyncService(
            $this->jobs(),
            new WcOrderGateway(),
            $this->erpClient(),
            new ActionSchedulerScheduler(),
            new RetryPolicy(30, 3600, 0.2),
            new PayloadBuilder($this->siteId(), WCOSR_VERSION),
            (int) $this->settings()->get('max_attempts'),
            120,
            null,
            $this->logger()
        );
    }

    public function webhook(): WebhookHandler
    {
        return $this->webhook ??= new WebhookHandler(
            $this->jobs(),
            $this->events(),
            new WcOrderGateway(),
            fn(): array => $this->settings()->webhookSecrets(),
            null,
            $this->logger()
        );
    }

    // --- hook callbacks ----------------------------------------------------

    /**
     * @param int|string $orderId
     */
    public function onStatusChanged($orderId, string $from, string $to): void
    {
        $settings = $this->settings();
        if (! $settings->get('enabled') || ! in_array($to, (array) $settings->get('statuses'), true)) {
            return;
        }

        // Queueing must never break checkout or an admin status change.
        try {
            $this->service()->enqueueOrder((int) $orderId);
        } catch (\Throwable $e) {
            $this->logger()->log('error', 'Could not queue order', ['order_id' => (int) $orderId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public function addOrderAction(array $actions): array
    {
        if (current_user_can('manage_woocommerce')) {
            $actions['wcosr_sync'] = __('Sync to ERP now', 'wc-order-sync-recovery');
        }

        return $actions;
    }

    /**
     * @param \WC_Order $order
     */
    public function handleOrderAction($order): void
    {
        if (! current_user_can('manage_woocommerce') || ! $order instanceof \WC_Order) {
            return;
        }

        $this->service()->enqueueOrder($order->get_id(), true);
        $order->add_order_note(__('Manual ERP sync queued.', 'wc-order-sync-recovery'));
    }

    /**
     * @param int|string $jobId
     */
    public function runJob($jobId): void
    {
        $this->service()->process((int) $jobId);
    }

    public function runRecovery(): void
    {
        $this->service()->recover((int) $this->settings()->get('retention_days'));
        $this->events()->prune(time() - 30 * DAY_IN_SECONDS);
    }
}
