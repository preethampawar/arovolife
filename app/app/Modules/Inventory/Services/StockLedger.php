<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\Exceptions\InsufficientStockException;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only class that writes `stock_movements`.
 *
 * Stock is not a mutable integer. It is the sum of an append-only ledger, and
 * `inventory_levels.on_hand` / `stock_batches.qty_on_hand` are projections of
 * that sum — the same arrangement as the wallet (ADR-0004), for the same
 * reason: a number nobody can explain is a number nobody can correct.
 *
 * Every post runs in one transaction holding a row lock on the level and, when
 * a batch is named, on the batch. Two pickers reaching for the last unit
 * therefore serialise, and the second one is refused rather than taking the
 * stock negative. `inventory:verify` re-derives both projections and fails on
 * any drift.
 */
final class StockLedger
{
    /**
     * Movement types that must carry a positive quantity, and those that must
     * carry a negative one. The sign lives in the caller, but which sign is
     * legal for a type is a property of the type — a `sale_out` posted as +5
     * would read as a receipt in every report.
     *
     * @var array<string, int>
     */
    private const DIRECTION = [
        StockMovement::TYPE_PURCHASE_IN => 1,
        StockMovement::TYPE_PURCHASE_REVERSAL => -1,
        StockMovement::TYPE_SALE_OUT => -1,
        StockMovement::TYPE_SALE_REVERSAL => 1,
        StockMovement::TYPE_TRANSFER_OUT => -1,
        StockMovement::TYPE_TRANSFER_IN => 1,
        StockMovement::TYPE_RETURN_IN => 1,
        StockMovement::TYPE_ADJUSTMENT_IN => 1,
        StockMovement::TYPE_ADJUSTMENT_OUT => -1,
        StockMovement::TYPE_WRITE_OFF => -1,
        StockMovement::TYPE_OPENING => 1,
    ];

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Record one movement and roll both projections forward.
     *
     * @param  array{type: string, variant_id: int, warehouse_code: string, batch_id?: int|null, qty: int,
     *               unit_cost_paise?: int, reference_type?: string|null, reference_id?: int|null,
     *               reason?: string|null, actor_user_id?: int|null, occurred_at?: CarbonInterface|null}  $movement
     *
     * @throws InsufficientStockException when the movement would take the level or the batch below zero
     * @throws InvalidArgumentException on an unknown type, a zero quantity, a wrong sign, an unknown
     *                                  warehouse, or a batch that belongs somewhere else
     */
    public function post(array $movement): StockMovement
    {
        $type = $movement['type'];
        $qty = $movement['qty'];
        $variantId = $movement['variant_id'];
        $warehouseCode = $movement['warehouse_code'];
        $batchId = $movement['batch_id'] ?? null;

        if (! isset(self::DIRECTION[$type])) {
            throw new InvalidArgumentException("Unknown stock movement type [{$type}].");
        }

        if ($qty === 0) {
            throw new InvalidArgumentException('A stock movement cannot have a quantity of zero.');
        }

        if (($qty > 0 ? 1 : -1) !== self::DIRECTION[$type]) {
            throw new InvalidArgumentException(
                "A [{$type}] movement must have a ".(self::DIRECTION[$type] === 1 ? 'positive' : 'negative').' quantity.'
            );
        }

        if (! Warehouse::query()->where('code', $warehouseCode)->exists()) {
            throw new InvalidArgumentException("Unknown warehouse [{$warehouseCode}].");
        }

        return $this->db->transaction(function () use ($movement, $type, $qty, $variantId, $warehouseCode, $batchId): StockMovement {
            $level = $this->lockLevel($variantId, $warehouseCode);
            $onHandBefore = $level->on_hand;
            $onHandAfter = $onHandBefore + $qty;

            if ($onHandAfter < 0) {
                throw InsufficientStockException::forVariant(
                    $this->variantLabel($variantId),
                    abs($qty),
                    $onHandBefore,
                    $warehouseCode,
                );
            }

            $batch = null;

            if ($batchId !== null) {
                $batch = StockBatch::query()->whereKey($batchId)->lockForUpdate()->first();

                if ($batch === null) {
                    throw new InvalidArgumentException("Unknown stock batch [{$batchId}].");
                }

                if ($batch->product_variant_id !== $variantId || $batch->warehouse_code !== $warehouseCode) {
                    throw new InvalidArgumentException(
                        "Batch [{$batch->batch_no}] does not belong to this variant and warehouse."
                    );
                }

                if ($batch->qty_on_hand + $qty < 0) {
                    throw InsufficientStockException::forVariant(
                        $this->variantLabel($variantId).' batch '.$batch->batch_no,
                        abs($qty),
                        $batch->qty_on_hand,
                        $warehouseCode,
                    );
                }
            }

            $occurredAt = $movement['occurred_at'] ?? now();

            $row = StockMovement::create([
                'product_variant_id' => $variantId,
                'warehouse_code' => $warehouseCode,
                'stock_batch_id' => $batchId,
                'type' => $type,
                'qty' => $qty,
                'unit_cost_paise' => $movement['unit_cost_paise'] ?? 0,
                'reference_type' => $movement['reference_type'] ?? null,
                'reference_id' => $movement['reference_id'] ?? null,
                'reason' => $movement['reason'] ?? null,
                'actor_user_id' => $movement['actor_user_id'] ?? null,
                'occurred_at' => $occurredAt,
            ]);

            $level->on_hand = $onHandAfter;
            $level->save();

            if ($batch !== null) {
                $batch->qty_on_hand += $qty;
                $batch->save();
            }

            AuditLog::create([
                'actor_id' => $movement['actor_user_id'] ?? null,
                'action' => 'inventory.stock.moved',
                'subject_type' => StockMovement::class,
                'subject_id' => $row->id,
                'before_hash' => AuditLog::digest((string) $onHandBefore),
                'after_hash' => AuditLog::digest((string) $onHandAfter),
                'details' => [
                    'type' => $type,
                    'product_variant_id' => $variantId,
                    'warehouse_code' => $warehouseCode,
                    'stock_batch_id' => $batchId,
                    'qty' => $qty,
                    'on_hand_before' => $onHandBefore,
                    'on_hand_after' => $onHandAfter,
                    'reference_type' => $movement['reference_type'] ?? null,
                    'reference_id' => $movement['reference_id'] ?? null,
                    'reason' => $movement['reason'] ?? null,
                ],
            ]);

            return $row;
        });
    }

