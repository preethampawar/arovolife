<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\PurchaseInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Prices are entered in RUPEES and GST as a PERCENT for admin convenience;
 * the controller converts to paise / basis-points before calling
 * `PurchaseInvoiceService`.
 */
final class PurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already gates on this permission; re-checking here means
        // the request is safe to reuse even if a route stops doing so.
        return $this->user()?->can('inventory.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $purchaseInvoice = $this->route('purchaseInvoice');
        $id = $purchaseInvoice instanceof PurchaseInvoice ? $purchaseInvoice->id : null;

        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'warehouse_code' => ['required', 'string', 'exists:warehouses,code'],
            // Same supplier invoice cannot be entered twice (mirrors the
            // uniq_purchase_invoices_supplier_invoice DB constraint).
            'supplier_invoice_no' => [
                'required', 'string', 'max:64',
                Rule::unique('purchase_invoices', 'supplier_invoice_no')
                    ->where('supplier_id', $this->input('supplier_id'))
                    ->ignore($id),
            ],
            'supplier_invoice_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.batch_no' => ['required', 'string', 'max:64'],
            'lines.*.mfg_date' => ['nullable', 'date'],
            'lines.*.expiry_date' => ['nullable', 'date', 'after_or_equal:lines.*.mfg_date'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one line item.',
            'supplier_invoice_no.unique' => 'This supplier invoice number has already been entered for this supplier.',
        ];
    }
}
