<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Stock;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Services\InventorySettings;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Batches with stock remaining that expire within the settings window (plan
 * §4, Stock) — the same condition as `InventoryAlertService::expiring()`,
 * repeated here (rather than called through it) so a snoozed batch can be
 * excluded. Hidden while `InventoryFeature` is off.
 */
final class ExpiringProvider extends AbstractProvider
{
    public function __construct(private readonly InventorySettings $settings) {}

    public function key(): string
    {
        return 'stock.expiring';
    }

    public function group(): string
    {
        return ActionGroup::STOCK;
    }

    public function label(): string
    {
        return 'Batches expiring soon';
    }

    public function description(): string
    {
        return 'Batches with stock on hand expiring soon. Prioritise them for sale or transfer.';
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
        return 'stock_batch';
    }

    public function targetRoute(): string
    {
        return 'admin.inventory.reports.batch-expiry';
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

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $batch->id,
                    title: "{$name} — {$batch->batch_no}",
                    subtitle: "{$batch->qty_on_hand} on hand, expires {$batch->expiry_date?->toDateString()} ({$batch->warehouse_code})",
                    occurredAt: now(),
                    dueAt: $batch->expiry_date,
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
        $days = $this->settings->expiryAlertDays();

        $query = StockBatch::query()
            ->where('qty_on_hand', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString());

        $this->excludeSnoozed($query->getQuery(), 'stock_batches.id');

        return $query;
    }
}
