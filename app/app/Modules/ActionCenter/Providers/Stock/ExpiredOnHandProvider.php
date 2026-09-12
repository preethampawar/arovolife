<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Stock;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Batches with stock still on hand past their expiry date — should have been
 * written off but has not been (plan §4, Stock; `InventoryAlertService::expired()`'s
 * condition). Critical: expired goods on the shelf are a saleability and
 * compliance risk, not a scheduling one. Hidden while `InventoryFeature` is
 * off.
 */
final class ExpiredOnHandProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'stock.expired_on_hand';
    }

    public function group(): string
    {
        return ActionGroup::STOCK;
    }

    public function label(): string
    {
        return 'Expired stock on hand';
    }

    public function description(): string
    {
        return 'Batches past their expiry date still showing stock on hand. Write them off from an adjustment.';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function enabled(): bool
    {
        return Feature::for(null)->active(InventoryFeature::class);
    }

    public function subjectType(): string
    {
        return 'stock_batch';
    }

    public function targetRoute(): string
    {
        return 'admin.inventory.adjustments.index';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('expiry_date')
            ->limit($limit)
            ->with(['variant.product'])
            ->get()
            ->map(function (StockBatch $batch): ActionItem {
                $name = $batch->variant->product->name ?? $batch->variant->variant_sku ?? "Variant #{$batch->product_variant_id}";
                $occurredAt = $batch->expiry_date ?? now();

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $batch->id,
                    title: "{$name} — {$batch->batch_no}",
                    subtitle: "{$batch->qty_on_hand} on hand, expired ".$this->ageLabel($occurredAt).' ago',
                    occurredAt: $occurredAt,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute()),
                    meta: [
                        'batch_no' => $batch->batch_no,
                        'warehouse_code' => $batch->warehouse_code,
                        'qty_on_hand' => $batch->qty_on_hand,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<StockBatch> */
    private function baseQuery(): Builder
    {
        $query = StockBatch::query()
            ->where('qty_on_hand', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString());

        $this->excludeSnoozed($query->getQuery(), 'stock_batches.id');

        return $query;
    }
}
