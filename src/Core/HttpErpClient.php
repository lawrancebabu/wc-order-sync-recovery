<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

/**
 * Signed JSON-over-HTTP client for the ERP.
 *
 * The actual HTTP call is an injected "transport" callable so the class has no
 * WordPress dependency:
 *
 *   function (string $method, string $url, array $headers, string $body, int $timeout): array
 *     => ['status' => int, 'headers' => array, 'body' => string]   on any HTTP response
 *     => ['error'  => string]                                      on transport failure
 */
final class HttpErpClient implements ErpClient
{
    /** @var callable */
    private $transport;

    /** @var callable():int */
    private $clock;

    public function __construct(
        private string $baseUrl,
        private string $secret,
        callable $transport,
        private int $timeout = 15,
        ?callable $clock = null,
        private string $ordersPath = '/api/orders',
        private string $pingPath = '/api/ping',
        private string $pluginVersion = 'dev'
    ) {
        $this->transport = $transport;
        $this->clock     = $clock ?? 'time';
    }

    public function send(Job $job): ErpResponse
    {
        return $this->post($this->ordersPath, $job->payload, [
            'Idempotency-Key'   => $job->idempotencyKey,
            'X-WCOSR-Attempt'   => (string) $job->attempts,
        ]);
    }

    /**
     * Signed connectivity + secret check used by the "Test connection" button.
     */
    public function ping(): ErpResponse
    {
        $now = ($this->clock)();

        return $this->post($this->pingPath, (string) json_encode(['ping' => $now]));
    }

    /**
     * @param array<string,string> $extraHeaders
     */
    private function post(string $path, string $body, array $extraHeaders = []): ErpResponse
    {
        $timestamp = ($this->clock)();

        $headers = array_merge([
            'Content-Type'                => 'application/json',
            'Accept'                      => 'application/json',
            'User-Agent'                  => 'WCOSR/' . $this->pluginVersion,
            Signature::HEADER_TIMESTAMP   => (string) $timestamp,
            Signature::HEADER_SIGNATURE   => Signature::sign($body, $timestamp, $this->secret),
        ], $extraHeaders);

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        try {
            $result = ($this->transport)('POST', $url, $headers, $body, $this->timeout);
        } catch (\Throwable $e) {
            return new ErpResponse(0, '', [], 'Transport exception: ' . Text::clean($e->getMessage(), 300));
        }

        if (isset($result['error']) && is_string($result['error'])) {
            return new ErpResponse(0, '', [], Text::clean($result['error'], 300));
        }

        return new ErpResponse(
            (int) ($result['status'] ?? 0),
            (string) ($result['body'] ?? ''),
            is_array($result['headers'] ?? null) ? $result['headers'] : []
        );
    }
}
