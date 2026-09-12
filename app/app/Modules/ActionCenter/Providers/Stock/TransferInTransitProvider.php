<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Stock;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * A stock transfer dispatched but not received within the transit window
 * (plan §4, Stock). Hidden while `InventoryFeature` is off.
 */
final class TransferInTransitProvider extends AbstractProvider
{
    public function __construct(private readonly ActionCenterSettings $settings) {}

    public function key(): string
    {
        return 'stock.transfer_in_transit';
    }

    public function group(): string
    {
        return ActionGroup::STOCK;
    }

    public function label(): string
    {
        return 'Transfers stuck in transit';
    }

    public function description(): string
    {
        return 'Stock transfers dispatched but not marked received within the transit window. Chase the receiving warehouse.';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function slaHours(): int
    {
        return $this->settings->transferTransitDays() * 24;
    }

    public function enabled(): bool
    {
        return Feature::for(null)->active(InventoryFeature::class);
    }

    public function subjectType(): string
    {
        return 'stock_transfer';
    }

    public function targetRoute(): string
    {
        return 'admin.inventory.transfers.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('dispatched_at')
            ->limit($limit)
            ->get(['id', 'transfer_no', 'dispatched_at', 'from_warehouse_code', 'to_warehouse_code'])
            ->map(function (StockTransfer $transfer): ActionItem {
                $dueAt = $this->dueAt($transfer->dispatched_at);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $transfer->id,
                    title: $transfer->transfer_no,
                    subtitle: 'Dispatched '.$this->ageLabel($transfer->dispatched_at)." ago, {$transfer->from_warehouse_code} → {$transfer->to_warehouse_code}",
                    occurredAt: $transfer->dispatched_at ?? now(),
                    dueAt: $dueAt,
                    severity: $this->itemSeverity($dueAt),
                    url: route($this->targetRoute(), ['stockTransfer' => $transfer->id]),
                    meta: [
                        'transfer_no' => $transfer->transfer_no,
                        'from_warehouse_code' => $transfer->from_warehouse_code,
                        'to_warehouse_code' => $transfer->to_warehouse_code,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<StockTransfer> */
    private function baseQuery(): Builder
    {
        $query = StockTransfer::query()
            ->where('status', StockTransfer::STATUS_DISPATCHED)
            ->whereNotNull('dispatched_at')
            ->whereNull('received_at')
            ->where('dispatched_at', '<=', $this->slaCutoff());

        $this->excludeSnoozed($query->getQuery(), 'stock_transfers.id');

        return $query;
    }
}
