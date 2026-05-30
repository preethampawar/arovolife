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

{{-- User-status banner. The KYC queue index filters by status='pending' so
     anyone reaching this page directly may be in a non-standard state. Make
     it obvious so the admin doesn't accidentally re-approve a closed account
     or re-reject an active one. --}}
@php
    $status = $distributor->user?->status ?? 'unknown';
    $statusMeta = [
        'pending'    => ['bg' => 'bg-amber-50',  'border' => 'border-amber-200',  'text' => 'text-amber-900',  'label' => 'Pending review',     'note' => $hasPriorRejection ? 'This is a RE-SUBMISSION — the applicant previously had documents rejected and uploaded replacements.' : 'New submission awaiting review.'],
        'active'     => ['bg' => 'bg-green-50',  'border' => 'border-green-200',  'text' => 'text-green-900',  'label' => 'Already approved',   'note' => 'This distributor was already approved. Re-approving will reset their activation timestamp.'],
        'rejected'   => ['bg' => 'bg-red-50',    'border' => 'border-red-200',    'text' => 'text-red-900',    'label' => 'Rejected',           'note' => 'The applicant was previously rejected and has not yet resubmitted. They can log in and re-upload at /kyc/resubmit.'],
        'terminated' => ['bg' => 'bg-gray-100',  'border' => 'border-gray-300',   'text' => 'text-gray-900',   'label' => 'Account closed',     'note' => 'This account is permanently terminated. No further action is normally appropriate here.'],
        'frozen'     => ['bg' => 'bg-purple-50', 'border' => 'border-purple-200', 'text' => 'text-purple-900', 'label' => 'Frozen (hold)',      'note' => 'This account is on a compliance hold.'],
    ][$status] ?? ['bg' => 'bg-gray-50', 'border' => 'border-gray-200', 'text' => 'text-gray-900', 'label' => ucfirst($status), 'note' => ''];
@endphp
<div class="rounded-xl border {{ $statusMeta['border'] }} {{ $statusMeta['bg'] }} p-4 mb-6">
    <p class="text-sm font-semibold {{ $statusMeta['text'] }}">{{ $statusMeta['label'] }}</p>
    @if($statusMeta['note'])
    <p class="text-xs {{ $statusMeta['text'] }} mt-1 leading-relaxed">{{ $statusMeta['note'] }}</p>
    @endif
    @if($hasPriorRejection && $lastRejectionReason)
    <div class="mt-3 rounded-lg border border-amber-200 bg-white p-3 text-xs text-amber-900">
        <p class="font-semibold uppercase tracking-wider text-[10px] text-amber-700 mb-1">Last rejection reason</p>
        <p class="leading-relaxed">{{ $lastRejectionReason }}</p>
    </div>
    @endif
