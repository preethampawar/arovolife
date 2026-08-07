@extends('admin.layouts.admin')
@php
    $center = $center ?? null;
    $isEdit = $center !== null;
    $action = $isEdit
        ? route('admin.compensation.adc-bonus.centers.update', $center)
        : route('admin.compensation.adc-bonus.centers.store');
@endphp
@section('title', $isEdit ? 'Edit Center' : 'Add Center')
@section('heading', $isEdit ? 'Edit Arete Development Center' : 'Add Arete Development Center')

@section('content')

<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('admin.compensation.adc-bonus.centers.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← All centers</a>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 max-w-xl">
    <div class="mb-4 text-sm text-blue-800 bg-blue-50 border border-blue-200 rounded-lg px-4 py-3">
        @if($isEdit)
            Update this company-approved Arete Development Center. Every change is recorded in the audit log.
        @else
            Add a company-approved Arete Development Center and assign it to the distributor who will earn the ADC Bonus.
        @endif
    </div>

    <form method="POST" action="{{ $action }}" class="space-y-4">
        @csrf
        @if($isEdit) @method('PUT') @endif

        @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Center name <span class="text-red-500">*</span> <x-help-tip text="Name of the company-approved Arete Development Center." /></label>
            <input type="text" name="name" value="{{ old('name', $center->name ?? '') }}" required maxlength="200"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Location <x-help-tip text="City or address of the center. Optional." /></label>
            <input type="text" name="location" value="{{ old('location', $center->location ?? '') }}" maxlength="300"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Pincode <x-help-tip text="6-digit postal code of the center. Used to search the ADC monthly calculation report by area. Optional." /></label>
            <input type="text" name="pincode" value="{{ old('pincode', $center->pincode ?? '') }}" maxlength="6" inputmode="numeric"
                   placeholder="e.g. 502001"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">District <x-help-tip text="District the center operates in. Used to search the ADC monthly calculation report by area. Optional." /></label>
            <input type="text" name="district" value="{{ old('district', $center->district ?? '') }}" maxlength="100"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">State / Union Territory <x-help-tip text="State or union territory the center operates in. Used to search the ADC monthly calculation report by area. Optional." /></label>
            <select name="state"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                <option value="">— Select state —</option>
                @foreach(\App\Modules\Shared\Support\IndianStates::all() as $stateName)
                <option value="{{ $stateName }}" @selected(old('state', $center->state ?? '') === $stateName)>{{ $stateName }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Assigned distributor ADN <span class="text-red-500">*</span>
                <x-help-tip text="Enter the ADN of the distributor who will receive the ADC Bonus for this center." />
            </label>
            <input type="text" name="assigned_adn" value="{{ old('assigned_adn', $center?->assignedDistributor?->adn ?? '') }}" required
                   placeholder="e.g. ARV1000001"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Development phase
                <x-help-tip text="The center's current development phase, judged on its ADC income in a single calendar month. Upgrade it here only after the owner has emailed the company a letter and photos proving the facility upgrade." />
            </label>
            <select name="development_phase"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                @foreach(\App\Modules\Compensation\Models\AreteCenter::PHASES as $phaseNo => $phaseLabel)
                <option value="{{ $phaseNo }}" @selected((int) old('development_phase', $center->development_phase ?? 1) === $phaseNo)>{{ $phaseLabel }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Monthly cap override (₹)
                <x-help-tip text="Leave blank to pay up to the standard ADC cap. Set a lower amount to apply the development-phase penalty: a center that crossed a phase income level without emailing proof of the upgrade is paid a lower slab income until it does. The override can only lower the standard cap, never raise it. Clear it once the upgrade is verified." />
            </label>
            <input type="number" name="monthly_cap_override" value="{{ old('monthly_cap_override', isset($center->monthly_cap_override_paise) ? intdiv($center->monthly_cap_override_paise, 100) : '') }}"
                   min="0" max="100000" step="1" placeholder="Blank = standard cap"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Approval date <x-help-tip text="Date the company approved this center. Optional." /></label>
            <input type="date" name="approved_at" value="{{ old('approved_at', $center->approved_at ?? '') }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes <x-help-tip text="Internal notes about this center. Optional." /></label>
            <textarea name="notes" rows="3"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">{{ old('notes', $center->notes ?? '') }}</textarea>
        </div>

        <div class="pt-2 flex gap-3">
            <button type="submit" class="px-5 py-2 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 transition-colors">
                {{ $isEdit ? 'Edit Center' : 'Add Center' }}
            </button>
            <a href="{{ route('admin.compensation.adc-bonus.centers.index') }}"
               class="px-5 py-2 border border-gray-200 text-gray-600 text-sm rounded-lg hover:bg-gray-50 transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>

@endsection
