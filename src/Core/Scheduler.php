<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

interface Scheduler
{
    /**
     * Run SyncService::process($jobId) at (or after) $timestamp.
     * A timestamp in the past means "as soon as possible".
     */
    public function schedule(int $jobId, int $timestamp): void;

    public function isScheduled(int $jobId): bool;
}
