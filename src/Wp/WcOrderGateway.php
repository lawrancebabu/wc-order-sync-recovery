<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Wp;

use WCOSR\Core\OrderGateway;

/**
 * HPOS-safe by construction: orders are only ever read and written through the
 * WC_Order CRUD API (wc_get_order(), get_meta(), update_meta_data(), save_meta_data(),
 * add_order_note()). There is no get_post_meta(), no WP_Query on shop_order and no SQL
 * against wp_posts/wp_postmeta, so the same code works whether orders live in the
 * legacy posts tables or in WooCommerce's custom order tables.
 */
final class WcOrderGateway implements OrderGateway
{
    public const META_ERP_ID    = '_wcosr_erp_id';
    public const META_SYNCED_AT = '_wcosr_synced_at';

    public function snapshot(int $orderId): ?array
    {
        $order = wc_get_order($orderId);

        // Refunds are WC_Order_Refund objects; they are represented inside their parent order.
        if (! $order instanceof \WC_Order) {
            return null;
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $product = $item->get_product();
            $items[] = [
                'name'     => $item->get_name(),
                'sku'      => $product ? (string) $product->get_sku() : '',
                'product_id' => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'quantity' => (int) $item->get_quantity(),
                'subtotal' => (string) $item->get_subtotal(),
                'total'    => (string) $item->get_total(),
                'tax'      => (string) $item->get_total_tax(),
            ];
        }

        $snapshot = [
            'id'             => $order->get_id(),
            'number'         => (string) $order->get_order_number(),
            'status'         => $order->get_status(),
            'currency'       => $order->get_currency(),
            'created_at'     => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : null,
            'customer_id'    => $order->get_customer_id(),
            'payment_method' => $order->get_payment_method(),
            'transaction_id' => (string) $order->get_transaction_id(),
            'totals'         => [
                'subtotal' => (string) $order->get_subtotal(),
                'discount' => (string) $order->get_total_discount(),
                'shipping' => (string) $order->get_shipping_total(),
                'tax'      => (string) $order->get_total_tax(),
                'total'    => (string) $order->get_total(),
                'refunded' => (string) $order->get_total_refunded(),
            ],
            'billing'        => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => $order->get_billing_company(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
            ],
            'shipping'       => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'state'      => $order->get_shipping_state(),
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
            ],
            'items'          => $items,
        ];

        /**
         * Filter the order data sent to the ERP (add fields, or strip personal data you don't need).
         * The result must stay JSON-serialisable and keep the integer "id".
         *
         * @param array<string,mixed> $snapshot
         * @param \WC_Order           $order
         */
        $filtered = apply_filters('wcosr_order_snapshot', $snapshot, $order);

        return is_array($filtered) && isset($filtered['id']) ? $filtered : $snapshot;
    }

    public function recordSynced(int $orderId, string $erpId, int $at): void
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            return;
        }

        $order->update_meta_data(self::META_ERP_ID, $erpId);
        $order->update_meta_data(self::META_SYNCED_AT, $at);
        $order->save_meta_data(); // meta only: no status transitions, so no sync hooks re-fire
    }

    public function addNote(int $orderId, string $note): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order) {
            $order->add_order_note($note);
        }
    }

    /**
     * Admin edit URL that is correct for both HPOS and legacy storage.
     */
    public static function editUrl(int $orderId): string
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'get_order_admin_edit_url')) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url($orderId);
        }

        return (string) get_edit_post_link($orderId, 'raw');
    }
}
