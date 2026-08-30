@extends('admin.layouts.admin')
@section('title', 'Add a franchise')
@section('heading', 'Add a franchise')

@section('content')
<a href="{{ route('admin.commerce.franchises.index') }}" class="text-sm text-brand-700 underline hover:text-brand-800">← Franchises</a>

{{-- Form-purpose note. --}}
<div class="mt-4 mb-6 max-w-2xl rounded-xl border border-gray-200 bg-white shadow-sm px-5 py-4 text-sm leading-relaxed text-gray-700">
    <p class="mb-1 font-semibold text-gray-900">What this form does</p>
    <p>
        Records a franchise <strong>application</strong>. It is not live and earns nothing until someone
        approves it on the next screen, and it cannot be chosen as a collection point at checkout before then.
    </p>
    <p class="mt-2">
        The franchise code is generated automatically in the form <code>FR-XXXXX</code> — deliberately unlike
        a nine-digit ADN, so the two identifiers can never be confused.
    </p>
</div>

@if ($errors->any())
    <div class="mb-6 max-w-2xl rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <ul class="list-inside list-disc space-y-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('admin.commerce.franchises.store') }}"
      class="max-w-2xl space-y-5 rounded-xl border border-gray-200 bg-white shadow-sm p-6"
      data-confirm="Record this franchise application?"
      data-confirm-title="New franchise application"
      data-confirm-impact="It is recorded as pending. Nothing earns and nothing appears at checkout until it is approved.">
    @csrf

    @include('admin.commerce.franchises._form', ['franchise' => null, 'planRateBp' => $planRateBp])

    <button type="submit" class="rounded-lg bg-brand-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-800">
        Record application
    </button>
</form>

<x-confirm-modal />
@endsection
