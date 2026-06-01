<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Requests;

use App\Modules\Commerce\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the admin coupon create/update form. `value`, `max_discount` and
 * `min_purchase` are entered in human units (percent / rupees); the controller
 * converts rupee amounts to paise before persisting.
 */
final class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasRole('admin');
    }

    protected function prepareForValidation(): void
    {
        // Coupon codes are case-insensitive — store/compare uppercase.
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('coupon')?->id;
        $scope = $this->input('scope');

        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($id)],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in([Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED])],
            // percent → 1–100; fixed → rupees (>= 0).
            'value' => ['required', 'numeric', 'min:0', $this->input('type') === Coupon::TYPE_PERCENT ? 'max:100' : 'max:10000000'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'scope' => ['required', Rule::in([Coupon::SCOPE_ALL, Coupon::SCOPE_CATEGORY, Coupon::SCOPE_PRODUCT])],
            'scope_id' => [
                Rule::requiredIf(fn () => in_array($scope, [Coupon::SCOPE_CATEGORY, Coupon::SCOPE_PRODUCT], true)),
                'nullable',
                'integer',
                match ($scope) {
                    Coupon::SCOPE_CATEGORY => Rule::exists('product_categories', 'id'),
                    Coupon::SCOPE_PRODUCT => Rule::exists('products', 'id'),
                    default => 'nullable',
                },
            ],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in([Coupon::STATUS_ACTIVE, Coupon::STATUS_ARCHIVED])],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Code may contain only letters, numbers, hyphens and underscores.',
            'scope_id.required' => 'Choose the category or product this coupon applies to.',
        ];
    }
}
