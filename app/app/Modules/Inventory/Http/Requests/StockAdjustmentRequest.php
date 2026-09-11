<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\StockAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StockAdjustmentRequest extends FormRequest
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
            'warehouse_code' => ['required', 'string', 'exists:warehouses,code'],
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'stock_batch_id' => ['nullable', 'integer', 'exists:stock_batches,id'],
            'qty_delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', Rule::in(StockAdjustment::REASONS)],
            'notes' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'qty_delta.not_in' => 'An adjustment must have a non-zero quantity.',
        ];
    }
}
