@extends('layouts.app')

@section('title', 'Re-upload your KYC documents')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-8">

    @if(session('status'))
    <div class="rounded-xl border border-leaf-200 bg-leaf-50 p-4 mb-6 text-sm text-leaf-800">
        {{ session('status') }}
    </div>
    @endif

    @if($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-700">
        @foreach($errors->all() as $error)
        <p>{{ $error }}</p>
        @endforeach
    </div>
    @endif

    <div class="rounded-2xl border border-red-200 bg-red-50 p-6 mb-6">
        <h1 class="text-xl font-semibold text-red-900 mb-2">Your KYC documents need an update</h1>
        <p class="text-sm text-red-800 leading-relaxed mb-3">
            Our compliance team reviewed your submission for ADN
            <span class="font-mono text-red-900 font-semibold">{{ $distributor->adn }}</span>
            @if($rejectedAt)
                on <strong>{{ \Carbon\Carbon::parse($rejectedAt)->format('d M Y') }}</strong>
            @endif
            and asked for an update before approving your account.
        </p>

        @if($rejectionReason)
        <div class="mt-4 rounded-lg border border-red-300 bg-white p-4 text-sm text-red-900">
            <p class="text-xs font-semibold text-red-700 uppercase tracking-wider mb-1.5">Reviewer's note</p>
            <p class="leading-relaxed">{{ $rejectionReason }}</p>
        </div>
        @endif
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-6 mb-6">
        <p class="text-base font-semibold text-gray-800 mb-1.5">Upload replacement documents</p>
        <p class="text-xs text-gray-500 mb-4">
            Re-upload only the documents that need correction. Anything you don't upload here stays
            as it was. Accepted formats: JPEG, PNG or PDF — max 5 MB per file. Your account will move
            straight back into the review queue.
        </p>

        <form method="POST" action="{{ route('kyc.resubmit.submit') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">PAN card (front)</label>
                <input type="file" name="pan_doc" accept=".jpg,.jpeg,.png,.pdf"
                    class="w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:text-brand-700 file:px-3 file:py-1.5 file:text-sm file:cursor-pointer hover:file:bg-brand-100">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Aadhaar (front)</label>
                <input type="file" name="aadhaar_doc" accept=".jpg,.jpeg,.png,.pdf"
                    class="w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:text-brand-700 file:px-3 file:py-1.5 file:text-sm file:cursor-pointer hover:file:bg-brand-100">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Cancelled cheque / passbook page <span class="text-gray-400">(optional)</span></label>
                <input type="file" name="cheque_doc" accept=".jpg,.jpeg,.png,.pdf"
                    class="w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:text-brand-700 file:px-3 file:py-1.5 file:text-sm file:cursor-pointer hover:file:bg-brand-100">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Address proof (front) <span class="text-gray-400">(optional)</span></label>
                <input type="file" name="address_proof_front" accept=".jpg,.jpeg,.png,.pdf"
                    class="w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:text-brand-700 file:px-3 file:py-1.5 file:text-sm file:cursor-pointer hover:file:bg-brand-100">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Address proof (back) <span class="text-gray-400">(optional)</span></label>
                <input type="file" name="address_proof_back" accept=".jpg,.jpeg,.png,.pdf"
                    class="w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:text-brand-700 file:px-3 file:py-1.5 file:text-sm file:cursor-pointer hover:file:bg-brand-100">
            </div>

            <button type="submit"
                class="w-full sm:w-auto rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-6 py-2.5 text-sm transition-colors">
                Resubmit for review
            </button>
        </form>
    </div>

    <p class="text-xs text-gray-500 text-center">
        Need help? Write to <a href="mailto:support@arovolife.com" class="text-brand-600 hover:text-brand-700 underline">support@arovolife.com</a>
        or visit our <a href="{{ route('p.grievance') ?? '/p/grievance' }}" class="text-brand-600 hover:text-brand-700 underline">grievance page</a>.
    </p>

    <form method="POST" action="{{ route('logout') }}" class="text-center mt-4">
        @csrf
        <button type="submit" class="text-xs text-gray-500 hover:text-gray-700 underline">Sign out</button>
    </form>
</div>
@endsection
