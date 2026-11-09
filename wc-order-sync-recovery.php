<?php
/**
 * Plugin Name:       WooCommerce Order Sync & Recovery
 * Description:       Reliably sends WooCommerce orders to an external ERP using background jobs, signed webhooks, duplicate protection, retries and failed-job recovery. HPOS compatible.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Lawrance Babu Gain
 * Author URI:        https://github.com/lawrancebabu
 * Plugin URI:        https://github.com/lawrancebabu/wc-order-sync-recovery
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-order-sync-recovery
 * WC requires at least: 8.2
 *
 * @package WCOSR
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('WCOSR_VERSION', '1.0.0');
define('WCOSR_FILE', __FILE__);
define('WCOSR_DIR', plugin_dir_path(__FILE__));

if (PHP_VERSION_ID < 80100) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('WooCommerce Order Sync & Recovery requires PHP 8.1 or newer.', 'wc-order-sync-recovery')
            . '</p></div>';
    });

    return;
}

// Composer autoloader when present, otherwise a tiny PSR-4 loader so the plugin works as a plain zip.
if (is_readable(WCOSR_DIR . 'vendor/autoload.php')) {
    require_once WCOSR_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (strpos($class, 'WCOSR\\') !== 0) {
            return;
        }
        $file = WCOSR_DIR . 'src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    });
}

// Declare High-Performance Order Storage (custom order tables) compatibility.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WCOSR_FILE, true);
    }
});

register_activation_hook(__FILE__, [\WCOSR\Wp\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [\WCOSR\Wp\Installer::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \WCOSR\Wp\Plugin::instance()->boot();
});
