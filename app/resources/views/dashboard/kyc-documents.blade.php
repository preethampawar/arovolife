@extends('layouts.app')
@section('title', 'My documents — arovolife')

@section('content')
@php
    // Friendly labels for the doc types the customer can manage themselves.
    $labels = [
        'pan' => ['title' => 'PAN card', 'help' => 'Front side, clearly readable.', 'required' => true],
        'aadhaar' => ['title' => 'Aadhaar (front)', 'help' => 'Front side showing photo, name and DOB.', 'required' => true],
        'aadhaar_back' => ['title' => 'Aadhaar (back)', 'help' => 'Back side showing the address printed by UIDAI.', 'required' => true],
        'cheque' => ['title' => 'Cancelled cheque or passbook page', 'help' => 'Account number and IFSC must be visible.', 'required' => false],
        'address_proof_front' => ['title' => 'Address proof (front)', 'help' => 'Aadhaar, passport, voter ID, driving licence, or utility bill (last 3 months).', 'required' => true],
        'address_proof_back' => ['title' => 'Address proof (back)', 'help' => 'Back of the same document.', 'required' => true],
    ];
@endphp

<div class="max-w-3xl mx-auto">
    <div class="mb-6">
        <a href="{{ route('dashboard') }}" class="text-sm text-brand-600 hover:underline">← Back to dashboard</a>
    </div>

    <h1 class="text-2xl font-bold text-gray-900 mb-2">My KYC documents</h1>
    <p class="text-sm text-gray-600 mb-6">
        PAN, Aadhaar (front and back) and address proof (front and back) are
        required for KYC approval. Cancelled cheque or passbook is optional —
        add it when you're ready. New uploads are pending admin review until
        approved.
    </p>

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

    <div class="space-y-3 mb-8">
        @foreach($selfServiceTypes as $type)
            @php
                $meta = $labels[$type] ?? ['title' => $type, 'help' => '', 'required' => false];
                $doc = $docsByType[$type] ?? null;
                $isApproved = $doc && $doc->verified_at !== null;
                $isPending = $doc && $doc->verified_at === null;
            @endphp
            <div class="rounded-2xl border border-gray-200 bg-white p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-gray-900">
                            {{ $meta['title'] }}
                            @if($meta['required'])
                                <span class="text-red-700">*</span>
                            @else
                                <span class="text-gray-500 text-xs font-normal">(optional)</span>
                            @endif
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $meta['help'] }}</p>
                    </div>
                    <div>
                        @if($isApproved)
                            <span class="inline-flex items-center rounded-full bg-green-50 border border-green-200 px-2 py-1 text-[11px] font-medium text-green-800">
                                ✓ Approved {{ optional($doc->verified_at)->format('d M Y') }}
                            </span>
                        @elseif($isPending)
                            <span class="inline-flex items-center rounded-full bg-amber-50 border border-amber-200 px-2 py-1 text-[11px] font-medium text-amber-800">
                                Pending review
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-gray-50 border border-gray-200 px-2 py-1 text-[11px] font-medium text-gray-600">
                                Not uploaded
                            </span>
                        @endif
                    </div>
                </div>

                @if(! $isApproved)
                <form method="POST" action="{{ route('dashboard.documents.store') }}" enctype="multipart/form-data" class="mt-4 flex flex-wrap items-center gap-3">
                    @csrf
                    <input type="hidden" name="type" value="{{ $type }}">
                    <input type="file" name="document" accept="image/jpeg,image/png,application/pdf" required
                        class="text-xs text-gray-800 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold hover:file:bg-gray-200">
                    <button type="submit"
                        class="px-3 py-1.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-xs font-medium transition-colors">
                        {{ $isPending ? 'Replace' : 'Upload' }}
                    </button>
                </form>
                @else
                <p class="mt-3 text-xs text-gray-500">
                    Already approved by admin. To replace, contact <a href="{{ route('content.show', 'grievance') }}" class="underline text-brand-600">support</a>.
                </p>
                @endif
            </div>
        @endforeach
    </div>

    <p class="text-xs text-gray-500">
        Accepted formats: JPG, PNG, PDF. Max file size 5 MB per document. All
        uploads are encrypted at rest and reviewed by an admin before the
        document is marked approved.
    </p>
</div>
@endsection
