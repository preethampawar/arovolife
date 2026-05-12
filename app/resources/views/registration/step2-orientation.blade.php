@extends('layouts.wizard')
@section('title', 'Step 1 — Orientation')
@php $currentStep = 3; @endphp

@section('content')
<div class="max-w-2xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Mandatory Orientation</h2>
    <p class="text-gray-600 text-sm mb-6">
        You must watch the full orientation video and pass the quiz before proceeding.
        This is required by the Consumer Protection (Direct Selling) Rules, 2021.
    </p>

    {{-- Video --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h3 class="font-semibold text-gray-800 mb-3">arovolife Direct Selling — Orientation Video</h3>
        <div class="relative w-full aspect-video bg-white rounded-lg flex items-center justify-center border border-gray-200 mb-4" id="video-placeholder">
            <div class="text-center">
                <div class="text-4xl mb-2">▶</div>
                <p class="text-sm text-gray-600">Orientation Video (Phase 1 placeholder)</p>
                <p class="text-xs text-gray-400 mt-1">Duration: ~8 minutes</p>
            </div>
        </div>
    </div>

    {{-- Quiz --}}
    <form method="POST" action="{{ url('/register/orientation') }}" id="orientation-form" class="space-y-6">
        @csrf

        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="confirmed_watched" value="1" required
                    class="mt-0.5 rounded text-brand-600 border-gray-300 bg-gray-100 focus:ring-brand-500">
                <div>
                    <span class="text-sm font-medium text-gray-800">I confirm I have watched the full orientation video <span class="text-red-700">*</span></span>
                    <p class="text-xs text-gray-500 mt-0.5">Check this only after watching the complete video above</p>
                </div>
            </label>
            @error('confirmed_watched')<p class="mt-2 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <h3 class="font-semibold text-gray-800 mb-5">Knowledge Check — 3 Questions</h3>

            <div class="space-y-6">
                {{-- Q1 --}}
                <div>
                    <p class="text-sm font-medium text-gray-800 mb-3">1. What does ADN stand for?</p>
                    <div class="space-y-2">
                        @foreach(['A' => 'arovolife Distributor Number', 'B' => 'Annual Distribution Network', 'C' => 'Agent Development Number'] as $val => $label)
                        <label class="flex items-center gap-3 p-3 rounded-lg bg-white border border-gray-200 cursor-pointer hover:border-brand-500 transition-colors">
                            <input type="radio" name="quiz_q1" value="{{ $val }}" class="text-brand-600 border-gray-300 bg-gray-100 focus:ring-brand-500">
                            <span class="text-sm text-gray-700">{{ $label }}</span>
                        </label>
                        @endforeach
                    </div>
                    @error('quiz_q1')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>

                {{-- Q2 --}}
                <div>
                    <p class="text-sm font-medium text-gray-800 mb-3">2. What is the cooling-off period for a new Direct Seller?</p>
                    <div class="space-y-2">
                        @foreach(['A' => '7 days', 'B' => '30 days', 'C' => '60 days'] as $val => $label)
                        <label class="flex items-center gap-3 p-3 rounded-lg bg-white border border-gray-200 cursor-pointer hover:border-brand-500 transition-colors">
                            <input type="radio" name="quiz_q2" value="{{ $val }}" class="text-brand-600 border-gray-300 bg-gray-100 focus:ring-brand-500">
                            <span class="text-sm text-gray-700">{{ $label }}</span>
                        </label>
                        @endforeach
                    </div>
                    @error('quiz_q2')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>

                {{-- Q3 --}}
                <div>
                    <p class="text-sm font-medium text-gray-800 mb-3">3. Under arovolife's plan, income is earned through:</p>
                    <div class="space-y-2">
                        @foreach(['A' => 'Recruiting new members only', 'B' => 'Membership fees', 'C' => 'Retail product sales to end consumers'] as $val => $label)
                        <label class="flex items-center gap-3 p-3 rounded-lg bg-white border border-gray-200 cursor-pointer hover:border-brand-500 transition-colors">
                            <input type="radio" name="quiz_q3" value="{{ $val }}" class="text-brand-600 border-gray-300 bg-gray-100 focus:ring-brand-500">
                            <span class="text-sm text-gray-700">{{ $label }}</span>
                        </label>
                        @endforeach
                    </div>
                    @error('quiz_q3')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <button type="submit"
            class="w-full rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
            Continue to Consent →
        </button>
    </form>
</div>
@endsection
