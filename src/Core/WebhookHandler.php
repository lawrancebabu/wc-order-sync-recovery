<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

/**
 * Inbound, signed ERP -> shop callbacks.
 *
 *   POST { "event_id": "evt_123", "type": "order.synced"|"order.rejected",
 *          "order_id": 456, "erp_id": "ERP-000456", "reason": "..." }
 *
 * Pipeline: size cap -> signature + timestamp window -> schema validation ->
 * order/job lookup -> atomic event-id claim (duplicate protection) -> apply.
 * If applying fails the claim is released so the ERP's own retry is accepted.
 */
final class WebhookHandler
{
    public const MAX_BODY_BYTES = 262144;
    public const TYPE_SYNCED    = 'order.synced';
    public const TYPE_REJECTED  = 'order.rejected';

    /** @var callable():string[] */
    private $secrets;

    /** @var callable():int */
    private $clock;

    private Logger $logger;

    /**
     * @param callable():string[] $secrets Current (and optionally previous) shared secret.
     */
    public function __construct(
        private JobRepository $jobs,
        private EventStore $events,
        private OrderGateway $orders,
        callable $secrets,
        ?callable $clock = null,
        ?Logger $logger = null
    ) {
        $this->secrets = $secrets;
        $this->clock   = $clock ?? 'time';
        $this->logger  = $logger ?? new NullLogger();
    }

    /**
     * Returns null when the request is authentic, otherwise the failure to send back.
     *
     * @param array<string,string> $headers Lower-cased header names.
     */
    public function authenticate(string $body, array $headers): ?WebhookResult
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new WebhookResult(413, 'payload_too_large', 'Payload too large.');
        }

        $valid = Signature::verify(
            $body,
            $headers['x-wcosr-timestamp'] ?? '',
            $headers['x-wcosr-signature'] ?? '',
            ($this->secrets)(),
            $this->now()
        );

        return $valid ? null : new WebhookResult(401, 'invalid_signature', 'Invalid or expired signature.');
    }

    /**
     * @param array<string,string> $headers
     */
    public function handle(string $body, array $headers): WebhookResult
    {
        return $this->authenticate($body, $headers) ?? $this->apply($body);
    }

    /**
     * Apply an already-authenticated event.
     */
    public function apply(string $body): WebhookResult
    {
        $data = json_decode($body, true);
        if (! is_array($data)) {
            return new WebhookResult(400, 'invalid_json', 'Body must be a JSON object.');
        }

        $eventId = $data['event_id'] ?? null;
        $type    = $data['type'] ?? null;
        $orderId = $data['order_id'] ?? null;

        if (
            ! is_string($eventId)
            || $eventId === ''
            || strlen($eventId) > 128
            || ! preg_match('/^[A-Za-z0-9._:\-]+$/', $eventId)
        ) {
            return new WebhookResult(400, 'invalid_event_id', 'event_id must be 1-128 chars of [A-Za-z0-9._:-].');
        }

        if (! in_array($type, [self::TYPE_SYNCED, self::TYPE_REJECTED], true)) {
            return new WebhookResult(400, 'invalid_type', 'Unsupported event type.');
        }

        if (is_string($orderId) && ctype_digit($orderId)) {
            $orderId = (int) $orderId;
        }
        if (! is_int($orderId) || $orderId < 1) {
            return new WebhookResult(400, 'invalid_order_id', 'order_id must be a positive integer.');
        }

        $job = $this->jobs->latestForOrder($orderId);
        if ($job === null) {
            return new WebhookResult(404, 'unknown_order', 'No sync job exists for this order.');
        }

        if (! $this->events->claim($eventId, $this->now())) {
            return new WebhookResult(200, 'duplicate', 'Event already processed.', ['event_id' => $eventId]);
        }

        try {
            $this->applyToJob($job, (string) $type, $data);
        } catch (\Throwable $e) {
            $this->events->release($eventId);
            $this->logger->log('error', 'Webhook processing failed', ['event_id' => $eventId, 'error' => $e->getMessage()]);

            return new WebhookResult(500, 'processing_failed', 'Could not process event; please retry.');
        }

        return new WebhookResult(200, 'ok', 'Event processed.', ['event_id' => $eventId, 'job_id' => $job->id]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function applyToJob(Job $job, string $type, array $data): void
    {
        $now = $this->now();

        if ($type === self::TYPE_SYNCED) {
            $erpId = isset($data['erp_id']) && is_scalar($data['erp_id'])
                ? Text::clean((string) $data['erp_id'], 100)
                : ($job->erpId ?? '');

            $job->status        = Job::SUCCEEDED;
            $job->erpId         = $erpId !== '' ? $erpId : null;
            $job->lastError     = null;
            $job->failureType   = null;
            $job->nextAttemptAt = null;
            $job->lockedUntil   = null;
            $job->completedAt   = $now;
            $job->updatedAt     = $now;
            $this->jobs->save($job);

            if ($erpId !== '') {
                $this->orders->recordSynced($job->orderId, $erpId, $now);
            }
            $this->orders->addNote($job->orderId, 'ERP confirmed the order via webhook' . ($erpId !== '' ? ' (' . $erpId . ').' : '.'));

            return;
        }

        $reason = isset($data['reason']) && is_scalar($data['reason'])
            ? Text::clean((string) $data['reason'], 500)
            : 'no reason given';

        $job->status        = Job::FAILED;
        $job->failureType   = Job::FAIL_REJECTED;
        $job->lastError     = 'Rejected by ERP: ' . $reason;
        $job->nextAttemptAt = null;
        $job->lockedUntil   = null;
        $job->completedAt   = $now;
        $job->updatedAt     = $now;
        $this->jobs->save($job);

        $this->orders->addNote($job->orderId, 'ERP rejected the order via webhook: ' . $reason);
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
