<?php

declare(strict_types=1);

namespace App\Modules\Tax\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Tax\Models\Invoice;
use App\Modules\Tax\Models\InvoiceLine;
use Illuminate\Support\Carbon;

/**
 * Generates GST invoices with CGST/SGST (intra-state) or IGST (inter-state) split.
 *
 * Seller state is hardcoded to 'TG' (Telangana — company registered state). In Phase 3
 * this should move to a setting.
 */
final class InvoiceGenerator
{
    private const SELLER_STATE = 'TG';

    public function generate(Order $order): Invoice
    {
        $existing = Invoice::where('order_id', $order->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $buyerState = $order->ship_state ?? self::SELLER_STATE;
        $isIntraState = strtoupper($buyerState) === self::SELLER_STATE;

        $invoiceNo = $this->generateInvoiceNo();

        $cgstTotal = 0;
        $sgstTotal = 0;
        $igstTotal = 0;

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'invoice_no' => $invoiceNo,
            'issued_at' => Carbon::now(),
            'seller_state' => self::SELLER_STATE,
            'buyer_state' => $buyerState,
            'place_of_supply' => $buyerState,
            'subtotal_paise' => $order->subtotal_paise - $order->gst_paise,
            'cgst_paise' => 0,
            'sgst_paise' => 0,
            'igst_paise' => 0,
            'total_paise' => $order->total_paise,
        ]);

        foreach ($order->items as $item) {
            $gst = $item->gst_paise;
            if ($isIntraState) {
                $cgst = (int) floor($gst / 2);
                $sgst = $gst - $cgst;
                $igst = 0;
                $cgstTotal += $cgst;
                $sgstTotal += $sgst;
            } else {
                $cgst = 0;
                $sgst = 0;
                $igst = $gst;
                $igstTotal += $igst;
            }

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'order_item_id' => $item->id,
                'hsn_code' => $item->hsn_code_snapshot,
                'qty' => $item->qty,
                'taxable_value_paise' => $item->taxable_value_paise,
                'gst_rate_bp' => $item->gst_rate_bp,
                'cgst_paise' => $cgst,
                'sgst_paise' => $sgst,
                'igst_paise' => $igst,
            ]);
        }

        $invoice->update([
            'cgst_paise' => $cgstTotal,
            'sgst_paise' => $sgstTotal,
            'igst_paise' => $igstTotal,
        ]);

        return $invoice->fresh(['lines']);
    }

    private function generateInvoiceNo(): string
    {
        $year = Carbon::now()->format('y');
        $seq = (int) (Carbon::now()->timestamp % 1000000);

        return sprintf('INV-%s-%06d', $year, $seq);
    }
}
