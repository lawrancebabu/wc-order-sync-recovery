<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

final class WebhookResult
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        public int $status,
        public string $code,
        public string $message,
        public array $data = []
    ) {
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
