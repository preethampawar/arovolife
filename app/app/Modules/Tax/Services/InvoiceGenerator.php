<?php

declare(strict_types=1);

namespace App\Modules\Tax\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Tax\Models\Invoice;
use App\Modules\Tax\Models\InvoiceLine;
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
 * **The value.** Tax was computed from the pre-discount subtotal, so an order
 * with a coupon or redeemed points reported more GST than the buyer was
 * charged and the invoice did not foot. Under CGST Act §15(3)(a) a discount
 * given at the time of supply and shown on the invoice is excluded from the
 * transaction value, so the reduction is apportioned across the lines and the
 * tax recomputed from what was actually charged. Catalogue prices here are
 * GST-inclusive, so each line's tax is `gross × rate / (10000 + rate)`.
 *
 * The split itself was already right: CGST + SGST where the place of supply is
 * the supplier's own state, IGST where it is not.
 */
final class InvoiceGenerator
{
    public function __construct(
        private readonly TaxSettings $settings,
        private readonly InvoiceNumberSequence $numbers,
    ) {}

    public function generate(Order $order): Invoice
    {
        $existing = Invoice::where('order_id', $order->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $sellerState = $this->settings->sellerState();
        $placeOfSupply = strtoupper($order->ship_state ?? $sellerState);
        $isIntraState = $placeOfSupply === $sellerState;

        // Everything that reduced what the buyer actually paid for goods. Both
        // are §15(3)(a) discounts shown on the invoice, so both come out of the
        // taxable value.
        $reduction = (int) $order->discount_paise + (int) ($order->redeem_points_paise ?? 0);

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
        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Commerce\Models\OrderItem> $items */
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
