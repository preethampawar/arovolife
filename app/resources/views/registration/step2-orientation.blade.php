@extends('layouts.wizard')
@section('title', 'Step 1 — Orientation')
@php $currentStep = 3; @endphp

@section('content')
<div class="max-w-2xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Mandatory Orientation</h2>
    <p class="text-gray-600 text-sm mb-6">
        Orientation is required by the Consumer Protection (Direct Selling) Rules, 2021.
        The knowledge check below must be passed before you can proceed.
    </p>

    {{-- Video --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h3 class="font-semibold text-gray-800 mb-3">arovolife Direct Selling — Orientation Video</h3>
        {{-- No video has been produced yet. The screen must not imply one is
             playing: the box below used to render a ▶ and "~8 minutes", and
             the applicant was then asked to confirm they had watched it. See
             C-03 / R-50 — the video and real playback tracking are a launch
             blocker, and this placeholder says so out loud until they ship. --}}
        <div class="relative w-full aspect-video bg-amber-50 rounded-lg flex items-center justify-center border border-amber-300 mb-4" id="video-placeholder">
            <div class="text-center px-6">
                <p class="text-sm font-semibold text-amber-800">The orientation video is not available yet.</p>
                <p class="text-xs text-amber-700 mt-2 leading-relaxed">
                    It is being produced. Until it is published you can still complete the knowledge
                    check below, and we record that you have not yet watched a video — we do not
                    record that you have.
                </p>
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
                    <span class="text-sm font-medium text-gray-800">I confirm I have read and understood the orientation material <span class="text-red-700">*</span></span>
                    <p class="text-xs text-gray-500 mt-0.5">We record this as your own declaration. It is not a record that a video was watched.</p>
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

        @include('registration._draft_notice')

        <button type="submit"
            class="w-full rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
            Continue to Consent →
        </button>
    </form>
</div>
@endsection
