<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

interface EventStore
{
    /**
     * Atomically remember an inbound webhook event id.
     *
     * @return bool true if this is the first time the id is seen, false for a duplicate.
     */
    public function claim(string $eventId, int $now): bool;

    /** Forget an id (used when processing failed so the sender's retry is accepted). */
    public function release(string $eventId): void;

    public function prune(int $olderThan): int;
}