</div>

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
        @php
            // Pre-sign one URL per document up front, valid for 30 minutes,
            // so the <img> tag points straight at S3 (or local) and the
            // browser doesn't have to round-trip through the streamDocument
            // controller for every image. The "Open full size" link still
            // hits the controller so admin clicks are audit-logged and
            // RBAC-checked at view time.
            $diskKyc = \Illuminate\Support\Facades\Storage::disk('kyc');
            $isS3Disk = config('filesystems.disks.kyc.driver') === 's3';
        @endphp
        <ul class="text-sm space-y-3">
            @foreach($distributor->kycDocuments as $doc)
                @php
                    $ext = strtolower(pathinfo($doc->object_storage_key, PATHINFO_EXTENSION));
                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
                    $auditedUrl = route('admin.kyc.document', [$distributor->id, $doc->id]);

                    // Direct-from-S3 signed URL for the inline thumbnail.
                    // temporaryUrl() is pure SigV4 math — no S3 round-trip —
                    // so this stays cheap even with 7 docs in a couple unit.
                    // Local disk (dev) falls back to the audited route.
                    $directUrl = $isS3Disk
                        ? (string) $diskKyc->temporaryUrl($doc->object_storage_key, now()->addMinutes(30))
                        : $auditedUrl;
                @endphp
                <li class="border border-gray-200 rounded-lg overflow-hidden">
                    <div class="flex justify-between items-center px-3 py-2 bg-gray-50">
                        <span class="text-gray-700 text-xs font-medium">{{ str_replace('_', ' ', $doc->type) }}</span>
                        <a href="{{ $auditedUrl }}" target="_blank"
                            class="text-[11px] text-brand-600 hover:text-brand-700 underline">Open full size →</a>
                    </div>
                    @if($isImage)
                        <a href="{{ $auditedUrl }}" target="_blank" class="block bg-gray-100">
                            <img src="{{ $directUrl }}" alt="{{ $doc->type }}"
                                 class="w-full h-40 object-contain bg-white"
                                 onerror="this.replaceWith(Object.assign(document.createElement('p'),{className:'text-xs text-red-600 p-3',textContent:'Image could not be loaded — file may be missing on disk.'}))">
                        </a>
                    @else
                        <div class="p-3 text-xs text-gray-500 bg-white">
                            {{ strtoupper($ext) ?: 'File' }} document — open in new tab
                        </div>
                    @endif

                    {{-- Flag this single document for re-upload. Distinct from
                         rejecting the whole KYC — the applicant gets an email
                         with a signed link to re-upload only this document. --}}
                    @if($doc->verified_at === null)
                        <div class="px-3 py-2 border-t border-gray-100 bg-white">
                            @if($doc->isFlagged())
                                <p class="text-[11px] text-amber-700 mb-1">
                                    <span class="font-semibold">Flagged for re-upload</span>
                                    on {{ $doc->flagged_at->format('d M Y H:i') }}
                                </p>
                                <p class="text-[11px] text-gray-600 italic">"{{ $doc->flagged_reason }}"</p>
                                <p class="text-[10px] text-gray-500 mt-1">Awaiting applicant re-upload.</p>
                            @else
                                <details class="text-[11px]">
                                    <summary class="cursor-pointer text-amber-700 hover:text-amber-800 font-medium select-none">Flag this document for re-upload</summary>
                                    <form method="POST"
                                          action="{{ route('admin.kyc.document.flag', [$distributor->id, $doc->id]) }}"
                                          class="mt-2 space-y-2"
                                          data-confirm="Flag this document for re-upload?"
                                          data-confirm-title="Confirm flag"
                                          data-confirm-impact="The applicant is emailed your reason and a signed link to re-upload only this document. The rest of their KYC submission is untouched.">
                                        @csrf
                                        <textarea name="reason" required minlength="8" maxlength="1024" rows="2"
                                            placeholder="Reason (sent verbatim to the applicant) — e.g. PAN card is blurry; please re-upload a sharper photo."
                                            class="w-full rounded border border-amber-300 px-2 py-1 text-[11px] focus:border-amber-500 focus:ring-amber-500"></textarea>
                                        <button type="submit"
                                            class="w-full inline-flex justify-center items-center rounded bg-amber-500 hover:bg-amber-600 text-white text-[11px] font-medium px-3 py-1">
                                            Flag &amp; email applicant
                                        </button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
        @endif
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <form method="POST" action="{{ route('admin.kyc.approve', $distributor->id) }}"
        data-confirm="Approve this KYC submission?"
        data-confirm-title="Confirm KYC approval"
        data-confirm-impact="Activates the account so the distributor can sign in, and purges the stored full PAN/Aadhaar, keeping only the last 4. The activation is audit-logged."
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
        data-confirm="Reject this KYC submission?"
        data-confirm-title="Confirm KYC rejection"
        data-confirm-impact="Sets the account to rejected and emails the distributor your reason. This is reversible — they can re-upload corrected documents."
        class="rounded-2xl border border-red-200 bg-red-50 p-6 space-y-3">
        @csrf
        <p class="text-base font-semibold text-red-800">Reject KYC (recoverable) <x-help-tip text="The reason you enter is emailed verbatim to the distributor along with a link to re-upload corrected documents." /></p>
        <p class="text-xs text-red-700">
            The distributor's status flips to <strong>rejected</strong>. They are emailed the reason
            and a link to re-upload corrected documents. Required: a brief, accurate reason
            (8–1024 characters, included verbatim in the email).
        </p>
        <textarea name="reason" required minlength="8" maxlength="1024" rows="3"
            class="w-full rounded-lg border border-red-300 bg-white px-3 py-2 text-sm focus:border-red-500 focus:ring-red-500"
            placeholder="e.g. Aadhaar image is unreadable; cheque image does not match the IFSC entered."></textarea>
        <button type="submit"
            class="w-full inline-flex justify-center items-center rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium px-4 py-2.5 text-sm transition-colors">
            Reject and request re-upload
        </button>
    </form>
</div>

