@extends('admin.layouts.admin')
@section('title', 'KYC review — ' . $distributor->adn)
@section('heading', 'KYC review — ' . $distributor->adn)

@section('content')

@if($errors->any())
<div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-700">
    @foreach($errors->all() as $error)
    <p>{{ $error }}</p>
    @endforeach
</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-6 lg:col-span-2">
        <p class="text-xs text-gray-500 uppercase tracking-wider mb-3">Applicant</p>
        <dl class="text-sm grid grid-cols-2 gap-y-2">
            <dt class="text-gray-600">ADN</dt>
            <dd class="font-mono font-bold text-brand-600 tracking-widest">{{ $distributor->adn }}</dd>

            <dt class="text-gray-600">Email</dt>
            <dd class="text-gray-900">{{ $distributor->user->email }}</dd>

            <dt class="text-gray-600">Phone</dt>
            <dd class="text-gray-900">{{ $distributor->user->phone_e164 }}</dd>

            <dt class="text-gray-600">PAN (last 4)</dt>
            <dd class="text-gray-900 font-mono">{{ $distributor->pan_last4 }}</dd>

            <dt class="text-gray-600">Aadhaar (last 4)</dt>
            <dd class="text-gray-900 font-mono">{{ $distributor->aadhaar_last4 ?? '—' }}</dd>

            <dt class="text-gray-600">IFSC</dt>
            <dd class="text-gray-900 font-mono">{{ $distributor->bank_ifsc }}</dd>

            <dt class="text-gray-600">State</dt>
            <dd class="text-gray-900">{{ $distributor->state }}</dd>

            <dt class="text-gray-600">Effective date</dt>
            <dd class="text-gray-900">{{ $distributor->effective_date->format('d M Y') }}</dd>
        </dl>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-6">
        <p class="text-xs text-gray-500 uppercase tracking-wider mb-3">Documents</p>
        @if($distributor->kycDocuments->isEmpty())
        <p class="text-sm text-gray-500">No documents uploaded.</p>
        @else
        <ul class="text-sm space-y-2">
            @foreach($distributor->kycDocuments as $doc)
            <li class="flex justify-between items-center">
                <span class="text-gray-700">{{ str_replace('_', ' ', $doc->type) }}</span>
                <a href="{{ route('admin.kyc.document', [$distributor->id, $doc->id]) }}"
                    target="_blank"
                    class="text-xs text-brand-600 hover:text-brand-700 underline">View →</a>
            </li>
            @endforeach
        </ul>
        @endif
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <form method="POST" action="{{ route('admin.kyc.approve', $distributor->id) }}"
        class="rounded-2xl border border-green-200 bg-green-50 p-6">
        @csrf
        <p class="text-base font-semibold text-green-800 mb-2">Approve KYC</p>
        <p class="text-xs text-green-700 mb-4">
            Confirms that PAN, Aadhaar, bank, and address documents are valid.
            Status will flip to active and the distributor can use their account.
        </p>
        <button type="submit"
            class="w-full inline-flex justify-center items-center rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium px-4 py-2.5 text-sm transition-colors">
            Approve
        </button>
    </form>

    <form method="POST" action="{{ route('admin.kyc.reject', $distributor->id) }}"
        class="rounded-2xl border border-red-200 bg-red-50 p-6 space-y-3">
        @csrf
        <p class="text-base font-semibold text-red-800">Reject KYC</p>
        <p class="text-xs text-red-700">
            The distributor's account will be terminated and the reason will be recorded
            in the audit log. Required: a brief, accurate reason (8–1024 characters).
        </p>
        <textarea name="reason" required minlength="8" maxlength="1024" rows="3"
            class="w-full rounded-lg border border-red-300 bg-white px-3 py-2 text-sm focus:border-red-500 focus:ring-red-500"
            placeholder="e.g. Aadhaar image is unreadable; cheque image does not match the IFSC entered."></textarea>
        <button type="submit"
            class="w-full inline-flex justify-center items-center rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium px-4 py-2.5 text-sm transition-colors">
            Reject
        </button>
    </form>
</div>

<a href="{{ route('admin.kyc.index') }}" class="inline-block mt-6 text-sm text-gray-500 hover:text-gray-700">
    ← Back to queue
</a>

@endsection
