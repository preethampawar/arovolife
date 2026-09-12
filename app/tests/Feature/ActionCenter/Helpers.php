<?php

declare(strict_types=1);

namespace Tests\Feature\ActionCenter;

use App\Modules\Commerce\Models\Order;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use App\Modules\Returns\Models\ReturnRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Shared fixtures for the Action Center feature tests. Every row here carries
 * only the columns the providers read; foreign keys point at ids that are
 * never created (`disableTestForeignKeys()` in each test's `beforeEach`),
 * since these providers query columns, not relations.
 */
final class Helpers
{
    public static function paidOrder(CarbonInterface $paidAt, ?CarbonInterface $packedAt = null, string $status = Order::STATUS_PAID): Order
    {
        $n = random_int(100000, 999999);

        return Order::create([
            'order_no' => "ORD-AC-{$n}",
            'customer_id' => 1,
            'attribution_source' => 'direct',
            'payment_method' => Order::PAYMENT_ONLINE,
            'status' => $status,
            'subtotal_paise' => 100000, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0,
            'total_paise' => 100000,
            'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
            'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
            'placed_at' => $paidAt, 'paid_at' => $paidAt, 'packed_at' => $packedAt,
            'idempotency_key' => "ac-{$n}-".uniqid(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function order(array $overrides = []): Order
    {
        $n = random_int(100000, 999999);

        return Order::create(array_merge([
            'order_no' => "ORD-AC-{$n}",
            'customer_id' => 1,
            'attribution_source' => 'direct',
            'payment_method' => Order::PAYMENT_ONLINE,
            'status' => Order::STATUS_PLACED,
            'subtotal_paise' => 100000, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0,
            'total_paise' => 100000,
            'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
            'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
            'placed_at' => now(), 'idempotency_key' => "ac-{$n}-".uniqid(),
        ], $overrides));
    }

    /** @return int order_item id */
    public static function orderItem(int $orderId): int
    {
        return (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_variant_id' => 1,
            'product_name_snapshot' => 'Item', 'variant_sku_snapshot' => 'SKU-1', 'hsn_code_snapshot' => '3004',
            'qty' => 1, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
            'taxable_value_paise' => 100000, 'gst_paise' => 0, 'line_total_paise' => 100000,
            'created_at' => now(),
        ]);
    }

    public static function stockMovement(string $type, int $referenceId, int $qty, ?int $batchId = null): StockMovement
    {
        return StockMovement::create([
            'product_variant_id' => 1,
            'warehouse_code' => 'DEFAULT',
            'stock_batch_id' => $batchId,
            'type' => $type,
            'qty' => $qty,
            'unit_cost_paise' => 60000,
            'reference_type' => OrderFulfilmentService::REFERENCE_ORDER_ITEM,
            'reference_id' => $referenceId,
            'occurred_at' => now(),
        ]);
    }

    public static function returnRequest(?CarbonInterface $receivedAt = null, ?CarbonInterface $entitlementsHeldAt = null, ?string $receiptOutcome = null): ReturnRequest
    {
        $n = random_int(100000, 999999);
        $order = self::paidOrder(now()->subDays(10));
        $itemId = self::orderItem($order->id);

        return ReturnRequest::create([
            'rma_no' => "RMA-AC-{$n}",
            'order_id' => $order->id,
            'order_item_id' => $itemId,
            'qty' => 1,
            'reason' => ReturnRequest::REASON_DISSATISFACTION,
            'opened_by_customer_id' => 1,
            'status' => ReturnRequest::STATUS_OPENED,
            'received_at' => $receivedAt,
            'entitlements_held_at' => $entitlementsHeldAt,
            'receipt_outcome' => $receiptOutcome,
        ]);
    }

    public static function stockBatch(?CarbonInterface $expiryDate, int $qtyOnHand = 5): StockBatch
    {
        $n = random_int(100000, 999999);

        return StockBatch::create([
            'product_variant_id' => 1,
            'warehouse_code' => 'DEFAULT',
            'batch_no' => "BATCH-AC-{$n}",
            'expiry_date' => $expiryDate,
            'unit_cost_paise' => 60000,
            'qty_on_hand' => $qtyOnHand,
            'received_at' => now(),
        ]);
    }

    public static function stockTransfer(string $status, ?CarbonInterface $dispatchedAt = null, ?CarbonInterface $receivedAt = null): StockTransfer
    {
        $n = random_int(100000, 999999);

        return StockTransfer::create([
            'transfer_no' => "TRF-AC-{$n}",
            'from_warehouse_code' => 'DEFAULT',
            'to_warehouse_code' => 'SECOND',
            'status' => $status,
            'dispatched_at' => $dispatchedAt,
            'received_at' => $receivedAt,
        ]);
    }

    public static function purchaseInvoiceDraft(CarbonInterface $createdAt): PurchaseInvoice
    {
        $n = random_int(100000, 999999);
        $invoice = PurchaseInvoice::create([
            'grn_no' => "GRN-AC-{$n}",
            'supplier_id' => 1,
            'warehouse_code' => 'DEFAULT',
            'supplier_invoice_no' => "SINV-{$n}",
            'supplier_invoice_date' => $createdAt->toDateString(),
            'status' => PurchaseInvoice::STATUS_DRAFT,
        ]);
        $invoice->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $invoice->fresh();
    }

    /** Gives an order an invoice so `orders.invoice_missing` does not also fire for it. */
    public static function invoiceFor(Order $order): void
    {
        DB::table('invoices')->insert([
            'invoice_no' => 'INV-AC-'.$order->id, 'order_id' => $order->id,
            'issued_at' => now(),
            'seller_state' => 'Telangana', 'buyer_state' => 'Telangana', 'place_of_supply' => 'Telangana',
            'subtotal_paise' => $order->total_paise, 'total_paise' => $order->total_paise,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function returnInspection(int $returnRequestId): void
    {
        DB::table('return_inspections')->insert([
            'return_request_id' => $returnRequestId,
            'received_at' => now(),
            'condition' => 'saleable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function purchaseOrder(string $status, ?CarbonInterface $sentAt = null, ?string $expectedAt = null): PurchaseOrder
    {
        $n = random_int(100000, 999999);

        return PurchaseOrder::create([
            'po_no' => "PO-AC-{$n}",
            'supplier_id' => 1,
            'warehouse_code' => 'DEFAULT',
            'status' => $status,
            'sent_at' => $sentAt,
            'expected_at' => $expectedAt,
        ]);
    }
}
