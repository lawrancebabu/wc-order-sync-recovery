<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

/**
 * Decides whether an ERP response is a success, worth retrying, or a dead end,
 * and how long to wait before the next attempt (exponential backoff + jitter).
 */
final class RetryPolicy
{
    public const SUCCESS   = 'success';
    public const RETRY     = 'retry';
    public const PERMANENT = 'permanent';

    /** Never wait longer than this on a server-provided Retry-After. */
    private const MAX_RETRY_AFTER = 21600;

    /** @var callable():float */
    private $rand;

    /**
     * @param callable():float|null $rand Returns a float in [0,1]; injectable for tests.
     */
    public function __construct(
        private int $baseDelay = 30,
        private int $maxDelay = 3600,
        private float $jitter = 0.2,
        ?callable $rand = null
    ) {
        $this->rand = $rand ?? static fn(): float => mt_rand() / mt_getrandmax();
    }

    public function classify(ErpResponse $response): string
    {
        if ($response->error !== null || $response->status === 0) {
            return self::RETRY; // Timeout, DNS, TLS, connection reset ...
        }

        $status = $response->status;

        if ($status >= 200 && $status < 300) {
            return self::SUCCESS;
        }

        // Request Timeout, Too Early, Too Many Requests, and any server error.
        if (in_array($status, [408, 425, 429], true) || $status >= 500) {
            return self::RETRY;
        }

        // Other 4xx (bad payload, bad signature, not found ...) and unfollowed 3xx.
        return self::PERMANENT;
    }

    /**
     * Seconds to wait after the given (1-based) failed attempt.
     *
     * base * 2^(attempt-1), capped at maxDelay, then +/- jitter. A server-provided
     * Retry-After can only lengthen the wait.
     */
    public function delayFor(int $attempt, ?int $retryAfter = null): int
    {
        $attempt = max(1, $attempt);
        $delay   = min($this->maxDelay, $this->baseDelay * (2 ** min($attempt - 1, 20)));

        $factor = 1 + ((($this->rand)() * 2) - 1) * $this->jitter;
        $delay  = max(1, (int) round($delay * $factor));

        if ($retryAfter !== null) {
            $delay = max($delay, min($retryAfter, self::MAX_RETRY_AFTER));
        }

        return $delay;
    }
}
