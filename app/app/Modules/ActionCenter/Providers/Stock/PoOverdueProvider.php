<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Stock;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Shared\Features\InventoryFeature;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * A purchase order sent to the supplier with nothing received against it,
 * past its expected date or the PO-overdue window if none was set (plan §4,
 * Stock). Hidden while `InventoryFeature` is off.
 */
final class PoOverdueProvider extends AbstractProvider
{
    public function __construct(private readonly ActionCenterSettings $settings) {}

    public function key(): string
    {
        return 'stock.po_overdue';
    }

    public function group(): string
    {
        return ActionGroup::STOCK;
    }

    public function label(): string
    {
        return 'Purchase orders overdue';
    }

    public function description(): string
    {
        return 'Purchase orders sent to the supplier with nothing received yet, past the expected date. Chase the supplier.';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    public function severity(): string
    {
        return Severity::INFO;
    }

    public function slaHours(): int
    {
        return $this->settings->poOverdueDays() * 24;
    }

    public function enabled(): bool
    {
        return Feature::for(null)->active(InventoryFeature::class);
    }

    public function subjectType(): string
    {
        return 'purchase_order';
    }

    public function targetRoute(): string
    {
        return 'admin.inventory.purchase-orders.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('sent_at')
            ->limit($limit)
            ->get(['id', 'po_no', 'sent_at', 'expected_at', 'warehouse_code'])
            ->map(function (PurchaseOrder $po): ActionItem {
                $occurredAt = $po->sent_at ?? now();
                $dueAt = $po->expected_at !== null
                    ? CarbonImmutable::instance($po->expected_at)
                    : $this->dueAt($po->sent_at);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $po->id,
                    title: $po->po_no,
                    subtitle: 'Sent '.$this->ageLabel($occurredAt).' ago, nothing received',
                    occurredAt: $occurredAt,
                    dueAt: $dueAt,
                    severity: $this->itemSeverity($dueAt),
                    url: route($this->targetRoute(), ['purchaseOrder' => $po->id]),
                    meta: [
                        'po_no' => $po->po_no,
                        'warehouse_code' => $po->warehouse_code,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<PurchaseOrder> */
    private function baseQuery(): Builder
    {
        $cutoff = now()->subDays($this->settings->poOverdueDays());

        $query = PurchaseOrder::query()
            ->where('status', PurchaseOrder::STATUS_SENT)
            ->where(function (Builder $q) use ($cutoff): void {
                $q->where(function (Builder $q2): void {
                    $q2->whereNotNull('expected_at')->whereDate('expected_at', '<', now()->toDateString());
                })->orWhere(function (Builder $q2) use ($cutoff): void {
                    $q2->whereNull('expected_at')->whereNotNull('sent_at')->where('sent_at', '<=', $cutoff);
                });
            });

        $this->excludeSnoozed($query->getQuery(), 'purchase_orders.id');

        return $query;
    }
}
