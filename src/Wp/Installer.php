<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Wp;

final class Installer
{
    public const DB_VERSION = '1';
    public const DB_OPTION  = 'wcosr_db_version';
    public const CRON_HOOK  = 'wcosr_recover';

    public static function jobsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wcosr_jobs';
    }

    public static function eventsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wcosr_events';
    }

    public static function activate(): void
    {
        self::migrate();
        self::scheduleRecovery();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /** Create/upgrade tables when the stored schema version is behind. */
    public static function maybeMigrate(): void
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::migrate();
        }
    }

    public static function migrate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $jobs    = self::jobsTable();
        $events  = self::eventsTable();

        // dbDelta is picky: one column per line, two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$jobs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  idempotency_key varchar(100) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  max_attempts smallint(5) unsigned NOT NULL DEFAULT 5,
  payload longtext NOT NULL,
  next_attempt_at bigint(20) unsigned DEFAULT NULL,
  locked_until bigint(20) unsigned DEFAULT NULL,
  last_error text DEFAULT NULL,
  last_http_status smallint(5) unsigned DEFAULT NULL,
  erp_id varchar(100) DEFAULT NULL,
  failure_type varchar(20) DEFAULT NULL,
  created_at bigint(20) unsigned NOT NULL,
  updated_at bigint(20) unsigned NOT NULL,
  completed_at bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY order_id (order_id),
  KEY status_next (status,next_attempt_at),
  KEY status_locked (status,locked_until)
) {$charset};");

        dbDelta("CREATE TABLE {$events} (
  event_id varchar(128) NOT NULL,
  received_at bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (event_id),
  KEY received_at (received_at)
) {$charset};");

        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function scheduleRecovery(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'wcosr_five_minutes', self::CRON_HOOK);
        }
    }

    public static function uninstall(): void
    {
        global $wpdb;

        $settings = get_option(Settings::OPTION, []);
        if (empty($settings['delete_on_uninstall'])) {
            return; // default: keep the queue history when the plugin is deleted
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query('DROP TABLE IF EXISTS ' . self::jobsTable());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query('DROP TABLE IF EXISTS ' . self::eventsTable());
        delete_option(Settings::OPTION);
        delete_option(self::DB_OPTION);
    }
}
