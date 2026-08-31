@extends('layouts.app')
@section('title', 'Tax Statements (TDS)')

@section('content')
<div>
    <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">← Back to dashboard</a>

    <h1 class="text-2xl font-bold text-gray-900 mt-4 mb-2">Tax Statements (TDS)</h1>
    <p class="text-sm text-gray-600 mb-6">Quarterly TDS certificates and the annual Form 26AS reconciliation issued by arovolife.</p>

    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-10 text-center">
        <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-amber-50 text-amber-700 mb-4">
            <x-lucide-file-text class="w-7 h-7" />
        </span>
        <h2 class="text-lg font-semibold text-gray-900 mb-1">No tax statements yet</h2>
        <p class="text-sm text-gray-600 max-w-md mx-auto leading-relaxed">
            You don't have any TDS certificates or tax statements on file yet. Statements are issued
            quarterly once commission payouts begin — they'll show up here automatically when ready.
        </p>
        <p class="text-xs text-gray-600 mt-4">
            Questions? Email <a href="mailto:support@arovolife.com" class="text-brand-700 hover:underline">support@arovolife.com</a>.
        </p>
    </div>
</div>
@endsection
