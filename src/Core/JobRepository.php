<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

interface JobRepository
{
    /**
     * Insert a job unless one with the same idempotency key already exists.
     *
     * @return Job|null The new job, or null when the key exists (duplicate).
     */
    public function create(int $orderId, string $idempotencyKey, string $payload, int $maxAttempts, int $now): ?Job;

    public function find(int $id): ?Job;

    public function findByKey(string $idempotencyKey): ?Job;

    public function latestForOrder(int $orderId): ?Job;

    /**
     * Atomically move a due pending/retrying job to "processing" and bump attempts.
     * Exactly one concurrent caller can win; everyone else gets null.
     */
    public function claim(int $id, int $now, int $lockSeconds): ?Job;

    /**
     * Persist a job's mutable fields.
     *
     * @param string|null $expectedStatus When set, only write if the stored status still matches (optimistic lock).
     * @return bool Whether the row was written.
     */
    public function save(Job $job, ?string $expectedStatus = null): bool;

    /**
     * Jobs stuck in "processing" with an expired lock (the worker died). They are
     * atomically moved to "retrying" (due now) and returned.
     *
     * @return Job[]
     */
    public function reclaimStale(int $now, int $limit): array;

    /**
     * Pending/retrying jobs whose next_attempt_at is at or before $dueBefore.
     *
     * @return Job[]
     */
    public function dueJobs(int $dueBefore, int $limit): array;

    /**
     * @param array{status?:string,order_id?:int} $filters
     * @return array{items:Job[],total:int}
     */
    public function list(array $filters, int $page, int $perPage): array;

    /**
     * @return array{counts:array<string,int>,last_success_at:?int,oldest_waiting_since:?int}
     */
    public function stats(): array;

    /** failed -> pending with attempts reset. False if the job is not in "failed". */
    public function resetForRetry(int $id, int $now): bool;

    /**
     * @return int[] Ids that were reset.
     */
    public function resetAllFailed(int $now, int $limit): array;

    public function pruneSucceeded(int $olderThan): int;
}
