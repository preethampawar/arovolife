<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Stock;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Levels at or under their reorder point (plan §4, Stock) — the same
 * condition `InventoryAlertService::lowStock()` uses for the reports and the
 * daily alert mail, so this and those always agree; the query is repeated
 * here rather than called through the service so a snoozed level can still
 * be excluded (plan §3.1.2), which the service's own collection cannot do.
 * Hidden while `InventoryFeature` is off (plan §3.1.4).
 */
final class LowStockProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'stock.low';
    }

    public function group(): string
    {
        return ActionGroup::STOCK;
    }

    public function label(): string
    {
        return 'Low stock';
    }

    public function description(): string
    {
        return 'Levels at or under their reorder point. Raise a purchase order or transfer.';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function enabled(): bool
    {
        return Feature::for(null)->active(InventoryFeature::class);
    }

    public function subjectType(): string
    {
        return 'inventory_level';
    }

    public function targetRoute(): string
    {
        return 'admin.inventory.reports.low-stock';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('warehouse_code')
            ->limit($limit)
            ->with(['variant.product'])
            ->get()
            ->map(function (InventoryLevel $level): ActionItem {
                $name = $level->variant->product->name ?? $level->variant->variant_sku ?? "Variant #{$level->product_variant_id}";

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $level->id,
                    title: $name,
                    subtitle: "On hand {$level->available()}, reorder point {$level->reorder_level} ({$level->warehouse_code})",
                    occurredAt: now(),
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute()),
                    meta: [
                        'warehouse_code' => $level->warehouse_code,
                        'on_hand' => $level->available(),
                        'reorder_level' => $level->reorder_level,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<InventoryLevel> */
    private function baseQuery(): Builder
    {
        $query = InventoryLevel::query()
            ->where('reorder_level', '>', 0)
            ->whereRaw('(on_hand - reserved) <= reorder_level');

        $this->excludeSnoozed($query->getQuery(), 'inventory_levels.id');

        return $query;
    }
}