{{-- Permanent closure action — distinct from Reject. Use for fraud,
     repeat rejections, or any case where the applicant should not be
     allowed to retry. The Reject card above is the right tool for
     "fix and resubmit" cases. --}}
<details class="mt-6 rounded-2xl border border-gray-300 bg-gray-50 p-6">
    <summary class="cursor-pointer text-sm font-semibold text-gray-800">
        Terminate account permanently (irreversible) &nbsp;⤓
    </summary>
    <form method="POST" action="{{ route('admin.kyc.terminate', $distributor->id) }}" class="mt-4 space-y-3"
        data-confirm="Terminate this account permanently?"
        data-confirm-title="Confirm termination"
        data-confirm-impact="Permanently closes the account; the applicant is emailed a closure notice and can never sign in again. This is irreversible.">
        @csrf
        <p class="text-xs text-gray-600 leading-relaxed">
            Use this only when reject + resubmit is not appropriate: confirmed fraud, repeat
            rejections of the same issue, or any other end-of-relationship case. The distributor
            is emailed the closure notice and the audit log records the reason. There is no
            recovery path from this state — the applicant cannot sign in afterwards.
        </p>
        <textarea name="reason" required minlength="8" maxlength="1024" rows="3"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-gray-500 focus:ring-gray-500"
            placeholder="e.g. Fraudulent PAN — does not match the name on the Aadhaar card. Multiple inconsistencies after re-upload."></textarea>
        <button type="submit"
            class="w-full sm:w-auto inline-flex justify-center items-center rounded-lg bg-gray-800 hover:bg-gray-900 text-white font-medium px-4 py-2.5 text-sm transition-colors">
            Terminate account permanently
        </button>
    </form>
</details>

{{-- Admin document upload --}}
<div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6">
    <p class="text-base font-semibold text-gray-800 mb-1">Upload document on behalf of distributor</p>
    <p class="text-xs text-gray-500 mb-4">
        Use this for manually-created accounts or when a resubmission is needed.
        Uploading replaces any existing <em>unverified</em> document of the same type.
        Verified documents cannot be replaced — reject KYC first.
    </p>
    @if(session('status'))
    <div class="mb-3 rounded-lg bg-green-50 border border-green-200 px-3 py-2 text-sm text-green-800">
        {{ session('status') }}
    </div>
    @endif
    @if($errors->has('document') || $errors->has('type'))
    <div class="mb-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">
        {{ $errors->first('document') ?: $errors->first('type') }}
    </div>
    @endif
    <form method="POST"
          action="{{ route('admin.kyc.document.upload', $distributor->id) }}"
          enctype="multipart/form-data"
          data-confirm="Upload this document on the applicant's behalf?"
          data-confirm-title="Confirm document upload"
          data-confirm-impact="Adds the selected document to this applicant's KYC record, replacing any existing unverified document of the same type. Verified documents are not affected."
          class="space-y-3">
        @csrf
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-gray-600 mb-1">Document type <x-help-tip text="The KYC document category being uploaded; uploading replaces any existing unverified document of the same type." /></label>
                <select name="type" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Select type…</option>
                    <option value="pan">PAN card</option>
                    <option value="aadhaar">Aadhaar (front)</option>
                    <option value="aadhaar_back">Aadhaar (back)</option>
                    <option value="cheque">Cancelled cheque / passbook</option>
                    <option value="address_proof_front">Address proof (front)</option>
                    <option value="address_proof_back">Address proof (back)</option>
                    <option value="photo">Photo</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">File (JPEG, PNG or PDF — max 5 MB) <x-help-tip text="The document image or PDF to attach to this applicant's KYC record; accepted formats are JPEG, PNG or PDF up to 5 MB." /></label>
                <input type="file" name="document" required accept=".jpg,.jpeg,.png,.pdf"
                    class="w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0
                           file:bg-brand-50 file:text-brand-700 file:px-3 file:py-1.5 file:text-sm
                           file:cursor-pointer hover:file:bg-brand-100">
            </div>
        </div>
        <button type="submit"
            class="w-full sm:w-auto rounded-lg bg-gray-800 hover:bg-gray-900 text-white font-medium
                   px-6 py-2 text-sm transition-colors">
            Upload
        </button>
    </form>
</div>

<a href="{{ route('admin.kyc.index') }}" class="inline-block mt-6 text-sm text-gray-500 hover:text-gray-700">
    ← Back to queue
</a>

@endsection
