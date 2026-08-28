@extends('layouts.wizard')
@section('title', 'Step 11 — Arete Development Centre')
@php $currentStep = 11; @endphp

@section('content')
<div class="max-w-2xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Arete Development Centre</h2>
    <p class="text-sm text-gray-600 mb-6">
        Every arovolife distributor is connected to an Arete Development Centre. Your centre
        provides local support, training and events. You can change it later from your profile.
    </p>

    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register.arete') }}" class="space-y-4">
        @csrf

        @if($centers->isEmpty())
            {{-- Company default is always present after seeding; this is a last-resort fallback. --}}
            @php
                $fallbackId = $defaultCenter?->id ?? 0;
            @endphp
            <input type="hidden" name="center_id" value="{{ $fallbackId }}">
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
                You will be enrolled in the <strong>Arovolife Company Centre</strong> by default. You can select a different centre from your profile after registration.
            </div>
        @else
            <div class="space-y-3">
                @foreach($centers as $center)
                <label class="flex items-start gap-3 p-4 rounded-xl border cursor-pointer transition-colors
                    {{ $selectedId === $center->id ? 'border-brand-500 bg-brand-50' : 'border-gray-200 bg-white hover:border-brand-300' }}">
                    <input type="radio" name="center_id" value="{{ $center->id }}"
                           class="mt-0.5 shrink-0 accent-brand-500"
                           {{ $selectedId === $center->id ? 'checked' : '' }}>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-900">
                            {{ $center->name }}
                            @if($center->is_company_default)
                                <span class="ml-2 inline-flex px-2 py-0.5 text-[10px] font-medium rounded-full bg-brand-100 text-brand-700">Company default</span>
                            @endif
                        </p>
                        @if($center->location)
                            <p class="text-xs text-gray-600 mt-0.5">{{ $center->location }}</p>
                        @endif
                    </div>
                </label>
                @endforeach
            </div>

            <p class="text-xs text-gray-600">
                Your Arete centre assignment can be changed later from your profile (OTP required).
            </p>
        @endif

        <div class="flex items-center justify-between pt-4">
            <a href="{{ route('register.documents') }}" class="text-sm text-gray-600 hover:text-gray-700">← Back</a>
            <button type="submit"
                    class="px-6 py-2.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold shadow-sm transition-colors">
                Continue →
            </button>
        </div>
    </form>
</div>
@endsection
