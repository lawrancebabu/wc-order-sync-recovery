<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Wp;

use WCOSR\Core\Job;
use WCOSR\Core\JobRepository;

/**
 * MySQL implementation. Concurrency safety comes from single-statement atomic
 * UPDATEs (claim, reclaim) and a UNIQUE key on idempotency_key (INSERT IGNORE),
 * never from read-then-write sequences.
 */
final class WpJobRepository implements JobRepository
{
    private const COLUMNS = 'id, order_id, idempotency_key, status, attempts, max_attempts, payload, next_attempt_at, locked_until, last_error, last_http_status, erp_id, failure_type, created_at, updated_at, completed_at';

    private function table(): string
    {
        return Installer::jobsTable();
    }

    public function create(int $orderId, string $idempotencyKey, string $payload, int $maxAttempts, int $now): ?Job
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $affected = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table()}
                (order_id, idempotency_key, status, attempts, max_attempts, payload, next_attempt_at, created_at, updated_at)
             VALUES (%d, %s, %s, 0, %d, %s, %d, %d, %d)",
            $orderId,
            $idempotencyKey,
            Job::PENDING,
            $maxAttempts,
            $payload,
            $now,
            $now,
            $now
        ));
        // phpcs:enable

        if ($affected !== 1) {
            return null; // duplicate key (or DB error): caller treats it as "already queued"
        }

        return $this->find((int) $wpdb->insert_id);
    }

    public function find(int $id): ?Job
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row($wpdb->prepare('SELECT ' . self::COLUMNS . " FROM {$this->table()} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function findByKey(string $idempotencyKey): ?Job
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row($wpdb->prepare('SELECT ' . self::COLUMNS . " FROM {$this->table()} WHERE idempotency_key = %s", $idempotencyKey), ARRAY_A);

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function latestForOrder(int $orderId): ?Job
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row($wpdb->prepare('SELECT ' . self::COLUMNS . " FROM {$this->table()} WHERE order_id = %d ORDER BY id DESC LIMIT 1", $orderId), ARRAY_A);

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function claim(int $id, int $now, int $lockSeconds): ?Job
    {
        global $wpdb;

        // One atomic statement: of N concurrent workers exactly one sees "1 row affected".
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()}
                SET status = %s, attempts = attempts + 1, locked_until = %d, updated_at = %d
              WHERE id = %d
                AND status IN (%s, %s)
                AND (next_attempt_at IS NULL OR next_attempt_at <= %d)",
            Job::PROCESSING,
            $now + $lockSeconds,
            $now,
            $id,
            Job::PENDING,
            Job::RETRYING,
            $now
        ));
        // phpcs:enable

        return $affected === 1 ? $this->find($id) : null;
    }

    public function save(Job $job, ?string $expectedStatus = null): bool
    {
        global $wpdb;

        $where = ['id' => $job->id];
        if ($expectedStatus !== null) {
            $where['status'] = $expectedStatus;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->update(
            $this->table(),
            [
                'status'           => $job->status,
                'attempts'         => $job->attempts,
                'next_attempt_at'  => $job->nextAttemptAt,
                'locked_until'     => $job->lockedUntil,
                'last_error'       => $job->lastError,
                'last_http_status' => $job->lastHttpStatus,
                'erp_id'           => $job->erpId,
                'failure_type'     => $job->failureType,
                'updated_at'       => $job->updatedAt,
                'completed_at'     => $job->completedAt,
            ],
            $where
        );

        if ($result === false) {
            return false;
        }

        // With an expected status the row always changes on success (status moves on), so 0 means
        // "someone else got there first". Without one, 0 can simply mean "nothing changed".
        return $expectedStatus === null || $result > 0;
    }

    public function reclaimStale(int $now, int $limit): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE status = %s AND locked_until IS NOT NULL AND locked_until < %d ORDER BY id ASC LIMIT %d",
            Job::PROCESSING,
            $now,
            $limit
        ));

        $out = [];
        foreach ($ids as $id) {
            // Re-check the condition inside the UPDATE so a worker that just finished is never clobbered.
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table()}
                    SET status = %s, next_attempt_at = %d, locked_until = NULL, last_error = %s, updated_at = %d
                  WHERE id = %d AND status = %s AND locked_until IS NOT NULL AND locked_until < %d",
                Job::RETRYING,
                $now,
                'Worker lock expired (the previous attempt did not finish).',
                $now,
                (int) $id,
                Job::PROCESSING,
                $now
            ));
            if ($affected === 1 && ($job = $this->find((int) $id)) !== null) {
                $out[] = $job;
            }
        }
        // phpcs:enable

        return $out;
    }

    public function dueJobs(int $dueBefore, int $limit): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table()}
              WHERE status IN (%s, %s) AND (next_attempt_at IS NULL OR next_attempt_at <= %d)
              ORDER BY next_attempt_at ASC LIMIT %d",
            Job::PENDING,
            Job::RETRYING,
            $dueBefore,
            $limit
        ), ARRAY_A);

        return array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []);
    }

    public function list(array $filters, int $page, int $perPage): array
    {
        global $wpdb;

        $where  = ['1=1'];
        $params = [];
        if (isset($filters['status']) && in_array($filters['status'], Job::STATUSES, true)) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }
        if (isset($filters['order_id']) && $filters['order_id'] > 0) {
            $where[]  = 'order_id = %d';
            $params[] = (int) $filters['order_id'];
        }
        $whereSql = implode(' AND ', $where);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $countSql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$whereSql}";
        $total    = (int) ($params === [] ? $wpdb->get_var($countSql) : $wpdb->get_var($wpdb->prepare($countSql, $params)));

        // The (potentially large) payload is not needed for list views.
        $columns = str_replace('payload,', "'' AS payload,", self::COLUMNS);
        $sql     = "SELECT {$columns} FROM {$this->table()} WHERE {$whereSql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows    = $wpdb->get_results(
            $wpdb->prepare($sql, array_merge($params, [$perPage, max(0, ($page - 1) * $perPage)])),
            ARRAY_A
        );
        // phpcs:enable

        return [
            'items' => array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []),
            'total' => $total,
        ];
    }

    public function stats(): array
    {
        global $wpdb;

        $counts = array_fill_keys(Job::STATUSES, 0);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status", ARRAY_A);
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['n'];
            }
        }

        $last   = $wpdb->get_var($wpdb->prepare("SELECT MAX(completed_at) FROM {$this->table()} WHERE status = %s", Job::SUCCEEDED));
        $oldest = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(next_attempt_at) FROM {$this->table()} WHERE status IN (%s, %s)",
            Job::PENDING,
            Job::RETRYING
        ));
        // phpcs:enable

        return [
            'counts'               => $counts,
            'last_success_at'      => $last !== null ? (int) $last : null,
            'oldest_waiting_since' => $oldest !== null ? (int) $oldest : null,
        ];
    }

    public function resetForRetry(int $id, int $now): bool
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()}
                SET status = %s, attempts = 0, next_attempt_at = %d, locked_until = NULL,
                    failure_type = NULL, completed_at = NULL, updated_at = %d
              WHERE id = %d AND status = %s",
            Job::PENDING,
            $now,
            $now,
            $id,
            Job::FAILED
        ));
        // phpcs:enable

        return $affected === 1;
    }

    public function resetAllFailed(int $now, int $limit): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->table()} WHERE status = %s ORDER BY id ASC LIMIT %d", Job::FAILED, $limit));

        $reset = [];
        foreach ($ids as $id) {
            if ($this->resetForRetry((int) $id, $now)) {
                $reset[] = (int) $id;
            }
        }

        return $reset;
    }

    public function pruneSucceeded(int $olderThan): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $n = $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE status = %s AND completed_at IS NOT NULL AND completed_at < %d", Job::SUCCEEDED, $olderThan));

        return is_int($n) ? $n : 0;
    }

    /**
     * @param array<string,mixed> $r
     */
    private static function hydrate(array $r): Job
    {
        $int = static fn($v): ?int => $v === null ? null : (int) $v;

        return new Job(
            (int) $r['id'],
            (int) $r['order_id'],
            (string) $r['idempotency_key'],
            (string) $r['status'],
            (int) $r['attempts'],
            (int) $r['max_attempts'],
            (string) $r['payload'],
            $int($r['next_attempt_at']),
            $int($r['locked_until']),
            $r['last_error'] === null ? null : (string) $r['last_error'],
            $int($r['last_http_status']),
            $r['erp_id'] === null ? null : (string) $r['erp_id'],
            $r['failure_type'] === null ? null : (string) $r['failure_type'],
            (int) $r['created_at'],
            (int) $r['updated_at'],
            $int($r['completed_at'])
        );
    }
}