    /** The projected quantity held at one warehouse. */
    public function onHand(int $variantId, string $warehouseCode): int
    {
        return (int) InventoryLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('warehouse_code', $warehouseCode)
            ->value('on_hand');
    }

    /**
     * What may still be sold: on hand less what orders have already reserved.
     *
     * With no warehouse named this sums the warehouses an order can actually be
     * picked from — active and `fulfils_orders` — so storage-only and archived
     * locations never make a product look sellable. Each location is floored at
     * zero so one over-reserved level cannot mask stock sitting elsewhere.
     */
    public function available(int $variantId, ?string $warehouseCode = null): int
    {
        $query = InventoryLevel::query()
            ->where('inventory_levels.product_variant_id', $variantId);

        if ($warehouseCode !== null) {
            $query->where('inventory_levels.warehouse_code', $warehouseCode);
        } else {
            $query->join('warehouses', 'warehouses.code', '=', 'inventory_levels.warehouse_code')
                ->where('warehouses.status', Warehouse::STATUS_ACTIVE)
                ->where('warehouses.fulfils_orders', true);
        }

        $rows = $query->get(['inventory_levels.on_hand', 'inventory_levels.reserved']);

        return (int) $rows->sum(static fn (InventoryLevel $level): int => max(0, $level->on_hand - $level->reserved));
    }

    /**
     * The level row, locked, created on first use.
     *
     * `firstOrCreate` before the lock rather than after: a concurrent creator
     * would otherwise win the unique index and this call would lock nothing.
     */
    private function lockLevel(int $variantId, string $warehouseCode): InventoryLevel
    {
        InventoryLevel::query()->firstOrCreate(
            ['product_variant_id' => $variantId, 'warehouse_code' => $warehouseCode],
            ['on_hand' => 0, 'reserved' => 0],
        );

        $level = InventoryLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('warehouse_code', $warehouseCode)
            ->lockForUpdate()
            ->first();

        if ($level === null) {
            throw new InvalidArgumentException(
                "Could not lock the inventory level for variant [{$variantId}] at [{$warehouseCode}]."
            );
        }

        return $level;
    }

    /** The SKU, for an error message an operator has to act on. */
    private function variantLabel(int $variantId): string
    {
        $sku = DB::table('product_variants')->where('id', $variantId)->value('variant_sku');

        return is_string($sku) && $sku !== '' ? $sku : "variant #{$variantId}";
    }
}
