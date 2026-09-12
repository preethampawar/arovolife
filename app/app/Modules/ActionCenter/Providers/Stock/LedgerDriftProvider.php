<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Stock;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * A stock projection that disagrees with the movement ledger (plan §4,
 * Stock) — the same comparison `inventory:verify` runs: `inventory_levels.on_hand`
 * must equal the sum of that (variant, warehouse)'s movements. Critical: a
 * drifted projection is unreachable stock or a phantom sale, not a
 * scheduling problem. Covers the level projection, which is the invariant
 * every other screen reads; batch-level drift is `inventory:verify`'s to
 * report until this type needs a second subject shape. Hidden while
 * `InventoryFeature` is off.
 */
final class LedgerDriftProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'stock.ledger_drift';
    }

    public function group(): string
    {
        return ActionGroup::STOCK;
    }

    public function label(): string
    {
        return 'Stock ledger drift';
    }

    public function description(): string
    {
        return 'Inventory levels that disagree with the movement ledger. Run `inventory:verify` and adjust.';
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
        return 'inventory_level';
    }

    public function targetRoute(): string
    {
        return 'admin.inventory.stock.index';
    }

    public function count(): int
    {
        return $this->driftedLevelIds()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        $ids = $this->driftedLevelIds()->take($limit);

        if ($ids->isEmpty()) {
            return collect();
        }

        return InventoryLevel::query()
            ->whereIn('id', $ids)
            ->with(['variant.product'])
            ->get()
            ->map(function (InventoryLevel $level): ActionItem {
                $name = $level->variant->product->name ?? $level->variant->variant_sku ?? "Variant #{$level->product_variant_id}";
                $expected = (int) DB::table('stock_movements')
                    ->where('product_variant_id', $level->product_variant_id)
                    ->where('warehouse_code', $level->warehouse_code)
                    ->sum('qty');

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $level->id,
                    title: $name,
                    subtitle: "Projection {$level->on_hand}, ledger says {$expected} ({$level->warehouse_code})",
                    occurredAt: now(),
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute()),
                    meta: [
                        'warehouse_code' => $level->warehouse_code,
                        'projected' => $level->on_hand,
                        'ledger_total' => $expected,
                    ],
                );
            })
            ->values();
    }

    /**
     * Level ids whose `on_hand` differs from the sum of their movements,
     * excluding levels currently snoozed. Not a single indexed query — the
     * same aggregate-then-compare shape `inventory:verify` uses, since the
     * comparison is inherently a full recomputation rather than a filter.
     *
     * @return Collection<int, int>
     */
    private function driftedLevelIds(): Collection
    {
        $sums = DB::table('stock_movements')
            ->select('product_variant_id', 'warehouse_code', DB::raw('SUM(qty) as total'))
            ->groupBy('product_variant_id', 'warehouse_code')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                $row->product_variant_id.'|'.$row->warehouse_code => (int) $row->total,
            ]);

        $snoozed = DB::table('action_center_snoozes')
            ->where('action_key', $this->key())
            ->where('subject_type', $this->subjectType())
            ->where('snoozed_until', '>', now())
            ->pluck('subject_id')
            ->all();

        $drifted = [];

        foreach (InventoryLevel::query()->orderBy('id')->cursor() as $level) {
            if (in_array($level->id, $snoozed, true)) {
                continue;
            }

            $key = $level->product_variant_id.'|'.$level->warehouse_code;
            $expected = $sums[$key] ?? 0;

            if ($level->on_hand !== $expected) {
                $drifted[] = $level->id;
            }
        }

        return collect($drifted);
    }
}
