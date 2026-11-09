<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Wp;

use WCOSR\Core\Scheduler;

/**
 * Background execution through Action Scheduler (bundled with WooCommerce), so jobs run
 * outside the checkout/admin request, are retried by its runner, and are visible under
 * WooCommerce > Status > Scheduled Actions (group "wcosr").
 */
final class ActionSchedulerScheduler implements Scheduler
{
    public const HOOK  = 'wcosr_process_job';
    public const GROUP = 'wcosr';

    public function schedule(int $jobId, int $timestamp): void
    {
        if (! function_exists('as_schedule_single_action')) {
            return; // WooCommerce inactive; the recovery sweep re-schedules once it is back.
        }

        // unique = true: at most one pending action per job id, so repeated calls cannot pile up.
        as_schedule_single_action(max($timestamp, time()), self::HOOK, [$jobId], self::GROUP, true);
    }

    public function isScheduled(int $jobId): bool
    {
        if (! function_exists('as_has_scheduled_action')) {
            return false;
        }

        return (bool) as_has_scheduled_action(self::HOOK, [$jobId], self::GROUP);
    }
}
