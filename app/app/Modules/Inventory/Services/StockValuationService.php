<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single owner of "what is this stock worth" (plan D5).
 *
 * Before this class the same `SUM(qty x unit_cost)` was written inline in four
 * places — two report methods, a private helper and a Blade template — which
 * is three too many for a number that feeds a profit figure.
 *
 * ## Two questions, two methods, and they are not the same question
 *
 * `currentValuePaise()` reads `stock_batches`: what is on the shelf right now.
 * Cheap, and it is what the stock screens want.
 *
 * `valueAtPaise($at)` replays `stock_movements`: what the stock was worth at a
 * moment in the past. `stock_batches.qty_on_hand` is a live projection with no
 * history, so a past balance can only be re-derived from the append-only
 * ledger. The Trading Account's opening and closing stock need exactly this —
 * asking the batch table for last month's closing value would silently return
 * today's.
 *
 * The two agree at "now" only when every movement carries a cost. Movements
 * that do not are reported by {@see unvaluedQtyAt()} rather than being
 * quietly absorbed, because an unvalued write-off is stock leaving without its
 * value leaving, and that is the exact shape of a margin that looks too good.
 */
final class StockValuationService
{
    /**
     * Value of stock held at a point in time, from the movement ledger.
     *
     * Every movement carries the qty and the unit cost at which it moved, so
     * the running sum of `qty x unit_cost` IS the inventory account balance.
     * Signs take care of themselves: receipts are positive, issues negative.
     */
    public function valueAtPaise(?DateTimeInterface $at = null, ?string $warehouseCode = null): int
    {
        return (int) StockMovement::query()
            ->when($at !== null, fn ($q) => $q->where('occurred_at', '<=', $at))
            ->when($warehouseCode !== null && $warehouseCode !== '', fn ($q) => $q->where('warehouse_code', $warehouseCode))
            ->selectRaw('COALESCE(SUM(qty * unit_cost_paise), 0) as value_paise')
            ->value('value_paise');
    }

    /**
     * Quantity that moved without a cost attached, up to a point in time.
     *
     * Historical adjustments predate cost-bearing adjustments and are 0 by
     * construction; this is what the Trading Account shows as its reconciling
     * line so the identity is seen to hold rather than assumed to.
     */
    public function unvaluedQtyAt(?DateTimeInterface $at = null, ?string $warehouseCode = null): int
    {
        return (int) StockMovement::query()
            ->where('unit_cost_paise', 0)
            ->when($at !== null, fn ($q) => $q->where('occurred_at', '<=', $at))
            ->when($warehouseCode !== null && $warehouseCode !== '', fn ($q) => $q->where('warehouse_code', $warehouseCode))
            ->sum('qty');
    }

    /** Value on the shelf right now, from the batch table. */
    public function currentValuePaise(?string $warehouseCode = null): int
    {
        return (int) $this->batchQuery($warehouseCode)
            ->get(['qty_on_hand', 'unit_cost_paise'])
            ->sum(fn (StockBatch $b): int => $b->qty_on_hand * $b->unit_cost_paise);
    }

    /**
     * Current value keyed `"{variantId}|{warehouseCode}"`, for list screens
     * that need a value per row without a query per row.
     *
     * @param  Collection<array-key, int>|array<array-key, int>  $variantIds
     * @return array<string, int>
     */
    public function currentValueByVariantWarehouse(Collection|array $variantIds, ?string $warehouseCode = null): array
    {
        $ids = array_values($variantIds instanceof Collection ? $variantIds->all() : $variantIds);

        $values = [];

        foreach ($this->batchQuery($warehouseCode)->whereIn('product_variant_id', $ids)
            ->get(['product_variant_id', 'warehouse_code', 'qty_on_hand', 'unit_cost_paise']) as $batch) {
            $key = $batch->product_variant_id.'|'.$batch->warehouse_code;
            $values[$key] = ($values[$key] ?? 0) + ($batch->qty_on_hand * $batch->unit_cost_paise);
        }

        return $values;
    }

    /** Value of one batch's remaining stock — the "at risk" figure on expiry reports. */
    public function batchValuePaise(StockBatch $batch): int
    {
        return $batch->qty_on_hand * $batch->unit_cost_paise;
    }

    /** @return Builder<StockBatch> */
    private function batchQuery(?string $warehouseCode): Builder
    {
        return StockBatch::query()
            ->when($warehouseCode !== null && $warehouseCode !== '', fn ($q) => $q->where('warehouse_code', $warehouseCode));
    }
}
