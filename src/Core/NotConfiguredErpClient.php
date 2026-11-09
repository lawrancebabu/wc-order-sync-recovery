<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

/**
 * Used while the ERP URL / shared secret are missing or invalid. Orders are still queued
 * (nothing is lost); attempts fail as retryable, and once the settings are fixed an
 * operator can "Retry all failed" from the dashboard.
 */
final class NotConfiguredErpClient implements ErpClient
{
    public function send(Job $job): ErpResponse
    {
        return new ErpResponse(0, '', [], 'ERP URL or shared secret is not configured (or the URL is not allowed).');
    }
}
