<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

/**
 * Everything the core needs from the shop. The WooCommerce implementation uses
 * only CRUD APIs (never post meta), which is what keeps it HPOS-safe.
 */
interface OrderGateway
{
    /**
     * Plain-array description of an order, or null if it does not exist.
     *
     * @return array<string,mixed>|null
     */
    public function snapshot(int $orderId): ?array;

    public function recordSynced(int $orderId, string $erpId, int $at): void;

    public function addNote(int $orderId, string $note): void;
}
