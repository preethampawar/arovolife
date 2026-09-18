<?php

declare(strict_types=1);

namespace App\Modules\Tax\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Shared\Support\IndianStates;
use App\Modules\Tax\Models\Invoice;
use App\Modules\Tax\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates the GST tax invoice for an order (R-28, CGST Rule 46).
 *
 * Three things this has to get right, and each was wrong before:
 *
 * **The supplier's identity.** `seller_gstin` was never written. An invoice
 * without it is not a tax invoice, and a buyer cannot claim input credit
 * against it. It now comes from `tax.seller_gstin`, and while that setting is
 * empty the document is honestly labelled a receipt rather than pretending.
 *
 * **The number.** It was `timestamp % 1000000` — not consecutive, and two
 * invoices raised 1,000,000 seconds apart carried the same identity. Now a
 * locked per-financial-year counter (`InvoiceNumberSequence`).
 *
 * **The value.** Catalogue prices here are GST-inclusive, so each line's tax is
 * `gross × rate / (10000 + rate)`. Whether a coupon or redeemed points come out
 * of the taxable value under CGST Act §15(3)(a) — or merely off the amount
 * payable — is `TaxSettings::discountsReduceTaxableValue()`, and it ships
 * `false`: checkout and the shipment journal both carry GST on the full sale
 * value, and this document is the source for GSTR-1, so declaring tax on a
 * reduced value here would put the return and the books in disagreement. The
 * apportionment below is what implements the other position if counsel ever
 * settles it that way (R-48 gate (c)).
 *
 * **The head.** CGST + SGST where the place of supply is the supplier's own
 * state, IGST where it is not — correct in shape, but it compared a two-letter
 * setting against a display-name column, so 'TG' never equalled 'Telangana' and
 * every intra-state supply was billed IGST. Both sides now normalise through
 * `IndianStates::canonical()` before the comparison.
 */
final class InvoiceGenerator
{
    public function __construct(
        private readonly TaxSettings $settings,
        private readonly InvoiceNumberSequence $numbers,
    ) {}

    /**
     * The state whose tax applies: where delivery terminates.
     *
     * For a home delivery that is the buyer's address. For a collection it is
     * the CENTRE's state, and reading `orders.ship_state` would be wrong twice
     * over — a collection order stores no address (R-47), so the column is
     * null, and the `?? $sellerState` fallback behind it would then quietly
     * make every inter-state collection look intra-state. CGST/SGST would be
     * charged where IGST is due, and the split is frozen onto the invoice at
     * checkout where no later render can correct it.
     *
     * Derived from the centre record rather than copied onto the order,
     * deliberately: the order must not assert an address the buyer never gave.
     */
    private function placeOfSupplyState(Order $order): ?string
    {
        if ($order->isCollection()) {
            return $order->areteCenter?->state;
        }

        return $order->ship_state;
    }

