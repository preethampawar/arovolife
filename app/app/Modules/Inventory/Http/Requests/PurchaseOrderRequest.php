<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Prices are entered in RUPEES; the controller converts to paise before
 * calling `PurchaseOrderService`.
 */
final class PurchaseOrderRequest extends FormRequest
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
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'warehouse_code' => ['required', 'string', 'exists:warehouses,code'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'distinct', 'integer', 'exists:product_variants,id'],
            'lines.*.qty_ordered' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one line item.',
            'lines.*.product_variant_id.distinct' => 'Each product may appear only once on a purchase order.',
        ];
    }
}
