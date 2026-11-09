<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

/**
 * The state machine behind the plugin:
 *
 *   enqueueOrder()  -> job "pending" (deduplicated by idempotency key) + scheduled
 *   process()       -> claim (atomic) -> send -> succeeded | retrying | failed
 *   retry*()        -> failed -> pending (manual recovery)
 *   recover()       -> crash recovery: expired locks and lost schedules
 *
 * Nothing here talks to WordPress directly, so all of it is unit-tested.
 */
final class SyncService
{
    /** @var callable():int */
    private $clock;

    private Logger $logger;

    public function __construct(
        private JobRepository $jobs,
        private OrderGateway $orders,
        private ErpClient $client,
        private Scheduler $scheduler,
        private RetryPolicy $policy,
        private PayloadBuilder $payloads,
        private int $maxAttempts = 5,
        private int $lockSeconds = 120,
        ?callable $clock = null,
        ?Logger $logger = null
    ) {
        $this->clock  = $clock ?? 'time';
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Queue an order for delivery.
     *
     * Duplicate protection: the key is derived from site + order id + a hash of the
     * order content, so repeated hooks/clicks for an unchanged order create no second
     * job. $force adds a timestamp so an operator can deliberately re-send; the ERP
     * should treat the payload as an upsert on order.id.
     */
    public function enqueueOrder(int $orderId, bool $force = false): EnqueueResult
    {
        $snapshot = $this->orders->snapshot($orderId);
        if ($snapshot === null) {
            return EnqueueResult::notFound();
        }

        $built = $this->payloads->build($snapshot);
        $now   = $this->now();
        $key   = $built['idempotency_key'] . ($force ? '-r' . $now : '');

        $job = $this->jobs->create($orderId, $key, $built['body'], $this->maxAttempts, $now);
        if ($job === null) {
            $this->logger->log('info', 'Duplicate sync ignored', ['order_id' => $orderId, 'key' => $key]);

            return EnqueueResult::duplicate($this->jobs->findByKey($key));
        }

        $this->scheduler->schedule($job->id, $now);

        return EnqueueResult::created($job);
    }

    /**
     * Run one job. Safe to call concurrently or repeatedly for the same id:
     * only the caller that wins the atomic claim does any work.
     */
    public function process(int $jobId): ?Job
    {
        $job = $this->jobs->claim($jobId, $this->now(), $this->lockSeconds);
        if ($job === null) {
            return null; // Finished, not due yet, or another worker owns it.
        }

        try {
            $response = $this->client->send($job);
        } catch (\Throwable $e) {
            $response = new ErpResponse(0, '', [], 'Exception: ' . Text::clean($e->getMessage(), 300));
        }

        $now                 = $this->now();
        $job->lockedUntil    = null;
        $job->lastHttpStatus = $response->status > 0 ? $response->status : null;
        $job->updatedAt      = $now;

        switch ($this->policy->classify($response)) {
            case RetryPolicy::SUCCESS:
                return $this->markSucceeded($job, $response, $now);

            case RetryPolicy::PERMANENT:
                return $this->markFailed($job, $this->describe($response), Job::FAIL_PERMANENT, $now, Job::PROCESSING);

            default:
                return $this->retryOrFail($job, $response, $now);
        }
    }

    /** Operator action: failed -> pending, attempts reset, run now. */
    public function retry(int $jobId): bool
    {
        $now = $this->now();
        if (! $this->jobs->resetForRetry($jobId, $now)) {
            return false;
        }

        $this->scheduler->schedule($jobId, $now);

        return true;
    }

    /** Operator action: retry every failed job (bounded per call). */
    public function retryAllFailed(int $limit = 500): int
    {
        $now = $this->now();
        $ids = $this->jobs->resetAllFailed($now, $limit);

        foreach ($ids as $id) {
            $this->scheduler->schedule($id, $now);
        }

        return count($ids);
    }

    /**
     * Self-healing sweep, run from WP-Cron every few minutes (independent of the queue
     * runner, so it still works when the queue itself is what broke).
     *
     * 1. A worker that died mid-send leaves a job "processing" with an expired lock.
     *    That attempt already counted, so the job is retried or, if out of attempts, failed.
     * 2. Due jobs with no scheduled action (lost schedule, cleared queue) are re-scheduled.
     * 3. Old succeeded jobs are pruned.
     *
     * @return array{reclaimed:int,rescheduled:int,pruned:int}
     */
    public function recover(int $retentionDays = 90): array
    {
        $now       = $this->now();
        $reclaimed = 0;
        $resched   = 0;

        foreach ($this->jobs->reclaimStale($now, 100) as $job) {
            ++$reclaimed;

            if ($job->attempts >= $job->maxAttempts) {
                $this->markFailed(
                    $job,
                    'Worker stopped before finishing the last attempt (crash or timeout).',
                    Job::FAIL_EXHAUSTED,
                    $now,
                    Job::RETRYING
                );
                continue;
            }

            $this->scheduler->schedule($job->id, $now);
        }

        // A one-minute grace period keeps us out of the way of the normal queue.
        foreach ($this->jobs->dueJobs($now - 60, 100) as $job) {
            if (! $this->scheduler->isScheduled($job->id)) {
                $this->scheduler->schedule($job->id, $now);
                ++$resched;
            }
        }

        $pruned = $retentionDays > 0
            ? $this->jobs->pruneSucceeded($now - $retentionDays * 86400)
            : 0;

        if ($reclaimed > 0 || $resched > 0) {
            $this->logger->log('warning', 'Recovery sweep repaired jobs', [
                'reclaimed'   => $reclaimed,
                'rescheduled' => $resched,
            ]);
        }

        return ['reclaimed' => $reclaimed, 'rescheduled' => $resched, 'pruned' => $pruned];
    }

    private function markSucceeded(Job $job, ErpResponse $response, int $now): Job
    {
        $body  = $response->json() ?? [];
        $erpId = $body['erp_id'] ?? ($body['id'] ?? null);

        $job->status         = Job::SUCCEEDED;
        $job->erpId          = is_scalar($erpId) ? Text::clean((string) $erpId, 100) : null;
        $job->lastError      = null;
        $job->failureType    = null;
        $job->nextAttemptAt  = null;
        $job->completedAt    = $now;

        if (! $this->jobs->save($job, Job::PROCESSING)) {
            return $this->conflict($job);
        }

        try {
            if ($job->erpId !== null && $job->erpId !== '') {
                $this->orders->recordSynced($job->orderId, $job->erpId, $now);
            }
            $this->orders->addNote($job->orderId, sprintf(
                'Order synced to ERP%s (attempt %d).',
                $job->erpId ? ' as ' . $job->erpId : '',
                $job->attempts
            ));
        } catch (\Throwable $e) {
            // The ERP has the order; a bookkeeping hiccup must not turn that into a failure.
            $this->logger->log('error', 'Could not record sync result on order', [
                'order_id' => $job->orderId,
                'error'    => $e->getMessage(),
            ]);
        }

        return $job;
    }

    private function retryOrFail(Job $job, ErpResponse $response, int $now): Job
    {
        $error = $this->describe($response);

        if ($job->attempts >= $job->maxAttempts) {
            return $this->markFailed($job, $error, Job::FAIL_EXHAUSTED, $now, Job::PROCESSING);
        }

        $delay = $this->policy->delayFor($job->attempts, $response->retryAfter($now));

        $job->status        = Job::RETRYING;
        $job->lastError     = $error;
        $job->nextAttemptAt = $now + $delay;

        if (! $this->jobs->save($job, Job::PROCESSING)) {
            return $this->conflict($job);
        }

        $this->scheduler->schedule($job->id, $job->nextAttemptAt);
        $this->logger->log('warning', 'Sync attempt failed, will retry', [
            'job_id'  => $job->id,
            'attempt' => $job->attempts,
            'delay'   => $delay,
            'error'   => $error,
        ]);

        return $job;
    }

    private function markFailed(Job $job, string $error, string $type, int $now, string $expectedStatus): Job
    {
        $job->status        = Job::FAILED;
        $job->failureType   = $type;
        $job->lastError     = $error;
        $job->nextAttemptAt = null;
        $job->lockedUntil   = null;
        $job->completedAt   = $now;
        $job->updatedAt     = $now;

        if (! $this->jobs->save($job, $expectedStatus)) {
            return $this->conflict($job);
        }

        try {
            $this->orders->addNote($job->orderId, sprintf(
                'ERP sync failed (%s) after %d attempt(s): %s. Use WooCommerce > Order Sync to retry.',
                $type,
                $job->attempts,
                $error
            ));
        } catch (\Throwable $e) {
            $this->logger->log('error', 'Could not add failure note', ['error' => $e->getMessage()]);
        }

        $this->logger->log('error', 'Sync job failed', ['job_id' => $job->id, 'type' => $type, 'error' => $error]);

        return $job;
    }

    /** Someone else changed the job while we were sending (e.g. a webhook completed it). Their state wins. */
    private function conflict(Job $job): Job
    {
        $this->logger->log('warning', 'Job changed during processing; result discarded', ['job_id' => $job->id]);

        return $this->jobs->find($job->id) ?? $job;
    }

    private function describe(ErpResponse $response): string
    {
        if ($response->error !== null) {
            return Text::clean($response->error);
        }

        $snippet = Text::clean($response->body, 500);

        return 'HTTP ' . $response->status . ($snippet !== '' ? ': ' . $snippet : '');
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
