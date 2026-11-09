<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Admin;

use WCOSR\Rest\RestController;
use WCOSR\Wp\Plugin;
use WCOSR\Wp\Settings;

/**
 * WooCommerce > Order Sync: live dashboard (JS over the REST API) and a settings tab
 * (WordPress Settings API, so saving is nonce- and capability-checked by core).
 */
final class AdminPage
{
    private const SLUG = 'wcosr';

    private string $hook = '';

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_filter('plugin_action_links_' . plugin_basename(WCOSR_FILE), [$this, 'actionLinks']);
    }

    public function menu(): void
    {
        $this->hook = (string) add_submenu_page(
            'woocommerce',
            __('Order Sync', 'wc-order-sync-recovery'),
            __('Order Sync', 'wc-order-sync-recovery'),
            'manage_woocommerce',
            self::SLUG,
            [$this, 'render']
        );
    }

    /**
     * @param string[] $links
     * @return string[]
     */
    public function actionLinks(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('Dashboard', 'wc-order-sync-recovery') . '</a>');

        return $links;
    }

    public function registerSettings(): void
    {
        register_setting('wcosr_group', Settings::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [$this->plugin->settings(), 'sanitize'],
            'default'           => Settings::defaults(),
        ]);
    }

    public function assets(string $hook): void
    {
        if ($hook !== $this->hook) {
            return;
        }

        wp_enqueue_style('wcosr-admin', plugins_url('assets/admin.css', WCOSR_FILE), [], WCOSR_VERSION);
        wp_enqueue_script('wcosr-admin', plugins_url('assets/admin.js', WCOSR_FILE), ['wp-api-fetch', 'wp-i18n'], WCOSR_VERSION, true);
        wp_localize_script('wcosr-admin', 'WCOSR', [
            'namespace' => RestController::NAMESPACE,
            'i18n'      => [
                'retry'        => __('Retry', 'wc-order-sync-recovery'),
                'retryAll'     => __('Retry all failed', 'wc-order-sync-recovery'),
                'confirmAll'   => __('Retry every failed job now?', 'wc-order-sync-recovery'),
                'empty'        => __('No jobs match this filter.', 'wc-order-sync-recovery'),
                'never'        => __('never', 'wc-order-sync-recovery'),
                'error'        => __('Request failed', 'wc-order-sync-recovery'),
                'testing'      => __('Testing…', 'wc-order-sync-recovery'),
                'prev'         => __('Previous', 'wc-order-sync-recovery'),
                'next'         => __('Next', 'wc-order-sync-recovery'),
            ],
        ]);
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'wc-order-sync-recovery'), 403);
        }

        $tab = isset($_GET['tab']) && $_GET['tab'] === 'settings' ? 'settings' : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification -- read-only tab switch
        $url = static fn(string $t): string => esc_url(add_query_arg(['page' => self::SLUG, 'tab' => $t], admin_url('admin.php')));

        echo '<div class="wrap wcosr"><h1>' . esc_html__('Order Sync & Recovery', 'wc-order-sync-recovery') . '</h1>';
        echo '<nav class="nav-tab-wrapper">';
        printf('<a class="nav-tab %s" href="%s">%s</a>', $tab === 'dashboard' ? 'nav-tab-active' : '', $url('dashboard'), esc_html__('Dashboard', 'wc-order-sync-recovery'));
        printf('<a class="nav-tab %s" href="%s">%s</a>', $tab === 'settings' ? 'nav-tab-active' : '', $url('settings'), esc_html__('Settings', 'wc-order-sync-recovery'));
        echo '</nav>';

        $tab === 'settings' ? $this->renderSettings() : $this->renderDashboard();

        echo '</div>';
    }

    private function renderDashboard(): void
    {
        ?>
        <div id="wcosr-app" aria-live="polite">
            <div id="wcosr-banner"></div>
            <div class="wcosr-cards" id="wcosr-cards"></div>
            <div class="wcosr-toolbar">
                <label for="wcosr-filter"><?php esc_html_e('Status', 'wc-order-sync-recovery'); ?></label>
                <select id="wcosr-filter">
                    <option value=""><?php esc_html_e('All', 'wc-order-sync-recovery'); ?></option>
                    <?php foreach (['pending', 'processing', 'retrying', 'succeeded', 'failed'] as $s) : ?>
                        <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html(ucfirst($s)); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="wcosr-order"><?php esc_html_e('Order #', 'wc-order-sync-recovery'); ?></label>
                <input type="number" min="1" id="wcosr-order" class="small-text">
                <button type="button" class="button" id="wcosr-refresh"><?php esc_html_e('Refresh', 'wc-order-sync-recovery'); ?></button>
                <button type="button" class="button button-primary" id="wcosr-retry-all"></button>
                <span id="wcosr-msg" role="status"></span>
            </div>
            <table class="widefat striped" id="wcosr-table">
                <thead><tr>
                    <th><?php esc_html_e('Job', 'wc-order-sync-recovery'); ?></th>
                    <th><?php esc_html_e('Order', 'wc-order-sync-recovery'); ?></th>
                    <th><?php esc_html_e('Status', 'wc-order-sync-recovery'); ?></th>
                    <th><?php esc_html_e('Attempts', 'wc-order-sync-recovery'); ?></th>
                    <th><?php esc_html_e('ERP ID', 'wc-order-sync-recovery'); ?></th>
                    <th><?php esc_html_e('Next attempt / completed', 'wc-order-sync-recovery'); ?></th>
                    <th><?php esc_html_e('Last error', 'wc-order-sync-recovery'); ?></th>
                    <th></th>
                </tr></thead>
                <tbody></tbody>
            </table>
            <p class="wcosr-pager"><button type="button" class="button" id="wcosr-prev"></button> <span id="wcosr-page"></span> <button type="button" class="button" id="wcosr-next"></button></p>
        </div>
        <?php
    }

    private function renderSettings(): void
    {
        $s        = $this->plugin->settings();
        $all      = $s->all();
        $statuses = wc_get_order_statuses();
        $webhook  = rest_url(RestController::NAMESPACE . '/webhook');
        $name     = Settings::OPTION;
        ?>
        <form method="post" action="options.php" class="wcosr-settings">
            <?php settings_fields('wcosr_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable sync', 'wc-order-sync-recovery'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1" <?php checked(! empty($all['enabled'])); ?>> <?php esc_html_e('Queue orders for the ERP when their status changes', 'wc-order-sync-recovery'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wcosr_erp_url"><?php esc_html_e('ERP base URL', 'wc-order-sync-recovery'); ?></label></th>
                    <td>
                        <input type="url" id="wcosr_erp_url" class="regular-text code" name="<?php echo esc_attr($name); ?>[erp_url]" value="<?php echo esc_attr((string) $all['erp_url']); ?>" placeholder="http://127.0.0.1:8089">
                        <p class="description"><?php esc_html_e('HTTPS is required, except for localhost (the bundled mock ERP).', 'wc-order-sync-recovery'); ?></p>
                        <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[allow_insecure]" value="1" <?php checked(! empty($all['allow_insecure'])); ?>> <?php esc_html_e('Allow insecure HTTP to non-local hosts (not recommended)', 'wc-order-sync-recovery'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wcosr_secret"><?php esc_html_e('Shared secret', 'wc-order-sync-recovery'); ?></label></th>
                    <td>
                        <?php if ($s->secretIsFromConstant()) : ?>
                            <p><code>WCOSR_ERP_SECRET</code> <?php esc_html_e('is defined in wp-config.php and overrides this field.', 'wc-order-sync-recovery'); ?></p>
                        <?php else : ?>
                            <input type="password" id="wcosr_secret" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr($name); ?>[secret]" value="" placeholder="<?php echo $all['secret'] !== '' ? esc_attr__('•••••••• (saved; leave blank to keep)', 'wc-order-sync-recovery') : ''; ?>">
                            <p class="description"><?php esc_html_e('At least 16 characters. Signs requests to the ERP and verifies webhooks from it. Never shown again after saving.', 'wc-order-sync-recovery'); ?></p>
                        <?php endif; ?>
                        <details><summary><?php esc_html_e('Rotating the secret', 'wc-order-sync-recovery'); ?></summary>
                            <p class="description"><?php esc_html_e('Put the old secret here while you switch the ERP over; inbound webhooks signed with either secret are accepted. Clear it when done.', 'wc-order-sync-recovery'); ?></p>
                            <input type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr($name); ?>[previous_secret]" value="<?php echo esc_attr((string) $all['previous_secret']); ?>">
                        </details>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Webhook URL', 'wc-order-sync-recovery'); ?></th>
                    <td><code><?php echo esc_html($webhook); ?></code>
                        <p class="description"><?php esc_html_e('Give this to your ERP. Callbacks must carry X-WCOSR-Timestamp and X-WCOSR-Signature headers (see README).', 'wc-order-sync-recovery'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync when order becomes', 'wc-order-sync-recovery'); ?></th>
                    <td>
                        <?php foreach ($statuses as $key => $label) : $slug = preg_replace('/^wc-/', '', (string) $key); ?>
                            <label class="wcosr-check"><input type="checkbox" name="<?php echo esc_attr($name); ?>[statuses][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, (array) $all['statuses'], true)); ?>> <?php echo esc_html($label); ?></label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wcosr_max"><?php esc_html_e('Max attempts', 'wc-order-sync-recovery'); ?></label></th>
                    <td><input type="number" id="wcosr_max" min="1" max="20" class="small-text" name="<?php echo esc_attr($name); ?>[max_attempts]" value="<?php echo esc_attr((string) $all['max_attempts']); ?>">
                        <p class="description"><?php esc_html_e('Backoff: 30s, 1m, 2m, 4m … capped at 1h, with jitter. After the last attempt the job is marked failed.', 'wc-order-sync-recovery'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wcosr_timeout"><?php esc_html_e('Request timeout (s)', 'wc-order-sync-recovery'); ?></label></th>
                    <td><input type="number" id="wcosr_timeout" min="3" max="60" class="small-text" name="<?php echo esc_attr($name); ?>[timeout]" value="<?php echo esc_attr((string) $all['timeout']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wcosr_ret"><?php esc_html_e('Keep succeeded jobs (days)', 'wc-order-sync-recovery'); ?></label></th>
                    <td><input type="number" id="wcosr_ret" min="0" class="small-text" name="<?php echo esc_attr($name); ?>[retention_days]" value="<?php echo esc_attr((string) $all['retention_days']); ?>"> <span class="description"><?php esc_html_e('0 = keep forever. Failed jobs are never pruned.', 'wc-order-sync-recovery'); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('On uninstall', 'wc-order-sync-recovery'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($name); ?>[delete_on_uninstall]" value="1" <?php checked(! empty($all['delete_on_uninstall'])); ?>> <?php esc_html_e('Delete the job tables and settings when the plugin is deleted', 'wc-order-sync-recovery'); ?></label></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <p><button type="button" class="button" id="wcosr-test"><?php esc_html_e('Test connection (saved settings)', 'wc-order-sync-recovery'); ?></button> <span id="wcosr-test-result" role="status"></span></p>
        <?php
    }
}
