{{-- Shared fields for the franchise create and edit forms. --}}
@php
    $franchise = $franchise ?? null;
    $value = fn (string $field, $fallback = null) => old($field, $franchise?->{$field} ?? $fallback);
@endphp

<div>
    <label for="name" class="mb-1.5 block text-sm font-medium text-gray-700">Franchise name</label>
    <input id="name" name="name" type="text" maxlength="160" required value="{{ $value('name') }}"
           class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="operator_adn" class="mb-1.5 block text-sm font-medium text-gray-700">
            Operating distributor (ADN)
            <x-help-tip text="The distributor who runs this pickup point and receives the monthly commission. Their Genos position is unaffected — operating a franchise is fulfilment work, not a tree placement." />
        </label>
        <input id="operator_adn" name="operator_adn" type="text" maxlength="16"
               value="{{ old('operator_adn', $franchise?->operator?->adn) }}"
               class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 font-mono text-sm text-gray-900">
    </div>

    <div>
        <label for="commission_rate_bp" class="mb-1.5 block text-sm font-medium text-gray-700">
            Rate override (basis points)
            <x-help-tip text="Leave blank to use the plan rate. 300 = 3%. Only set this where the individual franchise agreement says something different." />
        </label>
        <input id="commission_rate_bp" name="commission_rate_bp" type="number" min="0" max="1000"
               placeholder="Plan rate: {{ $planRateBp }} bp ({{ number_format($planRateBp / 100, 2) }}%)"
               value="{{ $value('commission_rate_bp') }}"
               class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900 placeholder-gray-400">
    </div>
</div>

<div>
    <label for="address_line" class="mb-1.5 block text-sm font-medium text-gray-700">Address</label>
    <input id="address_line" name="address_line" type="text" maxlength="255" value="{{ $value('address_line') }}"
           class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
</div>

<div class="grid gap-4 sm:grid-cols-3">
    <div>
        <label for="pincode" class="mb-1.5 block text-sm font-medium text-gray-700">PIN code</label>
        <input id="pincode" name="pincode" type="text" maxlength="6" value="{{ $value('pincode') }}"
               class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
    </div>
    <div>
        <label for="district" class="mb-1.5 block text-sm font-medium text-gray-700">District</label>
        <input id="district" name="district" type="text" maxlength="100" value="{{ $value('district') }}"
               class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
    </div>
    <div>
        <label for="state" class="mb-1.5 block text-sm font-medium text-gray-700">State</label>
        <input id="state" name="state" type="text" maxlength="100" value="{{ $value('state') }}"
               class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
    </div>
</div>

<div class="rounded-lg bg-gray-50 px-4 py-3">
    <input type="hidden" name="is_company_primary" value="0">
    <label class="flex items-start gap-2.5 text-sm text-gray-700">
        <input type="checkbox" name="is_company_primary" value="1"
               @checked(old('is_company_primary', $franchise?->is_company_primary))
               class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
        <span>
            <span class="font-medium">This is the company's own primary franchise.</span>
            It needs no operating distributor and earns no commission — the company does not pay itself out
            of its own revenue.
        </span>
    </label>
</div>

<div>
    <label for="notes" class="mb-1.5 block text-sm font-medium text-gray-700">Notes</label>
    <textarea id="notes" name="notes" rows="3" maxlength="2000"
              class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">{{ $value('notes') }}</textarea>
</div>
