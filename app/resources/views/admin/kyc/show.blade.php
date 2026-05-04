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

                    // Direct-from-S3 URL for the inline thumbnail. On the
                    // local disk (dev) we fall back to the audited route.
                    $directUrl = $auditedUrl;
                    if ($isS3Disk && $diskKyc->exists($doc->object_storage_key)) {
                        try {
                            $directUrl = (string) $diskKyc->temporaryUrl(
                                $doc->object_storage_key,
                                now()->addMinutes(30),
                            );
                        } catch (\Throwable $e) {
                            // If pre-signing fails for any reason fall back to
                            // the controller route so the page still renders.
                            $directUrl = $auditedUrl;
                        }
                    }
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
