<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\LandingPriceHistory;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\PurchaseInvoiceItem;
use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Database\DatabaseManager;

/**
 * Owns `product_variants.landing_price_paise` (plan D5).
 *
 * The field used to be typed by hand and read by nothing. It is now derived
 * from what the business actually paid — the weighted-average landed cost of
 * the stock it is holding — and every change is written to
 * `landing_price_history`.
 *
 * ## Why weighted average of on-hand batches
 *
 * "The last GRN's price" answers a different question: what the next unit
 * costs to buy. Landing price is used to value and cost the stock that exists,
 * so it has to reflect the mix actually sitting in the warehouses. When a
 * variant holds 400 units at Rs 199 and 100 at Rs 210, its landing price is
 * Rs 201.20, not Rs 210.
 *
 * When nothing is on hand there is no mix to weight, so the most recent posted
 * GRN's landed unit cost is the best available answer — that is what the next
 * receipt will most likely cost.
 */
final class LandingPriceService
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Recompute one variant's landing price from stock reality.
     *
     * Returns the history row when the price moved, and null when it did not —
     * a GRN posted at the price the variant already carried is not a change,
     * and writing a no-op row would turn the history into noise.
     */
    public function recompute(
        ProductVariant $variant,
        string $source = LandingPriceHistory::SOURCE_GRN,
        ?PurchaseInvoice $invoice = null,
        ?int $actorUserId = null,
        ?string $note = null,
    ): ?LandingPriceHistory {
        $derived = $this->derivePaise($variant->id);

        if ($derived === null) {
            return null;
        }

        return $this->apply($variant, $derived, $source, $invoice?->id, $actorUserId, $note);
    }

    /**
     * Record a price an admin typed. Only reachable while the variant has no
     * purchase history — see {@see isDerivable()}.
     */
    public function recordManual(ProductVariant $variant, int $newPaise, ?int $actorUserId): ?LandingPriceHistory
    {
        return $this->apply($variant, $newPaise, LandingPriceHistory::SOURCE_MANUAL, null, $actorUserId, null);
    }

    /**
     * True once the system can work the price out for itself, which is the
     * moment the admin form stops accepting a typed value.
     *
     * Tied to posted GRNs rather than to on-hand stock: a variant that has
     * been bought and then sold out is still one the system knows the cost of,
     * and letting the field become editable again the moment stock hits zero
     * would hand back exactly the silent-rewrite problem this replaced.
     */
    public function isDerivable(int $variantId): bool
    {
        return PurchaseInvoiceItem::query()
            ->whereHas('purchaseInvoice', fn ($q) => $q->where('status', PurchaseInvoice::STATUS_POSTED))
            ->where('product_variant_id', $variantId)
            ->exists();
    }

    /**
     * The weighted-average landed cost of on-hand stock, or the latest posted
     * GRN's landed unit cost when nothing is on hand. Null when the variant
     * has never been purchased — there is nothing to derive from, and
     * overwriting a bootstrap price with zero would be worse than leaving it.
     */
    public function derivePaise(int $variantId): ?int
    {
        $batches = StockBatch::query()
            ->where('product_variant_id', $variantId)
            ->where('qty_on_hand', '>', 0)
            ->get(['qty_on_hand', 'unit_cost_paise']);

        $qty = (int) $batches->sum('qty_on_hand');

        if ($qty > 0) {
            $value = (int) $batches->sum(fn (StockBatch $b): int => $b->qty_on_hand * $b->unit_cost_paise);

            return intdiv($value, $qty);
        }

        $latest = PurchaseInvoiceItem::query()
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_items.purchase_invoice_id')
            ->where('purchase_invoice_items.product_variant_id', $variantId)
            ->where('purchase_invoices.status', PurchaseInvoice::STATUS_POSTED)
            ->orderByDesc('purchase_invoices.posted_at')
            ->orderByDesc('purchase_invoice_items.id')
            ->value('purchase_invoice_items.landed_unit_cost_paise');

        return $latest === null ? null : (int) $latest;
    }

    /** Recompute every variant a GRN touched, in one transaction with it. */
    public function recomputeForInvoice(PurchaseInvoice $invoice, ?int $actorUserId, string $note): void
    {
        $invoice->loadMissing('items');

        ProductVariant::query()
            ->whereIn('id', $invoice->items->pluck('product_variant_id')->unique())
            ->get()
            ->each(fn (ProductVariant $v) => $this->recompute(
                $v,
                LandingPriceHistory::SOURCE_GRN,
                $invoice,
                $actorUserId,
                $note,
            ));
    }

    private function apply(
        ProductVariant $variant,
        int $newPaise,
        string $source,
        ?int $invoiceId,
        ?int $actorUserId,
        ?string $note,
    ): ?LandingPriceHistory {
        $old = (int) $variant->landing_price_paise;

        if ($old === $newPaise) {
            return null;
        }

        return $this->db->transaction(function () use ($variant, $old, $newPaise, $source, $invoiceId, $actorUserId, $note): LandingPriceHistory {
            $variant->forceFill(['landing_price_paise' => $newPaise])->save();

            return LandingPriceHistory::create([
                'product_variant_id' => $variant->id,
                'old_paise' => $old,
                'new_paise' => $newPaise,
                'source' => $source,
                'purchase_invoice_id' => $invoiceId,
                'changed_by_user_id' => $actorUserId,
                'note' => $note,
            ]);
        });
    }
}
