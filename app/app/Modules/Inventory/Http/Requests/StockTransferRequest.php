<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockTransferRequest extends FormRequest
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
            'from_warehouse_code' => ['required', 'string', 'exists:warehouses,code'],
            'to_warehouse_code' => ['required', 'string', 'different:from_warehouse_code', 'exists:warehouses,code'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.stock_batch_id' => ['required', 'integer', 'exists:stock_batches,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_warehouse_code.different' => 'A transfer must move stock between two different warehouses.',
            'lines.required' => 'Add at least one line item.',
        ];
    }
}
