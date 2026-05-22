@extends('admin.layouts.admin')
@section('title', 'Help finish — '.$user->email)
@section('heading', 'Help finish registration')

@section('content')

@if(session('status'))
    <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-800">
        {{ session('status') }}
    </div>
@endif

@if($errors->any())
    <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-800">
        <ul class="list-disc pl-5 space-y-1">
            @foreach($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="mb-4">
    <a href="{{ route('admin.pending-registrations.index') }}" class="text-sm text-brand-600 hover:underline">← Back to pending list</a>
</div>

{{-- Customer summary --}}
<div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
    <h3 class="font-semibold text-gray-800 mb-3">Customer</h3>
    <div class="grid grid-cols-2 gap-4 text-sm">
        <div>
            <p class="text-xs text-gray-500 mb-0.5">Name</p>
            <p class="text-gray-800">{{ $user->full_name ?: '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-0.5">Email</p>
            <p class="text-gray-800 font-mono">{{ $user->email }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-0.5">Phone</p>
            <p class="text-gray-800 font-mono">{{ $user->phone_e164 ?: '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-0.5">Account created</p>
            <p class="text-gray-800">{{ $user->created_at?->format('d M Y H:i') ?: '—' }}</p>
        </div>
    </div>
</div>

{{-- Draft state --}}
@if($draft === null)
    <div class="bg-red-50 border border-red-200 rounded-2xl p-6 mb-6">
        <h3 class="font-semibold text-red-800 mb-2">No active draft</h3>
        <p class="text-sm text-red-700">
            This user created an account but has no active registration draft
            (it may have expired, or they may never have advanced past step 2).
            This tool can't finish them. Use the
            <a href="{{ route('admin.distributors.create') }}" class="underline font-medium">Add Distributor</a> form instead.
        </p>
    </div>
@else
    {{-- What the customer submitted --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h3 class="font-semibold text-gray-800 mb-3">What the customer has submitted</h3>
        <p class="text-xs text-gray-700 mb-4">
            Read from the customer's registration draft (encrypted at rest).
            The draft expires <strong>{{ \Carbon\Carbon::parse($draft->expires_at)->diffForHumans() }}</strong>.
        </p>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <p class="text-xs text-gray-500 mb-0.5">Sponsor ADN</p>
                <p class="text-gray-800 font-mono">{{ $sponsorAdn ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-0.5">Placement ADN / side</p>
                <p class="text-gray-800 font-mono">{{ $placementAdn ?? '—' }} {{ $draft->side_opt ? '· '.$draft->side_opt : '' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-0.5">PAN</p>
                <p class="text-gray-800 font-mono">
                    @if(!empty($wizardData['pan']['pan_number']))
                        <span class="text-green-700">✓ supplied</span> ({{ substr($wizardData['pan']['pan_number'], -4) }})
                    @else
                        <span class="text-red-700">✗ missing</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-0.5">Aadhaar</p>
                <p class="text-gray-800 font-mono">
                    @if(!empty($wizardData['aadhaar']['aadhaar_number']))
                        <span class="text-green-700">✓ supplied</span>
                    @else
                        <span class="text-red-700">✗ missing</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-0.5">Personal (state)</p>
                <p class="text-gray-800">
                    @if(!empty($wizardData['personal']['state']))
                        <span class="text-green-700">✓ {{ $wizardData['personal']['state'] }}</span>
                    @else
                        <span class="text-red-700">✗ missing</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-0.5">KYC documents in draft</p>
                <p class="text-gray-800 text-xs">
                    @if(empty($docsInDraft))
                        <span class="text-red-700">None — upload below</span>
                    @else
                        {{ count($docsInDraft) }}/{{ count($requiredDocFields) }} ·
                        <span class="text-gray-600">{{ implode(', ', $docsInDraft) }}</span>
                    @endif
                </p>
            </div>
        </div>
    </div>

    {{-- Upload docs on behalf --}}
    <div class="bg-white rounded-2xl border border-amber-300 p-6 mb-6">
        <h3 class="font-semibold text-gray-800 mb-2 flex items-center gap-2">
            Upload documents on customer's behalf
            <span class="text-[10px] font-semibold uppercase tracking-wider text-amber-700 bg-amber-100 px-2 py-0.5 rounded">Email-attestation</span>
        </h3>
        <p class="text-xs text-gray-700 mb-4">
            Use when the customer has emailed their scans because they couldn't
            upload from their device. Each upload is audit-logged as
            <span class="font-mono">admin.registration.docs_uploaded_on_behalf</span>
            with your admin ID + timestamp. Skip any field where the customer
            has already uploaded; the form only writes attached fields.
        </p>
        <form method="POST" action="{{ route('admin.pending-registrations.upload', $user->id) }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            @foreach(['pan_doc' => 'PAN scan', 'aadhaar_doc' => 'Aadhaar scan', 'cheque_doc' => 'Cancelled cheque / passbook', 'address_proof_front' => 'Address proof — front', 'address_proof_back' => 'Address proof — back (if required)'] as $field => $label)
                <div class="flex items-center gap-3">
                    <label for="{{ $field }}" class="text-xs text-gray-700 w-56 shrink-0">
                        {{ $label }}
                        @if(in_array($field, $docsInDraft, true))
                            <span class="text-green-700 ml-1">✓ on file</span>
                        @endif
                    </label>
                    <input type="file" name="{{ $field }}" id="{{ $field }}"
                        accept="image/jpeg,image/png,application/pdf"
                        class="text-xs text-gray-800 file:mr-2 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1 file:text-xs file:font-semibold hover:file:bg-gray-200">
                </div>
            @endforeach
            <button type="submit" class="px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium transition-colors">
                Upload attached files
            </button>
        </form>
    </div>

    {{-- Finalise --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-2">Finalise on behalf</h3>
        <p class="text-xs text-gray-700 mb-4">
            Runs the same RegistrationService::finalise() the customer would
            have triggered with their final Confirm click. Creates the
            distributor row, issues the ADN, opens the cooling-off clock,
            inserts the kyc_documents rows (from the draft), and emits the
            usual placement events. Audit-logged as
            <span class="font-mono">admin.registration.finalised_on_behalf</span>.
        </p>
        <form method="POST" action="{{ route('admin.pending-registrations.finalise', $user->id) }}">
            @csrf
            <button type="submit"
                onclick="return confirm('Finalise this registration on behalf of {{ $user->email }}? This will create the distributor row, issue the ADN, and start the 30-day cooling-off clock.');"
                class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors">
                Finalise &amp; issue ADN
            </button>
        </form>
    </div>
@endif

@endsection
