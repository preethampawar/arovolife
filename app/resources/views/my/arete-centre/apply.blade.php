@extends('layouts.app')
@php
    $isEdit = $application !== null;
    $inp = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500';
    $lbl = 'block text-sm font-medium text-gray-700 mb-1.5';
    $v = fn (string $field, $default = '') => old($field, $application?->{$field} ?? $default);
@endphp
@section('title', $isEdit ? 'Update your Arete Development Centre application' : 'Apply to open an Arete Development Centre')

@section('content')

<div>
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
            <p class="text-xs text-gray-500 mb-4">Taken from your distributor record. To change any of these, update your profile. Nothing here is re-entered or re-submitted with this form.</p>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                @foreach([
                    'Name' => $applicant['name'], 'ADN' => $applicant['adn'],
                    'Date of joining' => $applicant['joined_on'], 'Current level' => $applicant['rank'],
                    'Mobile' => $applicant['mobile'], 'Email' => $applicant['email'],
                    'Registered address' => $applicant['address'],
                ] as $label => $value)
                <div>
                    <dt class="text-xs uppercase tracking-wider text-gray-500">{{ $label }}</dt>
                    <dd class="text-gray-900 {{ $label === 'ADN' ? 'font-mono' : '' }}">{{ $value }}</dd>
                </div>
                @endforeach
                <div>
                    <label class="text-xs uppercase tracking-wider text-gray-500" for="applicant_alternate_mobile">Alternate mobile number
                        <x-help-tip text="Optional — a second number we can reach you on about this application." /></label>
                    <input id="applicant_alternate_mobile" type="tel" name="applicant_alternate_mobile" value="{{ $v('applicant_alternate_mobile') }}" maxlength="15" class="{{ $inp }} font-mono mt-1" placeholder="+919876543210">
                </div>
            </dl>

            <h3 class="text-sm font-semibold text-gray-900 mt-6 mb-1">Upline (sponsor)</h3>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-wider text-gray-500">Name (ADN)</dt>
                    <dd class="text-gray-900">{{ $applicant['sponsor'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wider text-gray-500">Date of joining</dt>
                    <dd class="text-gray-900" data-reveal-target data-masked="••••••••" data-full="{{ $applicant['sponsor_joined_on'] }}">••••••••</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wider text-gray-500">Current level</dt>
                    <dd class="text-gray-900" data-reveal-target data-masked="••••••••" data-full="{{ $applicant['sponsor_rank'] }}">••••••••</dd>
                </div>
            </dl>

            <h3 class="text-sm font-semibold text-gray-900 mt-6 mb-1">PAN and bank details on file</h3>
            <p class="text-xs text-gray-500 mb-3">Already verified through KYC — shown masked for your security. Use the eye to reveal.</p>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-wider text-gray-500">PAN</dt>
                    <dd class="text-gray-900 font-mono tracking-wider" data-reveal-target data-masked="{{ $identity['pan']['masked'] }}" data-full="{{ $identity['pan']['full'] ?? '' }}">{{ $identity['pan']['masked'] }}</dd>
                    @if($identity['pan']['full'] === null && $identity['pan']['masked'] !== '—')
                    <p class="text-[11px] text-gray-500 mt-0.5" data-reveal-note hidden>Only the last 4 characters are kept on file after KYC verification.</p>
                    @endif
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wider text-gray-500">Bank account number</dt>
                    <dd class="text-gray-900 font-mono tracking-wider" data-reveal-target data-masked="{{ $identity['bank_account']['masked'] }}" data-full="{{ $identity['bank_account']['full'] ?? '' }}">{{ $identity['bank_account']['masked'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wider text-gray-500">IFSC</dt>
                    <dd class="text-gray-900 font-mono">{{ $identity['bank_ifsc']['masked'] }}</dd>
                </div>
                <div class="flex items-end">
                    <button type="button" id="identityRevealToggle" class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:text-brand-800" aria-pressed="false">
                        <svg data-eye-open class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                        <svg data-eye-closed class="w-4 h-4" hidden fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                        <span data-reveal-label>Show details</span>
                    </button>
                </div>
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
            @continue($meta['image'])
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

            <h3 class="text-sm font-semibold text-gray-900 pt-2">Site photos</h3>
            <p class="text-xs text-gray-500">One JPG or PNG per view, maximum {{ $maxPhotoKb }} KB each. Larger phone photos are shrunk automatically before upload.
                @if($isEdit) A photo you already uploaded is kept unless you choose a replacement. @endif</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @foreach($documentTypes as $type => $meta)
            @continue(! $meta['image'])
            @php $existing = $isEdit ? $application->documents->where('type', $type) : collect(); @endphp
            <div>
                <label class="{{ $lbl }}">{{ $meta['label'] }}
                    @if($meta['required'] && ! $isEdit)<span class="text-red-500">*</span>@endif
                </label>
                <input type="file" name="documents[{{ $type }}][]" accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                       data-photo-input data-max-kb="{{ $maxPhotoKb }}"
                       @if($meta['required'] && ! $isEdit) required @endif
                       class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100">
                <p class="text-[11px] text-gray-500 mt-1" data-photo-note>Max {{ $maxPhotoKb }} KB.</p>
                @if($existing->isNotEmpty())
                <p class="text-xs text-gray-600 mt-1">Already uploaded: {{ $existing->pluck('original_name')->join(', ') }}</p>
                @endif
            </div>
            @endforeach
            </div>
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
<script>
(function () {
    // Eye toggle: masked ↔ full for the read-only identity block. `full`
    // is empty where the platform no longer holds the number.
    var toggle = document.getElementById('identityRevealToggle');
    if (toggle) {
        var shown = false;
        toggle.addEventListener('click', function () {
            shown = !shown;
            document.querySelectorAll('[data-reveal-target]').forEach(function (el) {
                var full = el.getAttribute('data-full');
                el.textContent = shown && full ? full : el.getAttribute('data-masked');
            });
            document.querySelectorAll('[data-reveal-note]').forEach(function (el) { el.hidden = !shown; });
            toggle.querySelector('[data-eye-open]').hidden = shown;
            toggle.querySelector('[data-eye-closed]').hidden = !shown;
            toggle.querySelector('[data-reveal-label]').textContent = shown ? 'Hide details' : 'Show details';
            toggle.setAttribute('aria-pressed', shown ? 'true' : 'false');
        });
    }

    // Shrink oversized photos in the browser so the per-photo cap is
    // workable with phone cameras. Falls back to the original file (and the
    // server-side error) if the browser cannot decode the image.
    function shrink(file, maxBytes) {
        return new Promise(function (resolve) {
            if (!/^image\/(jpeg|png)$/.test(file.type) || file.size <= maxBytes) { resolve(file); return; }
            var img = new Image();
            var url = URL.createObjectURL(file);
            img.onload = function () {
                URL.revokeObjectURL(url);
                var maxSide = 1600, quality = 0.85, w = img.width, h = img.height;
                var attempt = function () {
                    var scale = Math.min(1, maxSide / Math.max(w, h));
                    var canvas = document.createElement('canvas');
                    canvas.width = Math.round(w * scale); canvas.height = Math.round(h * scale);
                    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob(function (blob) {
                        if (!blob) { resolve(file); return; }
                        if (blob.size <= maxBytes || (maxSide <= 640 && quality <= 0.5)) {
                            var name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                            resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
                            return;
                        }
                        if (quality > 0.5) { quality -= 0.15; } else { maxSide = Math.round(maxSide * 0.75); }
                        attempt();
                    }, 'image/jpeg', quality);
                };
                attempt();
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
            img.src = url;
        });
    }

    document.querySelectorAll('[data-photo-input]').forEach(function (input) {
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            var maxBytes = parseInt(input.getAttribute('data-max-kb'), 10) * 1024;
            var note = input.parentElement.querySelector('[data-photo-note]');
            if (!file || typeof DataTransfer === 'undefined') { return; }
            shrink(file, maxBytes).then(function (out) {
                if (out !== file) {
                    var dt = new DataTransfer();
                    dt.items.add(out);
                    input.files = dt.files;
                }
                if (note) {
                    note.textContent = out.size > maxBytes
                        ? 'This photo is ' + Math.round(out.size / 1024) + ' KB — over the ' + Math.round(maxBytes / 1024) + ' KB limit. Please choose a smaller one.'
                        : 'Ready: ' + Math.round(out.size / 1024) + ' KB.';
                    note.classList.toggle('text-red-600', out.size > maxBytes);
                }
            });
        });
    });
})();
</script>
</div>

@endsection
