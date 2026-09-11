<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SupplierRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone_e164' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:64'],
            'pincode' => ['nullable', 'string', 'max:10'],
            'status' => ['required', Rule::in([Supplier::STATUS_ACTIVE, Supplier::STATUS_ARCHIVED])],
        ];
    }
}
