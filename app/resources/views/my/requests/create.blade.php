@extends('layouts.app')
@section('title', 'New request')

@php
    use App\Modules\Identity\Models\DistributorRequest;
    $inp = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500';
    $lbl = 'block text-sm font-medium text-gray-700 mb-1.5';
    $selectedType = old('type', $type);
    $meta = $types[$selectedType];
@endphp

@section('content')
<div>
    <div class="flex items-center gap-3 mb-2">
        <a href="{{ route('my.requests.index') }}" class="text-sm text-gray-600 hover:text-gray-700">← My requests</a>
    </div>
    <h1 class="text-2xl font-bold mb-2">New request</h1>

    {{-- Form-purpose note (platform convention). --}}
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold mb-1">What this form does</p>
        <p class="leading-relaxed">Files a formal request about your own distributor record. It is free, and nothing changes until the arovolife team has checked the supporting document and approved it — you will be emailed the outcome. Mobile, email and address are changed on <a href="{{ route('profile.show') }}" class="underline">My profile</a> instead.</p>
    </div>

    @if($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-700">
        <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
    @endif

    {{-- Type picker: a GET so the form can show the right fields without JS. --}}
    <form method="GET" action="{{ route('my.requests.create') }}" class="rounded-2xl border border-gray-200 bg-white p-6 mb-6">
        <label class="{{ $lbl }}" for="type-picker">Request type <span class="text-red-500">*</span>
            <x-help-tip text="Pick the closest match. The fields and documents below change with the type." /></label>
        <select id="type-picker" name="type" class="{{ $inp }}" onchange="this.form.submit()">
            @foreach($types as $key => $t)
            <option value="{{ $key }}" @selected($selectedType === $key)>{{ $t['label'] }}</option>
            @endforeach
        </select>
        <noscript><button type="submit" class="mt-2 text-sm text-brand-700">Change type</button></noscript>
        <p class="text-xs text-gray-600 mt-2">{{ $meta['summary'] }}</p>
    </form>

    <form method="POST" action="{{ route('my.requests.store') }}" enctype="multipart/form-data" class="space-y-6"
          data-confirm="Submit this request?" data-confirm-title="Confirm request"
          data-confirm-impact="Your request will be reviewed by arovolife. Nothing on your record changes until it is approved.">
        @csrf
        <input type="hidden" name="type" value="{{ $selectedType }}">

        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-base font-semibold text-gray-900 mb-1">1. Applicant</h2>
            <p class="text-xs text-gray-500 mb-4">Taken from your distributor record.</p>
            <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-3 text-sm">
                <div><dt class="text-xs uppercase tracking-wider text-gray-500">ADN</dt><dd class="font-mono text-gray-900">{{ $current['adn'] }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wider text-gray-500">Name</dt><dd class="text-gray-900">{{ $current['name'] }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wider text-gray-500">Email</dt><dd class="text-gray-900">{{ $current['email'] }}</dd></div>
            </dl>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 space-y-4">
            <h2 class="text-base font-semibold text-gray-900">2. {{ $meta['label'] }}</h2>

            @if(in_array($selectedType, [DistributorRequest::TYPE_NAME_CORRECTION, DistributorRequest::TYPE_NAME_CHANGE], true))
            <div>
                <label class="{{ $lbl }}">Name as it should appear <span class="text-red-500">*</span>
                    <x-help-tip text="Exactly as printed on your PAN card. Your current name on record is shown above." /></label>
                <input type="text" name="requested_full_name" value="{{ old('requested_full_name') }}" maxlength="150" required class="{{ $inp }}">
            </div>
            @elseif($selectedType === DistributorRequest::TYPE_DOB_CORRECTION)
            <div>
                <label class="{{ $lbl }}">Correct date of birth <span class="text-red-500">*</span>
                    <x-help-tip text="As on your PAN card or birth certificate. Currently on record: {{ $current['date_of_birth'] }}." /></label>
                <input type="date" name="requested_date_of_birth" value="{{ old('requested_date_of_birth') }}" required max="{{ now()->subYears(18)->toDateString() }}" class="{{ $inp }}">
                <p class="text-xs text-gray-500 mt-1">Currently on record: {{ $current['date_of_birth'] }}.</p>
            </div>
            @elseif($selectedType === DistributorRequest::TYPE_MEMBERSHIP_TRANSFER)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                A transfer is reviewed by compliance under the Direct Seller Agreement. One PAN can hold only one ADN, so the relation must not already be a distributor, and they will complete their own KYC before the transfer takes effect.
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="{{ $lbl }}">Relation's full name <span class="text-red-500">*</span></label>
                    <input type="text" name="transferee_name" value="{{ old('transferee_name') }}" maxlength="150" required class="{{ $inp }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">Relationship <span class="text-red-500">*</span></label>
                    <select name="relationship" required class="{{ $inp }}">
                        <option value="">— Select —</option>
                        @foreach($relationships as $key => $label)
                        <option value="{{ $key }}" @selected(old('relationship') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">Relation's mobile <span class="text-red-500">*</span></label>
                    <input type="tel" name="transferee_mobile" value="{{ old('transferee_mobile') }}" maxlength="15" required class="{{ $inp }} font-mono" placeholder="+919876543210">
                </div>
                <div>
                    <label class="{{ $lbl }}">Relation's email</label>
                    <input type="email" name="transferee_email" value="{{ old('transferee_email') }}" maxlength="255" class="{{ $inp }}">
                </div>
            </div>
            @elseif($selectedType === DistributorRequest::TYPE_ID_CANCELLATION)
            @if($current['in_cooling_off'])
            <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-900">
                You are still within your 30-day cooling-off period. Use the one-click cancellation on <a href="{{ route('dashboard') }}" class="underline font-medium">your dashboard</a> instead — it closes the account immediately and refunds in full.
            </div>
            @endif
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                Cancelling closes your ADN permanently. Earned income already due to you is still paid under the plan; nothing new accrues after the closure date. Compliance will confirm the closure date with you.
            </div>
            <label class="flex items-start gap-3 text-sm text-gray-800">
                <input type="checkbox" name="acknowledged" value="1" required class="mt-1 accent-brand-500" @checked(old('acknowledged'))>
                <span>I understand my distributorship will be closed and my ADN cannot be reused.</span>
            </label>
            @endif

            <div>
                <label class="{{ $lbl }}">Reason / details <span class="text-red-500">*</span>
                    <x-help-tip text="Tell us briefly why you are making this request. Do not type your full PAN or Aadhaar number here — upload the document instead." /></label>
                <textarea name="reason" rows="4" maxlength="2000" required class="{{ $inp }}">{{ old('reason') }}</textarea>
            </div>
        </section>

        @if($meta['documents'] !== [])
        <section class="rounded-2xl border border-gray-200 bg-white p-6 space-y-4">
            <h2 class="text-base font-semibold text-gray-900">3. Documents</h2>
            <p class="text-xs text-gray-500">PDF, JPG or PNG, up to 5 MB each. Stored privately and seen only by the arovolife review team. Please mask all but the last 4 digits of an Aadhaar number before uploading.</p>
            @foreach($meta['documents'] as $docType => $doc)
            <div>
                <label class="{{ $lbl }}">{{ $doc['label'] }} @if($doc['required'])<span class="text-red-500">*</span>@endif</label>
                <input type="file" name="documents[{{ $docType }}][]" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" @if($doc['required']) required @endif
                       class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100">
            </div>
            @endforeach
        </section>
        @endif

        <button type="submit" class="w-full inline-flex justify-center items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-6 py-3 text-sm transition-colors">Submit request</button>
    </form>
</div>
@endsection
