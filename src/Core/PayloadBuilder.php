<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

/**
 * Turns an order snapshot into the exact bytes sent to the ERP, plus the
 * idempotency key that identifies "this order in this state".
 *
 * The JSON is canonical (object keys sorted recursively), so the same order
 * state always produces the same body, hash and key regardless of the order
 * in which the snapshot array was assembled.
 */
final class PayloadBuilder
{
    public const SCHEMA_VERSION = 1;

    // Non-ASCII is \u-escaped on purpose: the body is stored in a DB column and the result is
    // pure ASCII, so it survives legacy utf8 (3-byte) tables and any proxy/charset handling.
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    public function __construct(private string $siteId, private string $pluginVersion)
    {
    }

    /**
     * @param array<string,mixed> $order Must contain an integer "id".
     * @return array{body:string,hash:string,idempotency_key:string}
     */
    public function build(array $order): array
    {
        $canonicalOrder = self::canonicalize($order);
        $hash           = hash('sha256', self::encode($canonicalOrder));

        $payload = [
            'schema' => self::SCHEMA_VERSION,
            'event'  => 'order.upsert',
            'source' => [
                'site'           => $this->siteId,
                'plugin_version' => $this->pluginVersion,
            ],
            'order'  => $canonicalOrder,
        ];

        return [
            'body'            => self::encode(self::canonicalize($payload)),
            'hash'            => $hash,
            'idempotency_key' => self::idempotencyKey($this->siteId, (int) $order['id'], $hash),
        ];
    }

    public static function idempotencyKey(string $siteId, int $orderId, string $hash): string
    {
        return 'wcosr-' . $orderId . '-' . substr(hash('sha256', $siteId . '|' . $orderId . '|' . $hash), 0, 24);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function canonicalize($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out    = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonicalize($item);
        }

        if (! $isList) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function encode($value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }
}
