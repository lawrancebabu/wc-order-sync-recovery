<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

/**
 * HMAC-SHA256 request signing, used in both directions (plugin -> ERP and ERP -> plugin).
 *
 *   X-WCOSR-Timestamp: 1767225600
 *   X-WCOSR-Signature: v1=<hex hmac_sha256(secret, "<timestamp>.<raw body>")>
 *
 * Binding the timestamp into the MAC plus a tolerance window stops captured
 * requests being replayed later. The inbound webhook additionally de-duplicates
 * by event id (see WebhookHandler).
 */
final class Signature
{
    public const VERSION          = 'v1';
    public const HEADER_TIMESTAMP = 'X-WCOSR-Timestamp';
    public const HEADER_SIGNATURE = 'X-WCOSR-Signature';
    public const DEFAULT_TOLERANCE = 300;

    public static function sign(string $body, int $timestamp, string $secret): string
    {
        return self::VERSION . '=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * @param string|string[] $secrets One secret, or several to allow zero-downtime rotation.
     * @param string          $signatureHeader May hold several comma-separated "v1=..." values.
     */
    public static function verify(
        string $body,
        string $timestampHeader,
        string $signatureHeader,
        $secrets,
        int $now,
        int $tolerance = self::DEFAULT_TOLERANCE
    ): bool {
        $timestampHeader = trim($timestampHeader);
        if ($timestampHeader === '' || ! ctype_digit($timestampHeader)) {
            return false;
        }

        $timestamp = (int) $timestampHeader;
        if (abs($now - $timestamp) > $tolerance) {
            return false;
        }

        $secrets = array_values(array_filter(
            (array) $secrets,
            static fn($s): bool => is_string($s) && $s !== ''
        ));
        if ($secrets === []) {
            return false;
        }

        $candidates = array_filter(array_map('trim', explode(',', $signatureHeader)), 'strlen');
        if ($candidates === []) {
            return false;
        }

        // Compare against every secret/candidate pair without early exit.
        $valid = false;
        foreach ($secrets as $secret) {
            $expected = self::sign($body, $timestamp, $secret);
            foreach ($candidates as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    $valid = true;
                }
            }
        }

        return $valid;
    }
}
