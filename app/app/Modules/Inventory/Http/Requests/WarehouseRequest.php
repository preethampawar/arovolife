<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already gates on this permission; re-checking here means
        // the request is safe to reuse even if a route stops doing so.
        return $this->user()?->can('inventory.manage') ?? false;
    }

    /**
     * `code` is the FK key every stock table joins on (plan §3), so it is only
     * accepted when creating — editing a warehouse never renames its code.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in([Warehouse::TYPE_HUB, Warehouse::TYPE_WAREHOUSE, Warehouse::TYPE_FRANCHISE])],
            'line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:64'],
            'pincode' => ['nullable', 'string', 'max:10'],
            'contact_phone_e164' => ['nullable', 'string', 'max:20'],
            'fulfils_orders' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in([Warehouse::STATUS_ACTIVE, Warehouse::STATUS_ARCHIVED])],
        ];

        if ($this->isMethod('post')) {
            $rules['code'] = ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('warehouses', 'code')];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated($key, $default);
        $data['fulfils_orders'] = $this->boolean('fulfils_orders');

        return $data;
    }
}
