<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

final class NullLogger implements Logger
{
    public function log(string $level, string $message, array $context = []): void
    {
    }
}
