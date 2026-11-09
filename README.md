# WooCommerce Order Sync & Recovery

WordPress/WooCommerce plugin that sends orders to an external ERP **reliably**: background jobs via Action Scheduler, HMAC-signed requests both ways, idempotent de-duplication, exponential backoff retries, and a recovery sweep for stuck workers. Declares High-Performance Order Storage (HPOS) compatibility.

**Author:** [Lawrance Babu Gain](https://github.com/lawrancebabu)

## Requirements

- PHP 8.1+
- WordPress 6.4+
- WooCommerce 8.2+ (HPOS or legacy order storage)
- MySQL/MariaDB

## Install

1. Copy this folder to `wp-content/plugins/wc-order-sync-recovery` (or zip the folder and upload it under **Plugins → Add New**).
2. Activate it while WooCommerce is active. Custom tables are created on activation.
3. Open **WooCommerce → Order Sync → Settings**, set the ERP base URL and a shared secret (16+ characters), then use **Test connection**.

Optional: pin secrets in `wp-config.php` so they never touch the database:

```php
define( 'WCOSR_ERP_SECRET', 'your-long-shared-secret' );
define( 'WCOSR_ERP_PREVIOUS_SECRET', 'old-secret-during-rotation' ); // optional
```

## Features

- **Enqueue on status change** — when an order becomes *Processing* (and via a manual order action), a job row is written to `wp_wcosr_jobs` and handed to Action Scheduler. Checkout is never blocked by the ERP call.
- **Signed outbound delivery** — `POST {erp}/api/orders` with HMAC-SHA256 (`X-WCOSR-Signature` / `X-WCOSR-Timestamp`), `Idempotency-Key`, and a canonical order snapshot.
- **Retries with backoff** — timeouts, 408/425/429/5xx are retried (honours `Retry-After`); other 4xx fail permanently. Exhausted jobs stay visible as *failed* for one-click or bulk retry.
- **Inbound webhooks** — `POST /wp-json/wcosr/v1/webhook` accepts signed `order.synced` / `order.rejected` events with atomic event-id de-duplication.
- **Admin dashboard** — live counts, filterable job list, retry controls, settings, and connection test under **WooCommerce → Order Sync**.
- **REST admin API** — `wcosr/v1` status/jobs/retry/sync/test-connection routes (capability `manage_woocommerce`).

Job states: `pending → processing → succeeded`, or `processing → retrying → … → failed` (`permanent`, `exhausted`, or `rejected`).

## Protocol (short)

**Plugin → ERP:** `POST {base}/api/orders`  
Headers: `Content-Type: application/json`, `Idempotency-Key`, `X-WCOSR-Attempt`, `X-WCOSR-Timestamp`, `X-WCOSR-Signature`.  
Body shape: `{"schema":1,"event":"order.upsert","source":{…},"order":{ "id":123, … }}`. Reply `2xx` with `{"erp_id":"…"}`.

**Signature (both directions):**

```
X-WCOSR-Timestamp: <unix seconds>
X-WCOSR-Signature: v1=<hex( HMAC_SHA256( secret, "<timestamp>.<raw body>" ) )>
```

Reject if the timestamp is more than 300 seconds from your clock. Compare with `hash_equals`.

**ERP → plugin:** `POST /wp-json/wcosr/v1/webhook`

```json
{ "event_id": "evt_123", "type": "order.synced", "order_id": 456, "erp_id": "ERP-000456" }
{ "event_id": "evt_124", "type": "order.rejected", "order_id": 456, "reason": "credit hold" }
```

## Layout

```
wc-order-sync-recovery.php   plugin bootstrap, HPOS declaration
src/Core/                    framework-free sync, signature, retry, webhook logic
src/Wp/                      WordPress/WooCommerce adapters (jobs table, Action Scheduler, settings)
src/Rest/                    REST controller
src/Admin/                   admin page
assets/                      dashboard JS/CSS
uninstall.php                optional cleanup
```

Composer PSR-4 autoload (`WCOSR\`) is supported; a small built-in autoloader works without `vendor/`.

## Extending

```php
add_filter( 'wcosr_order_snapshot', function ( array $snapshot, WC_Order $order ) {
    unset( $snapshot['billing']['phone'] );
    $snapshot['meta']['warehouse'] = $order->get_meta( '_warehouse' );
    return $snapshot;
}, 10, 2 );
```

## Security notes

- Webhook auth is HMAC + timestamp window + event-id de-duplication; secrets compared with `hash_equals`.
- Outbound HTTPS is required (plain HTTP only for loopback unless explicitly allowed); redirects are not followed.
- Payloads and secrets are never written to WooCommerce logs.
- Default payload includes billing/shipping — strip unused PII with `wcosr_order_snapshot`.

## Tests

This package ships the plugin source only. No `tests/` directory or CI workflow is included in this release.

## License

GPL-2.0-or-later. Copyright (C) 2026 Lawrance Babu Gain. See [LICENSE](LICENSE).
