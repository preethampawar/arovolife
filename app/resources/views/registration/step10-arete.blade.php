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
            <input type="hidden" name="center_id" value="{{ $defaultCenter?->id ?? 0 }}">
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
                You will be enrolled in the <strong>Arovolife Company Centre</strong> by default. You can select a different centre from your profile after registration.
            </div>
        @else
            @php
                $byState = $centers->groupBy(fn ($c) => $c->state ?? 'Other');
                $selected = $centers->firstWhere('id', $selectedId) ?? $defaultCenter;
            @endphp
            <div>
                <label for="center_id" class="block text-sm font-medium text-gray-700 mb-1.5">
                    Choose your centre <span class="text-red-500">*</span>
                    <x-help-tip text="Centres are listed by state. The company centre is pre-selected if you are not sure — you can change this later from your profile (OTP required)." />
                </label>
                <select id="center_id" name="center_id" required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @foreach($byState as $stateName => $stateCenters)
                    <optgroup label="{{ $stateName }}">
                        @foreach($stateCenters as $center)
                        <option value="{{ $center->id }}" @selected($selectedId === $center->id)>
                            {{ $center->name }}@if($center->displayLocation() !== '') — {{ $center->displayLocation() }}@endif
                            @if($center->is_company_default) (company default)@endif
                        </option>
                        @endforeach
                    </optgroup>
                    @endforeach
                </select>
                @if($selected)
                <p class="text-xs text-gray-600 mt-2">
                    Currently selected: <strong>{{ $selected->name }}</strong>@if($selected->displayLocation() !== ''), {{ $selected->displayLocation() }}@endif.
                </p>
                @endif
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
