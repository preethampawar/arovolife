@extends('layouts.wizard')
@section('title', 'Step 7 — Documents')
@php $currentStep = 9; @endphp

@section('content')
<div class="max-w-xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Upload your documents</h2>
    <p class="text-gray-600 text-sm mb-8">
        Upload clear scans or photos of each document. Accepted formats: JPEG, PNG, PDF.
        Max file size 5 MB. An admin will review and approve your documents before your
        registration becomes active.
    </p>

    <form method="POST" action="{{ url('/register/documents') }}" enctype="multipart/form-data" class="bg-white rounded-2xl border border-gray-200 p-8 space-y-6">
        @csrf

        @php
            $fields = [
                'pan_doc'             => ['label' => 'PAN card',                 'help' => 'Front side, clearly readable.'],
                'aadhaar_doc'         => ['label' => 'Aadhaar (front)',          'help' => 'Or e-Aadhaar PDF from UIDAI.'],
                'cheque_doc'          => ['label' => 'Cancelled cheque or passbook page', 'help' => 'Account number and IFSC must be visible.'],
                'address_proof_front' => ['label' => 'Address proof (front)',    'help' => 'Aadhaar, passport, voter ID, driving licence, or utility bill (last 3 months).'],
                'address_proof_back'  => ['label' => 'Address proof (back)',     'help' => 'Back of the same document.'],
            ];
        @endphp

        @foreach($fields as $name => $meta)
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ $meta['label'] }} <span class="text-red-700">*</span></label>
            <input type="file" name="{{ $name }}" accept="image/jpeg,image/png,application/pdf" required
                class="block w-full text-sm text-gray-700 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 @error($name) ring-1 ring-red-300 rounded-lg @enderror">
            <p class="text-xs text-gray-500 mt-1">{{ $meta['help'] }}</p>
            @error($name)<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        @endforeach

        @if($isCouple ?? false)
        <div class="border-t border-gray-100 pt-5 space-y-5">
            <p class="text-xs uppercase tracking-wider text-gray-500 font-medium">Spouse documents</p>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Spouse PAN card <span class="text-red-700">*</span></label>
                <input type="file" name="spouse_pan_doc" accept="image/jpeg,image/png,application/pdf" required
                    class="block w-full text-sm text-gray-700 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 @error('spouse_pan_doc') ring-1 ring-red-300 rounded-lg @enderror">
                <p class="text-xs text-gray-500 mt-1">Front side of your spouse's PAN card.</p>
                @error('spouse_pan_doc')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Spouse Aadhaar (front) <span class="text-red-700">*</span></label>
                <input type="file" name="spouse_aadhaar_doc" accept="image/jpeg,image/png,application/pdf" required
                    class="block w-full text-sm text-gray-700 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 @error('spouse_aadhaar_doc') ring-1 ring-red-300 rounded-lg @enderror">
                <p class="text-xs text-gray-500 mt-1">Or e-Aadhaar PDF from UIDAI.</p>
                @error('spouse_aadhaar_doc')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>
        </div>
        @endif

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('register.personal') }}"
               class="inline-flex items-center px-5 py-3 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                ← Back
            </a>
            <button type="submit"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Upload and continue →
            </button>
        </div>
    </form>
</div>
@endsection
