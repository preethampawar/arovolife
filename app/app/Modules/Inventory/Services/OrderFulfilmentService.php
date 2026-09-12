<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\Exceptions\InsufficientStockException;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * The bridge between an order and the stock it takes — the only class that
 * knows both Commerce and Inventory (plan §4.8).
 *
 * Batches are chosen when someone is physically picking (pack), not when the
 * order is placed: reservation stays the simple counter checkout already
 * keeps, and the ledger records which batch actually left the building.
 *
 * Every method here runs inside the caller's transaction when there is one, so
 * the stock effect commits or rolls back with the order state change it
 * belongs to, and every method is safe to call twice.
 */
final class OrderFulfilmentService
{
    public const REFERENCE_ORDER_ITEM = 'order_item';

    public const REFERENCE_RETURN_REQUEST = 'return_request';

    public const CARRIER_MANUAL = 'MANUAL';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly StockLedger $ledger,
        private readonly InventorySettings $settings,
    ) {}

    /**
     * Whether stock numbers may stop something happening: the InventoryFeature
     * killswitch and the ops-level setting underneath it, both on.
     */
    public function availabilityEnforced(): bool
    {
        return Feature::for(null)->active(InventoryFeature::class) && $this->settings->enforceAvailability();
    }

    /**
     * Pick a paid order: allocate FEFO batches, write one `sale_out` per
     * (item, batch), release the reservation, open the shipment and move the
     * order to `ready_to_ship` ("packed").
     *
     * Idempotent: an order already packed is left exactly as it is.
     *
     * @throws InsufficientStockException when a tracked line cannot be covered from allocatable batches
     * @throws RuntimeException when the order is not paid or the warehouse cannot fulfil orders
     */
    public function pack(Order $order, ?string $warehouseCode, ?int $actorUserId): void
    {
        $this->db->transaction(function () use ($order, $warehouseCode, $actorUserId): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->getAttribute('packed_at') !== null) {
                $order->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            if ($locked->status !== Order::STATUS_PAID) {
                throw new RuntimeException("Cannot pack order {$locked->order_no} in status {$locked->status}.");
            }

            $code = $warehouseCode ?? $this->settings->defaultWarehouseCode();
            if (! $this->canFulfilFrom($code)) {
                throw new RuntimeException("Warehouse [{$code}] is not an active warehouse that fulfils orders.");
            }

            $locked->load('items.variant');

            // Plan every line before writing any: a short line must leave no
            // partial picks behind. `$claimed` keeps two lines of the same
            // variant from counting the same units twice.
            /** @var list<array{0: OrderItem, 1: ProductVariant, 2: list<array{0: StockBatch, 1: int}>}> $plans */
            $plans = [];
            /** @var array<int, int> $claimed */
            $claimed = [];
            foreach ($locked->items as $item) {
                /** @var OrderItem $item */
                $variant = $item->variant;
                if ($variant === null || $variant->inventory_policy !== 'track') {
                    continue;
                }

                $batches = StockBatch::query()
                    ->where('product_variant_id', $variant->id)
                    ->where('warehouse_code', $code)
                    ->allocatable()
                    ->orderByRaw('expiry_date IS NULL')
                    ->orderBy('expiry_date')
                    ->orderBy('received_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $need = $item->qty;
                $takes = [];
                foreach ($batches as $batch) {
                    if ($need === 0) {
                        break;
                    }
                    $free = $batch->qty_on_hand - ($claimed[$batch->id] ?? 0);
                    if ($free <= 0) {
                        continue;
                    }
                    $take = min($need, $free);
                    $takes[] = [$batch, $take];
                    $claimed[$batch->id] = ($claimed[$batch->id] ?? 0) + $take;
                    $need -= $take;
                }

                if ($need > 0) {
                    throw InsufficientStockException::forVariant($variant->variant_sku, $item->qty, $item->qty - $need, $code);
                }

                $plans[] = [$item, $variant, $takes];
            }

            $allocations = [];
            foreach ($plans as [$item, $variant, $takes]) {
                foreach ($takes as [$batch, $take]) {
                    $this->ledger->post([
                        'type' => StockMovement::TYPE_SALE_OUT,
                        'variant_id' => $variant->id,
                        'warehouse_code' => $code,
                        'batch_id' => $batch->id,
                        'qty' => -$take,
                        'unit_cost_paise' => $batch->unit_cost_paise,
                        'reference_type' => self::REFERENCE_ORDER_ITEM,
                        'reference_id' => $item->id,
                        'actor_user_id' => $actorUserId,
                    ]);
                    $allocations[] = ['item_id' => $item->id, 'batch_no' => $batch->batch_no, 'qty' => $take];
                }

                $this->releaseReservation($variant, $item->qty);
            }

            Shipment::create([
                'order_id' => $locked->id,
                'warehouse_code' => $code,
                'carrier_code' => self::CARRIER_MANUAL,
                'status' => Shipment::STATUS_PICKED,
            ]);

            $locked->update([
                'status' => Order::STATUS_READY_TO_SHIP,
                'warehouse_code' => $code,
                'packed_at' => now(),
                'packed_by_user_id' => $actorUserId,
            ]);
            $order->setRawAttributes($locked->getAttributes(), true);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.packed',
                'subject_type' => 'order',
                'subject_id' => $locked->id,
                'before_hash' => AuditLog::digest(Order::STATUS_PAID),
                'after_hash' => AuditLog::digest(Order::STATUS_READY_TO_SHIP),
                'details' => [
                    'order_no' => $locked->order_no,
                    'warehouse_code' => $code,
                    'allocations' => $allocations,
                ],
            ]);
        });
    }

    /**
     * The legacy one-click ship (H3): pack an unpacked paid order first.
     *
     * While availability is not enforced (InventoryFeature OFF), stock is
     * recorded but never allowed to stop a shipment: if the default warehouse
     * cannot fulfil or a line is short, the order ships unpacked exactly as it
     * did before this module, and the gap is logged for ops to reconcile.
     */
    public function packForShipment(Order $order, ?int $actorUserId): void
    {
        if ($order->getAttribute('packed_at') !== null || $order->status !== Order::STATUS_PAID) {
            return;
        }

        if ($this->availabilityEnforced()) {
            $this->pack($order, null, $actorUserId);

            return;
        }

        $code = $this->settings->defaultWarehouseCode();
        if (! $this->canFulfilFrom($code)) {
            Log::warning('Inventory: order shipped without packing — default warehouse cannot fulfil', [
                'order_id' => $order->id, 'warehouse_code' => $code,
            ]);

            return;
        }

        try {
            $this->pack($order, $code, $actorUserId);
        } catch (InsufficientStockException $e) {
            Log::warning('Inventory: order shipped without packing — stock not recorded', [
                'order_id' => $order->id, 'warehouse_code' => $code, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Carrier and AWB onto the order's open shipment, if it has one. */
    public function markDispatched(Order $order, ?string $carrier, ?string $trackingNo): void
    {
        $shipment = Shipment::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [Shipment::STATUS_CREATED, Shipment::STATUS_PICKED])
            ->latest('id')
            ->first();

        if ($shipment === null) {
            return;
        }

        $carrier = trim((string) $carrier);
        $trackingNo = trim((string) $trackingNo);

        $shipment->update([
            'status' => Shipment::STATUS_DISPATCHED,
            // shipments.carrier_code is 32 and awb_no 64; the ship form allows
            // 120, and the order row keeps the full text.
            'carrier_code' => $carrier !== '' ? mb_substr($carrier, 0, 32) : self::CARRIER_MANUAL,
            'awb_no' => $trackingNo !== '' ? mb_substr($trackingNo, 0, 64) : null,
            'dispatched_at' => now(),
        ]);
    }

    /** Close the order's dispatched shipment, if it has one. */
    public function markDelivered(Order $order): void
    {
        Shipment::query()
            ->where('order_id', $order->id)
            ->where('status', Shipment::STATUS_DISPATCHED)
            ->update(['status' => Shipment::STATUS_DELIVERED, 'delivered_at' => now()]);
    }

    /**
     * Cancel after pack (H4): put every picked unit back into the batch it came
     * from. Nets sale_out against earlier sale_reversal per (item, batch), so a
     * retried cancel posts nothing twice.
     */
    public function unpackForCancel(Order $order, ?int $actorUserId): void
    {
        $this->db->transaction(function () use ($order, $actorUserId): void {
            $itemIds = OrderItem::query()->where('order_id', $order->id)->pluck('id')->all();

            $net = StockMovement::query()
                ->where('reference_type', self::REFERENCE_ORDER_ITEM)
                ->whereIn('reference_id', $itemIds)
                ->whereIn('type', [StockMovement::TYPE_SALE_OUT, StockMovement::TYPE_SALE_REVERSAL])
                ->selectRaw('reference_id, product_variant_id, warehouse_code, stock_batch_id, MIN(unit_cost_paise) as unit_cost_paise, SUM(qty) as net_qty')
                ->groupBy('reference_id', 'product_variant_id', 'warehouse_code', 'stock_batch_id')
                ->get();

            $restocked = [];
            foreach ($net as $row) {
                $qty = -(int) $row->getAttribute('net_qty');
                if ($qty <= 0) {
                    continue;
                }

                $this->ledger->post([
                    'type' => StockMovement::TYPE_SALE_REVERSAL,
                    'variant_id' => (int) $row->getAttribute('product_variant_id'),
                    'warehouse_code' => (string) $row->getAttribute('warehouse_code'),
                    'batch_id' => $row->getAttribute('stock_batch_id') !== null ? (int) $row->getAttribute('stock_batch_id') : null,
                    'qty' => $qty,
                    'unit_cost_paise' => (int) $row->getAttribute('unit_cost_paise'),
                    'reference_type' => self::REFERENCE_ORDER_ITEM,
                    'reference_id' => (int) $row->getAttribute('reference_id'),
                    'reason' => 'Order cancelled after pack',
                    'actor_user_id' => $actorUserId,
                ]);
                $restocked[] = ['item_id' => (int) $row->getAttribute('reference_id'), 'stock_batch_id' => $row->getAttribute('stock_batch_id'), 'qty' => $qty];
            }

            Shipment::query()
                ->where('order_id', $order->id)
                ->whereIn('status', [Shipment::STATUS_CREATED, Shipment::STATUS_PICKED])
                ->update(['status' => Shipment::STATUS_RETURNED]);

            if ($restocked !== []) {
                AuditLog::create([
                    'actor_id' => $actorUserId,
                    'action' => 'order.unpacked',
                    'subject_type' => 'order',
                    'subject_id' => $order->id,
                    'details' => ['order_no' => $order->order_no, 'restocked' => $restocked],
                ]);
            }
        });
    }

    /**
     * A return inspected as saleable goes back on the shelf (H6). Into the
     * batch the item left from when that is unambiguous; otherwise into a
     * `RET-{rma_no}` batch. Idempotent per return request.
     */
    public function restockReturn(ReturnRequest $returnRequest, ?int $actorUserId): void
    {
        $this->db->transaction(function () use ($returnRequest, $actorUserId): void {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($returnRequest->id);

            $already = StockMovement::query()
                ->where('reference_type', self::REFERENCE_RETURN_REQUEST)
                ->where('reference_id', $locked->id)
                ->where('type', StockMovement::TYPE_RETURN_IN)
                ->exists();
            if ($already) {
                return;
            }

            $order = Order::query()->with('items.variant')->findOrFail($locked->order_id);
            $code = $order->getAttribute('warehouse_code') ?? Warehouse::DEFAULT_CODE;

            // An item-level return names its line and quantity; an order-level
            // one (order_item_id null) brings back every line in full.
            /** @var list<array{0: OrderItem, 1: ProductVariant, 2: int}> $lines */
            $lines = [];
            foreach ($order->items as $item) {
                /** @var OrderItem $item */
                if ($locked->order_item_id !== null && $item->id !== $locked->order_item_id) {
                    continue;
                }
                $qty = $locked->order_item_id !== null ? ($locked->qty ?? $item->qty) : $item->qty;
                if ($qty > 0 && $item->variant !== null && $item->variant->inventory_policy === 'track') {
                    $lines[] = [$item, $item->variant, $qty];
                }
            }

            foreach ($lines as [$item, $variant, $qty]) {
                $batch = $this->returnBatch($item, $variant, $code, $locked);

                $this->ledger->post([
                    'type' => StockMovement::TYPE_RETURN_IN,
                    'variant_id' => $variant->id,
                    'warehouse_code' => $batch->warehouse_code,
                    'batch_id' => $batch->id,
                    'qty' => $qty,
                    'unit_cost_paise' => $batch->unit_cost_paise,
                    'reference_type' => self::REFERENCE_RETURN_REQUEST,
                    'reference_id' => $locked->id,
                    'reason' => 'Saleable return '.$locked->rma_no,
                    'actor_user_id' => $actorUserId,
                ]);
            }
        });
    }

    /**
     * For the packing slip: one row per (item, batch) picked, or one row per
     * tracked-or-not item with no batch while the order is unpacked.
     *
     * @return list<array{sku: string, name: string, qty: int, batch_no: string|null, expiry: string|null}>
     */
    public function pickList(Order $order): array
    {
        $order->loadMissing('items');
        $items = $order->items;

        $movements = StockMovement::query()
            ->with('batch')
            ->where('reference_type', self::REFERENCE_ORDER_ITEM)
            ->whereIn('reference_id', $items->pluck('id')->all())
            ->whereIn('type', [StockMovement::TYPE_SALE_OUT, StockMovement::TYPE_SALE_REVERSAL])
            ->orderBy('id')
            ->get()
            ->groupBy('reference_id');

        $rows = [];
        foreach ($items as $item) {
            /** @var OrderItem $item */
            $sku = (string) $item->getAttribute('variant_sku_snapshot');
            $name = (string) $item->getAttribute('product_name_snapshot');
            $picked = [];
            foreach ($movements->get($item->id, collect()) as $movement) {
                /** @var StockMovement $movement */
                $key = (string) $movement->stock_batch_id;
                $picked[$key] ??= ['qty' => 0, 'batch' => $movement->batch];
                $picked[$key]['qty'] -= $movement->qty;
            }

            $picked = array_filter($picked, static fn (array $p): bool => $p['qty'] > 0);
            if ($picked === []) {
                $rows[] = ['sku' => $sku, 'name' => $name, 'qty' => $item->qty, 'batch_no' => null, 'expiry' => null];

                continue;
            }

            foreach ($picked as $p) {
                $rows[] = [
                    'sku' => $sku,
                    'name' => $name,
                    'qty' => $p['qty'],
                    'batch_no' => $p['batch']?->batch_no,
                    'expiry' => $p['batch']?->expiry_date?->toDateString(),
                ];
            }
        }

        return $rows;
    }

    private function canFulfilFrom(string $code): bool
    {
        return Warehouse::query()->fulfilling()->where('code', $code)->exists();
    }

    /**
     * Release what checkout reserved. Goes through the same `inventory`
     * relation checkout and cancel use, so reserve and release always hit the
     * same row; floored like cancel's release.
     */
    private function releaseReservation(ProductVariant $variant, int $qty): void
    {
        $level = $variant->inventory()->lockForUpdate()->first();
        if ($level === null) {
            return;
        }

        $release = min($qty, (int) $level->getAttribute('reserved'));
        if ($release > 0) {
            $level->decrement('reserved', $release);
        }
    }

    private function returnBatch(OrderItem $item, ProductVariant $variant, string $code, ReturnRequest $returnRequest): StockBatch
    {
        $batchIds = StockMovement::query()
            ->where('reference_type', self::REFERENCE_ORDER_ITEM)
            ->where('reference_id', $item->id)
            ->where('type', StockMovement::TYPE_SALE_OUT)
            ->whereNotNull('stock_batch_id')
            ->distinct()
            ->pluck('stock_batch_id')
            ->all();

        $originals = StockBatch::query()->whereIn('id', $batchIds)->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->get();

        if ($originals->count() === 1) {
            /** @var StockBatch $original */
            $original = $originals->first();

            return $original;
        }

        // Several (or no) source batches: a batch of its own. With several,
        // carry the earliest expiry — returned food supplements must never
        // outlive the stock they came from.
        /** @var StockBatch|null $earliest */
        $earliest = $originals->first();

        return StockBatch::query()->firstOrCreate(
            ['product_variant_id' => $variant->id, 'warehouse_code' => $code, 'batch_no' => 'RET-'.$returnRequest->rma_no],
            [
                'mfg_date' => $earliest?->mfg_date,
                'expiry_date' => $earliest?->expiry_date,
                'unit_cost_paise' => $earliest->unit_cost_paise ?? (int) $variant->getAttribute('cost_paise'),
                'qty_on_hand' => 0,
                'received_at' => now(),
                'source_type' => self::REFERENCE_RETURN_REQUEST,
                'source_id' => $returnRequest->id,
            ],
        );
    }
}
