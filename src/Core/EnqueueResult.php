<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

final class EnqueueResult
{
    public const CREATED   = 'created';
    public const DUPLICATE = 'duplicate';
    public const NOT_FOUND = 'not_found';

    private function __construct(public string $outcome, public ?Job $job)
    {
    }

    public static function created(Job $job): self
    {
        return new self(self::CREATED, $job);
    }

    public static function duplicate(?Job $existing): self
    {
        return new self(self::DUPLICATE, $existing);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, null);
    }
}
