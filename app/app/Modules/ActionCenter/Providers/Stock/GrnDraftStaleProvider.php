<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Stock;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * A GRN drafted but left unposted (plan §4, Stock) — the goods are logged
 * but the receipt has not hit the ledger, so the stock they represent is not
 * yet on the shelf anywhere the system can see. Hidden while
 * `InventoryFeature` is off.
 */
final class GrnDraftStaleProvider extends AbstractProvider
{
    public function __construct(private readonly ActionCenterSettings $settings) {}

    public function key(): string
    {
        return 'stock.grn_draft_stale';
    }

    public function group(): string
    {
        return ActionGroup::STOCK;
    }

    public function label(): string
    {
        return 'GRNs stuck in draft';
    }

    public function description(): string
    {
        return 'Goods receipts drafted but not posted. Post them so the stock lands in the ledger.';
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
        return $this->settings->grnDraftDays() * 24;
    }

    public function enabled(): bool
    {
        return Feature::for(null)->active(InventoryFeature::class);
    }

    public function subjectType(): string
    {
        return 'purchase_invoice';
    }

    public function targetRoute(): string
    {
        return 'admin.inventory.grns.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'grn_no', 'created_at', 'warehouse_code', 'total_paise'])
            ->map(function (PurchaseInvoice $invoice): ActionItem {
                $dueAt = $this->dueAt($invoice->created_at);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $invoice->id,
                    title: $invoice->grn_no,
                    subtitle: 'Drafted '.$this->ageLabel($invoice->created_at).' ago, not posted',
                    occurredAt: $invoice->created_at ?? now(),
                    dueAt: $dueAt,
                    severity: $this->itemSeverity($dueAt),
                    url: route($this->targetRoute(), ['purchaseInvoice' => $invoice->id]),
                    meta: [
                        'grn_no' => $invoice->grn_no,
                        'warehouse_code' => $invoice->warehouse_code,
                        'total_paise' => (int) $invoice->total_paise,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<PurchaseInvoice> */
    private function baseQuery(): Builder
    {
        $query = PurchaseInvoice::query()
            ->where('status', PurchaseInvoice::STATUS_DRAFT)
            ->where('created_at', '<=', $this->slaCutoff());

        $this->excludeSnoozed($query->getQuery(), 'purchase_invoices.id');

        return $query;
    }
}
