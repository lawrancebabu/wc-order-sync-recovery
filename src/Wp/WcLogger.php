<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Wp;

use WCOSR\Core\Logger;

/**
 * Writes to WooCommerce > Status > Logs (source "wcosr"). Never logs payloads or secrets.
 */
final class WcLogger implements Logger
{
    public function log(string $level, string $message, array $context = []): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }

        $allowed = ['debug', 'info', 'notice', 'warning', 'error'];
        wc_get_logger()->log(
            in_array($level, $allowed, true) ? $level : 'info',
            $message . ($context ? ' ' . wp_json_encode($context) : ''),
            ['source' => 'wcosr']
        );
    }
}
