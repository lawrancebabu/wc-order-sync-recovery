<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

interface Logger
{
    /**
     * @param string              $level   PSR-3 style level: debug, info, warning, error.
     * @param array<string,mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void;
}
