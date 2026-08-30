@extends('admin.layouts.admin')
@php
    use App\Modules\Compensation\Models\AreteCenter;

    $center = $center ?? null;
    $isEdit = $center !== null;
    $action = $isEdit
        ? route('admin.arete-centres.update', $center)
        : route('admin.arete-centres.store');
    $inp = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400';
    $lbl = 'block text-sm font-medium text-gray-700 mb-1';
    $v = fn (string $field, $default = '') => old($field, $center?->{$field} ?? $default);
@endphp
@section('title', $isEdit ? 'Edit Centre' : 'Add Centre')
@section('heading', $isEdit ? 'Edit Arete Development Centre' : 'Add Arete Development Centre')

@section('content')
@include('admin.arete-centres._tabs')

<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('admin.arete-centres.index') }}" class="text-sm text-gray-600 hover:text-gray-700">← Registry</a>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 max-w-2xl">
    <div class="mb-4 text-sm text-blue-800 bg-blue-50 border border-blue-200 rounded-lg px-4 py-3">
        @if($isEdit)
            Update this Arete Development Centre. Every change is recorded in the audit log. Status, company default and deactivation are changed from the registry list, not here.
        @else
            Add a company-approved Arete Development Centre directly. Distributor-run centres normally arrive through the application queue; add one here only when the paperwork was handled offline.
        @endif
    </div>

    <form method="POST" action="{{ $action }}" class="space-y-6"
          data-confirm="{{ $isEdit ? 'Save changes to this centre?' : 'Add this centre?' }}"
          data-confirm-title="{{ $isEdit ? 'Edit centre' : 'Add centre' }}"
          data-confirm-impact="{{ $isEdit ? 'The registry entry is updated and the change is audit-logged.' : 'The centre is created active and becomes selectable at Step 11 immediately.' }}">
        @csrf
        @if($isEdit) @method('PUT') @endif

        @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
        @endif

        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-900">Centre</legend>
            <div>
                <label class="{{ $lbl }}">Centre name <span class="text-red-500">*</span> <x-help-tip text="Name distributors see when choosing a centre." /></label>
                <input type="text" name="name" value="{{ $v('name') }}" required maxlength="200" class="{{ $inp }}">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="{{ $lbl }}">Type <span class="text-red-500">*</span> <x-help-tip text="A company centre has no owner and earns nothing. A distributor centre is owned by the ADN below, who earns the ADC Bonus on its members' BV." /></label>
                    <select name="centre_type" required class="{{ $inp }}">
                        @foreach(AreteCenter::TYPES as $key => $label)
                        <option value="{{ $key }}" @selected($v('centre_type', AreteCenter::TYPE_COMPANY) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">Owner ADN <x-help-tip text="Required for a distributor centre: the ADN that receives the ADC Bonus. Change it here to transfer the centre to another distributor (audit-logged). Leave blank for a company centre." /></label>
                    <input type="text" name="assigned_adn" value="{{ old('assigned_adn', $center?->assignedDistributor?->adn ?? '') }}" placeholder="e.g. ARV1000001" class="{{ $inp }} font-mono">
                </div>
                <div>
                    <label class="{{ $lbl }}">Contact person <x-help-tip text="Who to reach at the centre. Optional." /></label>
                    <input type="text" name="contact_person" value="{{ $v('contact_person') }}" maxlength="150" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Contact number</label>
                    <input type="tel" name="contact_number" value="{{ $v('contact_number') }}" maxlength="15" class="{{ $inp }} font-mono" placeholder="+919876543210">
                </div>
                <div>
                    <label class="{{ $lbl }}">Alternate contact number</label>
                    <input type="tel" name="alternate_contact_number" value="{{ $v('alternate_contact_number') }}" maxlength="15" class="{{ $inp }} font-mono">
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-900">Premises</legend>
            <div>
                <label class="{{ $lbl }}">Address line 1</label>
                <input type="text" name="address_line_1" value="{{ $v('address_line_1', $center?->location ?? '') }}" maxlength="255" class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Address line 2</label>
                <input type="text" name="address_line_2" value="{{ $v('address_line_2') }}" maxlength="255" class="{{ $inp }}">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="{{ $lbl }}">Landmark</label>
                    <input type="text" name="landmark" value="{{ $v('landmark') }}" maxlength="150" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Pincode <x-help-tip text="6-digit postal code. Used to search the ADC monthly calculation report by area." /></label>
                    <input type="text" name="pincode" value="{{ $v('pincode') }}" maxlength="6" inputmode="numeric" placeholder="e.g. 502001" class="{{ $inp }} font-mono">
                </div>
                <div>
                    <label class="{{ $lbl }}">City / district</label>
                    <input type="text" name="city" value="{{ $v('city', $center?->district ?? '') }}" maxlength="100" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">State / Union Territory</label>
                    <select name="state" class="{{ $inp }}">
                        <option value="">— Select state —</option>
                        @foreach(\App\Modules\Shared\Support\IndianStates::all() as $stateName)
                        <option value="{{ $stateName }}" @selected($v('state') === $stateName)>{{ $stateName }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">Property type</label>
                    <select name="property_type" class="{{ $inp }}">
                        <option value="">—</option>
                        @foreach(AreteCenter::PROPERTY_TYPES as $key => $label)
                        <option value="{{ $key }}" @selected($v('property_type') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">Size (sq ft)</label>
                    <input type="number" name="premises_sqft" value="{{ $v('premises_sqft') }}" min="1" max="100000" step="1" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Distance to nearest ADC (km) <x-help-tip text="Self-declared by the applicant; kept for reference." /></label>
                    <input type="number" name="distance_to_nearest_adc_km" value="{{ $v('distance_to_nearest_adc_km') }}" min="0" max="9999.9" step="0.1" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Weekly off</label>
                    <select name="weekly_off" class="{{ $inp }}">
                        <option value="">—</option>
                        @foreach(AreteCenter::WEEKLY_OFF_OPTIONS as $key => $label)
                        <option value="{{ $key }}" @selected($v('weekly_off') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">Opens at</label>
                    <input type="time" name="opening_time" value="{{ substr((string) $v('opening_time'), 0, 5) }}" class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Closes at</label>
                    <input type="time" name="closing_time" value="{{ substr((string) $v('closing_time'), 0, 5) }}" class="{{ $inp }}">
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-900">Development and approval</legend>
            <div>
                <label class="{{ $lbl }}">
                    Development phase
                    <x-help-tip text="The centre's current development phase, judged on its ADC income in a single calendar month. Upgrade it here only after the owner has emailed the company a letter and photos proving the facility upgrade." />
                </label>
                <select name="development_phase" class="{{ $inp }}">
                    @foreach(AreteCenter::PHASES as $phaseNo => $phaseLabel)
                    <option value="{{ $phaseNo }}" @selected((int) old('development_phase', $center->development_phase ?? 1) === $phaseNo)>{{ $phaseLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $lbl }}">
                    Monthly cap override (₹)
                    <x-help-tip text="Leave blank to pay up to the standard ADC cap. Set a lower amount to apply the development-phase penalty: a centre that crossed a phase income level without emailing proof of the upgrade is paid a lower slab income until it does. The override can only lower the standard cap, never raise it. Clear it once the upgrade is verified." />
                </label>
                <input type="number" name="monthly_cap_override" value="{{ old('monthly_cap_override', isset($center->monthly_cap_override_paise) ? intdiv($center->monthly_cap_override_paise, 100) : '') }}"
                       min="0" max="100000" step="1" placeholder="Blank = standard cap" class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Approval date <x-help-tip text="Date the company approved this centre. Optional." /></label>
                <input type="date" name="approved_at" value="{{ $v('approved_at') }}" class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Notes <x-help-tip text="Internal notes about this centre. Optional." /></label>
                <textarea name="notes" rows="3" class="{{ $inp }}">{{ $v('notes') }}</textarea>
            </div>
        </fieldset>

        <div class="pt-2 flex gap-3">
            <button type="submit" class="px-5 py-2 bg-brand-700 text-white text-sm rounded-lg hover:bg-brand-800 transition-colors">
                {{ $isEdit ? 'Save changes' : 'Add Centre' }}
            </button>
            <a href="{{ route('admin.arete-centres.index') }}"
               class="px-5 py-2 border border-gray-200 text-gray-600 text-sm rounded-lg hover:bg-gray-50 transition-colors">Cancel</a>
        </div>
    </form>
</div>

@endsection
