<?php
/**
 * Sync job value object.
 *
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

/**
 * One unit of work: "deliver this order snapshot to the ERP".
 *
 * Timestamps are unix seconds (UTC) so no time-zone maths is needed anywhere.
 */
final class Job
{
    public const PENDING    = 'pending';
    public const PROCESSING = 'processing';
    public const RETRYING   = 'retrying';
    public const SUCCEEDED  = 'succeeded';
    public const FAILED     = 'failed';

    /** The ERP said no (4xx other than 408/425/429) — retrying will not help. */
    public const FAIL_PERMANENT = 'permanent';
    /** Every allowed attempt was used up on retryable errors. */
    public const FAIL_EXHAUSTED = 'exhausted';
    /** The ERP rejected the order asynchronously through a signed webhook. */
    public const FAIL_REJECTED = 'rejected';

    public const STATUSES = [
        self::PENDING,
        self::PROCESSING,
        self::RETRYING,
        self::SUCCEEDED,
        self::FAILED,
    ];

    public function __construct(
        public int $id,
        public int $orderId,
        public string $idempotencyKey,
        public string $status,
        public int $attempts,
        public int $maxAttempts,
        public string $payload,
        public ?int $nextAttemptAt,
        public ?int $lockedUntil,
        public ?string $lastError,
        public ?int $lastHttpStatus,
        public ?string $erpId,
        public ?string $failureType,
        public int $createdAt,
        public int $updatedAt,
        public ?int $completedAt = null
    ) {
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED], true);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(bool $withPayload = false): array
    {
        $data = [
            'id'               => $this->id,
            'order_id'         => $this->orderId,
            'idempotency_key'  => $this->idempotencyKey,
            'status'           => $this->status,
            'attempts'         => $this->attempts,
            'max_attempts'     => $this->maxAttempts,
            'next_attempt_at'  => $this->nextAttemptAt,
            'last_error'       => $this->lastError,
            'last_http_status' => $this->lastHttpStatus,
            'erp_id'           => $this->erpId,
            'failure_type'     => $this->failureType,
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
            'completed_at'     => $this->completedAt,
        ];

        if ($withPayload) {
            $decoded         = json_decode($this->payload, true);
            $data['payload'] = is_array($decoded) ? $decoded : null;
        }

        return $data;
    }
}
