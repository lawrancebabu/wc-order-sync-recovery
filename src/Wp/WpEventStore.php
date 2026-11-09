<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Wp;

use WCOSR\Core\EventStore;

/**
 * Webhook de-duplication. The PRIMARY KEY makes "first time seen" an atomic database
 * decision, so two simultaneous deliveries of the same event cannot both win.
 */
final class WpEventStore implements EventStore
{
    public function claim(string $eventId, int $now): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $affected = $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . Installer::eventsTable() . ' (event_id, received_at) VALUES (%s, %d)', $eventId, $now));

        return $affected === 1;
    }

    public function release(string $eventId): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete(Installer::eventsTable(), ['event_id' => $eventId]);
    }

    public function prune(int $olderThan): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $n = $wpdb->query($wpdb->prepare('DELETE FROM ' . Installer::eventsTable() . ' WHERE received_at < %d', $olderThan));

        return is_int($n) ? $n : 0;
    }
}
