@extends('layouts.app')
@php
    $isEdit = $application !== null;
    $inp = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500';
    $lbl = 'block text-sm font-medium text-gray-700 mb-1.5';
    $v = fn (string $field, $default = '') => old($field, $application?->{$field} ?? $default);
@endphp
@section('title', $isEdit ? 'Update your Arete Development Centre application' : 'Apply to open an Arete Development Centre')

@section('content')

<div class="max-w-3xl mx-auto py-10">
    <div class="flex items-center gap-3 mb-2">
        <a href="{{ route('my.adc.status') }}" class="text-sm text-gray-600 hover:text-gray-700">← Back</a>
    </div>
    <h1 class="text-2xl font-bold mb-2">{{ $isEdit ? 'Update your application' : 'Apply to open an Arete Development Centre' }}</h1>

    {{-- Form-purpose note (platform convention). --}}
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold mb-1">What this form does</p>
        <p class="leading-relaxed">
            This applies to open an <strong>Arete Development Centre</strong> — a training, product-demonstration
            and support centre for arovolife distributors in your area. It is not a shop or retail outlet.
            arovolife will review the premises details and documents and email you the outcome.
            Applying is <strong>free</strong>, creates no purchase obligation and does not guarantee approval.
        </p>
    </div>

    @if($isEdit && $application->admin_notes)
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 mb-6 text-sm text-amber-900">
        <p class="font-semibold mb-1">Changes requested by arovolife</p>
        <p class="leading-relaxed">{{ $application->admin_notes }}</p>
    </div>
    @endif

    @if($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-700">
        <ul class="list-disc list-inside space-y-1">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ $isEdit ? route('my.adc.update') : route('my.adc.apply.submit') }}" enctype="multipart/form-data" class="space-y-8"
        data-confirm="{{ $isEdit ? 'Resubmit your application?' : 'Submit your application?' }}"
        data-confirm-title="Confirm Arete Development Centre application"
        data-confirm-impact="Your application will be reviewed by arovolife. There is no charge to apply.">
        @csrf
        @if($isEdit) @method('PUT') @endif

        {{-- A1 Applicant — read-only --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-base font-semibold text-gray-900 mb-1">1. Applicant</h2>
            <p class="text-xs text-gray-500 mb-4">Taken from your distributor record. To change any of these, update your profile.</p>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                @foreach([
                    'Name' => $applicant['name'], 'ADN' => $applicant['adn'],
                    'Date of joining' => $applicant['joined_on'], 'Current rank' => $applicant['rank'],
                    'Mobile' => $applicant['mobile'], 'Email' => $applicant['email'],
                    'Sponsor' => $applicant['sponsor'], 'Registered address' => $applicant['address'],
                ] as $label => $value)
                <div>
                    <dt class="text-xs uppercase tracking-wider text-gray-500">{{ $label }}</dt>
                    <dd class="text-gray-900 {{ $label === 'ADN' ? 'font-mono' : '' }}">{{ $value }}</dd>
                </div>
                @endforeach
            </dl>
        </section>

        {{-- A2 Centre identity --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6 space-y-4">
            <h2 class="text-base font-semibold text-gray-900">2. Centre</h2>
            <div>
                <label class="{{ $lbl }}">Proposed centre name <span class="text-red-500">*</span>
                    <x-help-tip text="The name the centre will be listed under for distributors choosing a centre. Must not match an existing active centre." /></label>
                <input type="text" name="centre_name" value="{{ $v('centre_name') }}" maxlength="200" required class="{{ $inp }}" placeholder="e.g. Sangareddy Arete Development Centre">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="{{ $lbl }}">Contact person for the centre
                        <x-help-tip text="Optional — leave blank if you will be the contact yourself." /></label>
                    <input type="text" name="contact_person" value="{{ $v('contact_person') }}" maxlength="150" class="{{ $inp }}" placeholder="{{ $applicant['name'] }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Alternate contact number
                        <x-help-tip text="Optional — a second number arovolife can reach about this centre." /></label>
                    <input type="tel" name="alternate_contact_number" value="{{ $v('alternate_contact_number') }}" maxlength="15" class="{{ $inp }} font-mono" placeholder="+919876543210">
                </div>
            </div>
        </section>

        {{-- A3 Premises --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6 space-y-4">
            <h2 class="text-base font-semibold text-gray-900">3. Premises</h2>
            <div>
                <label class="{{ $lbl }}">Address line 1 <span class="text-red-500">*</span></label>
                <input type="text" name="address_line_1" value="{{ $v('address_line_1') }}" maxlength="255" required class="{{ $inp }}" placeholder="Building / street">
            </div>
            <div>
                <label class="{{ $lbl }}">Address line 2</label>
                <input type="text" name="address_line_2" value="{{ $v('address_line_2') }}" maxlength="255" class="{{ $inp }}" placeholder="Area / locality">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="{{ $lbl }}">Landmark <span class="text-red-500">*</span></label>
                    <input type="text" name="landmark" value="{{ $v('landmark') }}" maxlength="150" required class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Pincode <span class="text-red-500">*</span></label>
                    <input type="text" name="pincode" value="{{ $v('pincode') }}" pattern="\d{6}" maxlength="6" inputmode="numeric" required class="{{ $inp }} font-mono" placeholder="502001">
                </div>
                <div>
                    <label class="{{ $lbl }}">City / district <span class="text-red-500">*</span></label>
                    <input type="text" name="city" value="{{ $v('city') }}" maxlength="100" required class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">State / Union Territory <span class="text-red-500">*</span></label>
                    <select name="state" required class="{{ $inp }}">
                        <option value="">— Select state —</option>
                        @foreach($states as $stateName)
                        <option value="{{ $stateName }}" @selected($v('state') === $stateName)>{{ $stateName }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <span class="{{ $lbl }}">Property type <span class="text-red-500">*</span></span>
                <div class="flex flex-wrap gap-4">
                    @foreach($propertyTypes as $key => $label)
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="radio" name="property_type" value="{{ $key }}" class="accent-brand-500" required @checked($v('property_type') === $key)> {{ $label }}
                    </label>
                    @endforeach
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="{{ $lbl }}">Size (sq. ft) <span class="text-red-500">*</span>
                        <x-help-tip text="Carpet area of the premises in square feet. The minimum accepted is {{ $minSqft }} sq ft." /></label>
                    <input type="number" name="premises_sqft" value="{{ $v('premises_sqft', $minSqft) }}" min="{{ $minSqft }}" max="100000" step="1" required class="{{ $inp }}">
                    <p class="text-xs text-gray-500 mt-1">Minimum {{ $minSqft }} sq ft.</p>
                </div>
                <div>
                    <label class="{{ $lbl }}">Distance from the nearest Arete Development Centre (km) <span class="text-red-500">*</span>
                        <x-help-tip text="Your own estimate, in kilometres, to the nearest existing centre. You can update it later." /></label>
                    <input type="number" name="distance_to_nearest_adc_km" value="{{ $v('distance_to_nearest_adc_km') }}" min="0" max="9999.9" step="0.1" required class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Operating hours — from <span class="text-red-500">*</span></label>
                    <input type="time" name="opening_time" value="{{ substr((string) $v('opening_time'), 0, 5) }}" required class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Operating hours — to <span class="text-red-500">*</span></label>
                    <input type="time" name="closing_time" value="{{ substr((string) $v('closing_time'), 0, 5) }}" required class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Weekly off <span class="text-red-500">*</span></label>
                    <select name="weekly_off" required class="{{ $inp }}">
                        <option value="">— Select —</option>
                        @foreach($weeklyOffOptions as $key => $label)
                        <option value="{{ $key }}" @selected($v('weekly_off') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        {{-- A4 Documents --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6 space-y-4">
            <h2 class="text-base font-semibold text-gray-900">4. Documents</h2>
            <p class="text-xs text-gray-500">PDF, JPG or PNG, up to 5 MB each. Documents are stored privately and seen only by the arovolife review team.
                @if($isEdit) Files you already uploaded are kept unless you choose a replacement. @endif</p>
            @foreach($documentTypes as $type => $meta)
            @php $existing = $isEdit ? $application->documents->where('type', $type) : collect(); @endphp
            <div>
                <label class="{{ $lbl }}">{{ $meta['label'] }}
                    @if($meta['required'] && ! $isEdit)<span class="text-red-500">*</span>@endif
                    @if($meta['max'] > 1)<span class="text-xs text-gray-500">(up to {{ $meta['max'] }} files)</span>@endif
                </label>
                <input type="file" name="documents[{{ $type }}][]" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                       @if($meta['max'] > 1) multiple @endif
                       @if($meta['required'] && ! $isEdit) required @endif
                       class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100">
                @if($existing->isNotEmpty())
                <p class="text-xs text-gray-600 mt-1">Already uploaded: {{ $existing->pluck('original_name')->join(', ') }}</p>
                @endif
            </div>
            @endforeach
        </section>

        {{-- A5 Declarations --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6 space-y-3">
            <h2 class="text-base font-semibold text-gray-900">5. Declarations <span class="text-red-500">*</span></h2>
            <p class="text-xs text-gray-500">Each declaration must be accepted. Your acceptance is recorded with the date, time and IP address.</p>
            @foreach($declarations as $key => $text)
            <label class="flex items-start gap-3 text-sm text-gray-800">
                <input type="checkbox" name="declarations[]" value="{{ $key }}" required class="mt-1 accent-brand-500"
                       @checked(in_array($key, (array) old('declarations', []), true))>
                <span>{{ $text }}</span>
            </label>
            @endforeach
        </section>

        <button type="submit"
            class="w-full inline-flex justify-center items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-6 py-3 text-sm transition-colors">
            {{ $isEdit ? 'Resubmit application' : 'Submit application' }}
        </button>
    </form>
</div>

@endsection
