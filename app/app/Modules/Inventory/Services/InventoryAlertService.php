<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Database\Eloquent\Collection;

/**
 * Plan §4.9 — the single place that decides what counts as low stock or
 * near/at expiry. Used by the dashboard card, the sidebar badge, the reports
 * and the daily `inventory:alerts` command, so all four always agree.
 */
final class InventoryAlertService
{
    /**
     * Levels at or under their reorder point. `reorder_level = 0` means
     * "not tracked for reorder" (the product form default), not "always low".
     *
     * @return Collection<int, InventoryLevel>
     */
    public function lowStock(): Collection
    {
        return InventoryLevel::query()
            ->where('reorder_level', '>', 0)
            ->whereRaw('(on_hand - reserved) <= reorder_level')
            ->with(['variant.product'])
            ->orderBy('warehouse_code')
            ->get();
    }

    /**
     * Batches with stock remaining that expire within the next $days days
     * (inclusive), not already expired.
     *
     * @return Collection<int, StockBatch>
     */
    public function expiring(int $days): Collection
    {
        return StockBatch::query()
            ->where('qty_on_hand', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString())
            ->with(['variant.product'])
            ->orderBy('expiry_date')
            ->get();
    }

    /**
     * Batches with stock still on hand whose expiry date has passed — should
     * have been written off but has not been yet.
     *
     * @return Collection<int, StockBatch>
     */
    public function expired(): Collection
    {
        return StockBatch::query()
            ->where('qty_on_hand', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->with(['variant.product'])
            ->orderBy('expiry_date')
            ->get();
    }
}
