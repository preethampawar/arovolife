@extends('layouts.app')
@section('title', 'Re-upload document')

@section('content')
<div class="max-w-xl mx-auto py-10">
    <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">← Back to dashboard</a>

    <h1 class="text-2xl font-bold text-gray-900 mt-4 mb-2">Re-upload your {{ $documentTypeHuman }}</h1>

    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 mb-6 text-sm text-amber-900">
        <p class="font-semibold mb-1">Why this needs a re-upload</p>
        <p class="leading-relaxed">{{ $document->flagged_reason }}</p>
        <p class="text-xs text-amber-700 mt-2">Flagged on {{ $document->flagged_at->format('d M Y H:i') }}</p>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-6 mb-6">
        <p class="text-sm text-gray-600 mb-4 leading-relaxed">
            Upload a clearer version of your <strong>{{ $documentTypeHuman }}</strong>. The rest of your KYC submission is untouched — you only need to replace this one document.
        </p>

        <form method="POST" action="{{ route('kyc.reupload.store', $document->id) }}" enctype="multipart/form-data" class="space-y-4">
            @csrf

            <div>
                <label for="document" class="block text-xs font-medium text-gray-700 mb-1">New file</label>
                <input id="document" name="document" type="file" required
                    accept="image/jpeg,image/png,application/pdf"
                    class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100 {{ $errors->has('document') ? 'border border-red-400 rounded p-1' : '' }}">
                <p class="text-xs text-gray-500 mt-1">JPG, PNG or PDF. Max 5 MB. Make sure the entire document is in frame and the text is sharp.</p>
                @error('document')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <button type="submit"
                class="w-full inline-flex justify-center items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-6 py-3 text-sm transition-colors">
                Submit re-upload
            </button>
        </form>
    </div>

    <p class="text-xs text-gray-500 text-center">
        Questions? Email <a href="mailto:support@arovolife.com" class="text-brand-600 hover:underline">support@arovolife.com</a>.
    </p>
</div>
@endsection