    public function generate(Order $order): Invoice
    {
        $existing = Invoice::where('order_id', $order->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        // The two sides of this comparison are written in different alphabets.
        // `tax.seller_state` is a two-letter code by design (the setting caps
        // at 2 characters), while checkout persists `orders.ship_state` as the
        // display name and a centre stores the same. Comparing them raw made
        // 'TELANGANA' !== 'TG' and answered "inter-state" for every supply the
        // company makes from its own state — IGST charged and remitted to the
        // Centre where CGST+SGST was due, on an invoice no later render can
        // correct. Normalise both to the canonical name before deciding.
        //
        // Absent and unrecognised are different answers and must stay that way.
        // No place of supply at all falls back to the seller's own state, as it
        // always has. A state that is present but unreadable must NOT: pointing
        // it at the seller would call an unknown supply intra-state, which is
        // the failure this whole comparison exists to prevent. It resolves to
        // null and bills IGST, so null === null can never read as a match.
        $rawSellerState = $this->settings->sellerState();
        $rawPlaceOfSupply = $this->placeOfSupplyState($order);
        $hasPlaceOfSupply = trim((string) $rawPlaceOfSupply) !== '';

        $sellerCanonical = IndianStates::canonical($rawSellerState);
        $placeCanonical = $hasPlaceOfSupply
            ? IndianStates::canonical($rawPlaceOfSupply)
            : $sellerCanonical;

        $isIntraState = $sellerCanonical !== null && $sellerCanonical === $placeCanonical;

        // An unrecognised state still has to print something, and the raw value
        // is more use to whoever has to fix it than a blank.
        $sellerState = $sellerCanonical ?? strtoupper($rawSellerState);
        $placeOfSupply = $placeCanonical
            ?? ($hasPlaceOfSupply ? strtoupper((string) $rawPlaceOfSupply) : $sellerState);

        // Everything that reduced what the buyer actually paid for goods: a
        // coupon and any redeemed points.
        //
        // Whether these come out of the TAXABLE VALUE under §15(3)(a) or
        // merely off the amount payable after tax is R-48 gate (c), which is
        // not settled. Under the shipping default they do not: checkout
        // computes `orders.gst_paise` on the undiscounted value and the
        // shipment journal credits `liability.gst_output` with that same
        // figure, so an invoice that declared tax on a reduced value would put
        // the GSTR-1 return and the books in disagreement — on a ₹1,180 order
        // settled with 1,000 points, ₹27 declared against ₹180 in the ledger.
        //
        // The invoice therefore declares the tax that was actually charged and
        // is actually remitted, and shows the reduction below it as what it is:
        // a reduction in the amount payable.
        $settlementReduction = (int) $order->discount_paise + (int) ($order->redeem_points_paise ?? 0);
        $reduction = $this->settings->discountsReduceTaxableValue() ? $settlementReduction : 0;

        return DB::transaction(function () use ($order, $sellerState, $placeOfSupply, $isIntraState, $reduction): Invoice {
            $issuedAt = Carbon::now();

            $invoice = Invoice::create([
                'order_id' => $order->id,
                'invoice_no' => $this->numbers->next($issuedAt),
                'issued_at' => $issuedAt,
                'seller_gstin' => $this->settings->sellerGstin(),
                'seller_state' => $sellerState,
                'buyer_gstin' => $order->buyer_gstin,
                'buyer_state' => $placeOfSupply,
                'place_of_supply' => $placeOfSupply,
                'subtotal_paise' => 0,
                'cgst_paise' => 0,
                'sgst_paise' => 0,
                'igst_paise' => 0,
                'total_paise' => $order->total_paise,
            ]);

            $lines = $this->apportion($order, $reduction);

            $taxableTotal = 0;
            $cgstTotal = 0;
            $sgstTotal = 0;
            $igstTotal = 0;

            foreach ($lines as $line) {
                $cgst = $isIntraState ? intdiv($line['gst_paise'], 2) : 0;
                $sgst = $isIntraState ? $line['gst_paise'] - $cgst : 0;
                $igst = $isIntraState ? 0 : $line['gst_paise'];

                $taxableTotal += $line['taxable_value_paise'];
                $cgstTotal += $cgst;
                $sgstTotal += $sgst;
                $igstTotal += $igst;

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'order_item_id' => $line['order_item_id'],
                    'hsn_code' => $line['hsn_code'],
                    'qty' => $line['qty'],
                    'taxable_value_paise' => $line['taxable_value_paise'],
                    'gst_rate_bp' => $line['gst_rate_bp'],
                    'cgst_paise' => $cgst,
                    'sgst_paise' => $sgst,
                    'igst_paise' => $igst,
                ]);
            }

            $invoice->update([
                'subtotal_paise' => $taxableTotal,
                'cgst_paise' => $cgstTotal,
                'sgst_paise' => $sgstTotal,
                'igst_paise' => $igstTotal,
            ]);

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * Spread an order-level reduction across the lines and recompute each
     * line's taxable value and tax from what was actually charged.
     *
     * Apportioned by line gross, so a discount falls on the lines in the
     * proportion they contributed to it. The last line absorbs the rounding
     * remainder, so the apportioned amounts sum to the reduction exactly — a
     * penny left unallocated would make the invoice fail to foot, which is the
     * one thing an invoice must never do.
     *
     * @return array<int, array{order_item_id: int, hsn_code: string, qty: int, taxable_value_paise: int, gst_paise: int, gst_rate_bp: int}>
     */
    private function apportion(Order $order, int $reduction): array
    {
        /** @var Collection<int, OrderItem> $items */
        $items = $order->items;
        $grossTotal = (int) $items->sum('line_total_paise');

        $lines = [];
        $allocated = 0;
        $lastIndex = $items->count() - 1;

        foreach ($items->values() as $index => $item) {
            $lineGross = (int) $item->line_total_paise;

            $share = $grossTotal > 0 && $reduction > 0
                ? ($index === $lastIndex
                    ? $reduction - $allocated
                    : (int) floor($reduction * $lineGross / $grossTotal))
                : 0;

            $allocated += $share;

            // Never let a reduction take a line below zero: a negative taxable
            // value is not a thing, and it would silently offset another line's
            // tax.
            $netGross = max(0, $lineGross - $share);
            $rateBp = (int) $item->gst_rate_bp;
            $gst = (int) round($netGross * $rateBp / (10000 + $rateBp));

            $lines[] = [
                'order_item_id' => (int) $item->id,
                'hsn_code' => (string) $item->hsn_code_snapshot,
                'qty' => (int) $item->qty,
                'taxable_value_paise' => $netGross - $gst,
                'gst_paise' => $gst,
                'gst_rate_bp' => $rateBp,
            ];
        }

        return $lines;
    }
}
