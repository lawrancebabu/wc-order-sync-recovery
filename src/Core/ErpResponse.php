<?php
/**
 * Normalised ERP HTTP response.
 *
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

final class ErpResponse
{
    /** @var array<string,string> Header names are lower-cased. */
    public array $headers;

    /**
     * @param int                  $status  HTTP status, or 0 when no response was received.
     * @param array<string,string> $headers Response headers.
     * @param string|null          $error   Transport-level error (timeout, DNS, TLS ...).
     */
    public function __construct(
        public int $status,
        public string $body = '',
        array $headers = [],
        public ?string $error = null
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Seconds the server asked us to wait (Retry-After as seconds or HTTP-date).
     */
    public function retryAfter(int $now): ?int
    {
        $value = $this->header('retry-after');
        if ($value === null || $value === '') {
            return null;
        }

        if (ctype_digit(trim($value))) {
            return (int) trim($value);
        }

        $ts = strtotime($value);

        return $ts === false ? null : max(0, $ts - $now);
    }
}
