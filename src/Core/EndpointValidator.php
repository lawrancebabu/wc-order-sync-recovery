<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

/**
 * Guards the configured ERP base URL: HTTPS only (order data contains personal
 * data), except loopback hosts so the bundled mock ERP works out of the box.
 */
final class EndpointValidator
{
    public static function isAllowed(string $url, bool $allowInsecure = false): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        // Credentials in URLs leak into logs and error messages.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme === 'https') {
            return true;
        }

        if ($scheme === 'http') {
            return $allowInsecure || self::isLoopback($parts['host']);
        }

        return false;
    }

    public static function isLoopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }
}
