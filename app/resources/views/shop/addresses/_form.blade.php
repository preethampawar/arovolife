{{-- Reusable add/edit form for a saved shipping address.
     Vars: $action (url), $method ('POST'|'PATCH'), $address (CustomerAddress|null),
           $presetLabels (array), $submitLabel (string) --}}
@php
    $a = $address ?? null;
    $phone10 = $a ? preg_replace('/^\+91/', '', (string) $a->phone_e164) : '';
@endphp
<form method="POST" action="{{ $action }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @csrf
    @if(($method ?? 'POST') === 'PATCH')@method('PATCH')@endif

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Label</label>
        <input name="label" type="text" list="addr-label-options" maxlength="40"
               value="{{ old('label', $a->label ?? '') }}" placeholder="Home, Work, Office…"
               class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
        <datalist id="addr-label-options">
            @foreach($presetLabels as $preset)<option value="{{ $preset }}"></option>@endforeach
        </datalist>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Recipient name *</label>
        <input name="name" type="text" required maxlength="150" value="{{ old('name', $a->name ?? '') }}"
               class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Mobile number *</label>
        <div class="flex">
            <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-gray-300 bg-gray-50 text-sm text-gray-500">+91</span>
            <input name="phone" type="tel" required pattern="[6-9]\d{9}" maxlength="10" inputmode="numeric"
                   value="{{ old('phone', $phone10) }}"
                   class="w-full rounded-r-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
        </div>
    </div>
    <div class="flex items-end">
        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="is_default" value="1" {{ old('is_default', $a->is_default ?? false) ? 'checked' : '' }}
                   class="rounded text-brand-600 focus:ring-brand-500">
            Set as my default delivery address
        </label>
    </div>
    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Address line 1 *</label>
        <input name="line1" type="text" required maxlength="255" value="{{ old('line1', $a->line1 ?? '') }}"
               placeholder="House/Flat no., building, street"
               class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
    </div>
    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Address line 2</label>
        <input name="line2" type="text" maxlength="255" value="{{ old('line2', $a->line2 ?? '') }}"
               placeholder="Landmark, area"
               class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">City *</label>
        <input name="city" type="text" required maxlength="100" value="{{ old('city', $a->city ?? '') }}"
               class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">State *</label>
        <input name="state" type="text" required maxlength="64" value="{{ old('state', $a->state ?? '') }}"
               class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Pincode *</label>
        <input name="pincode" type="text" required pattern="\d{6}" maxlength="6" inputmode="numeric"
               value="{{ old('pincode', $a->pincode ?? '') }}"
               class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
    </div>
    <div class="md:col-span-2 flex items-center gap-3 pt-1">
        <button type="submit" class="px-5 py-2.5 rounded-full bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors">
            {{ $submitLabel ?? 'Save address' }}
        </button>
        @if(isset($cancelTarget))
        <button type="button" data-addr-cancel="{{ $cancelTarget }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</button>
        @endif
    </div>
</form>
