@extends('layouts.wizard')
@section('title', 'Step 6 — Demographics')
@php $currentStep = 6; @endphp

@section('content')
<div class="max-w-2xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Demographics</h2>
    <p class="text-gray-600 text-sm mb-6">
        This information personalises your experience and is not shared publicly.
    </p>

    <form method="POST" action="{{ route('register.demographics') }}" class="space-y-6 bg-white rounded-2xl border border-gray-200 p-8">
        @csrf

        {{-- Gender --}}
        <div>
            <p class="block text-sm font-medium text-gray-700 mb-3">Gender <span class="text-red-700">*</span></p>
            <div class="grid grid-cols-2 gap-3">
                @foreach([
                    'male'               => 'Male',
                    'female'             => 'Female',
                    'transgender_other'  => 'Transgender or Other',
                    'prefer_not_to_say'  => 'Prefer not to say',
                ] as $value => $label)
                <label class="flex items-center gap-3 cursor-pointer rounded-lg border px-4 py-3 transition-colors
                    {{ old('gender', $data['gender'] ?? '') === $value ? 'border-brand-500 bg-brand-50' : 'border-gray-200 bg-white hover:bg-gray-50' }}">
                    <input type="radio" name="gender" value="{{ $value }}" required
                        {{ old('gender', $data['gender'] ?? '') === $value ? 'checked' : '' }}
                        class="text-brand-700 border-gray-300 focus:ring-brand-500">
                    <span class="text-sm text-gray-700">{{ $label }}</span>
                </label>
                @endforeach
            </div>
            @error('gender')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- Marital Status --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Marital Status <span class="text-red-700">*</span>
            </label>
            <select name="marital_status" required
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                <option value="" disabled {{ old('marital_status', $data['marital_status'] ?? '') === '' ? 'selected' : '' }}>Select your marital status</option>
                @foreach([
                    'single'             => 'Single',
                    'married'            => 'Married',
                    'divorced'           => 'Divorced',
                    'widowed'            => 'Widowed',
                    'prefer_not_to_say'  => 'Prefer not to say',
                ] as $value => $label)
                <option value="{{ $value }}" {{ old('marital_status', $data['marital_status'] ?? '') === $value ? 'selected' : '' }}>
                    {{ $label }}
                </option>
                @endforeach
            </select>
            @error('marital_status')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- Highest Education --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Highest Education <span class="text-red-700">*</span>
            </label>
            <select name="highest_education" required
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                <option value="" disabled {{ old('highest_education', $data['highest_education'] ?? '') === '' ? 'selected' : '' }}>Select your highest qualification</option>
                @foreach([
                    'below_10th'        => 'Below 10th',
                    '10th_pass'         => '10th Pass',
                    '12th_pass'         => '12th Pass',
                    'diploma'           => 'Diploma',
                    'graduate'          => 'Graduate',
                    'post_graduate'     => 'Post Graduate',
                    'doctorate'         => 'Doctorate',
                    'prefer_not_to_say' => 'Prefer not to say',
                ] as $value => $label)
                <option value="{{ $value }}" {{ old('highest_education', $data['highest_education'] ?? '') === $value ? 'selected' : '' }}>
                    {{ $label }}
                </option>
                @endforeach
            </select>
            @error('highest_education')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- Occupation --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Occupation <span class="text-xs text-gray-600 font-normal">(optional)</span>
            </label>
            <input name="occupation" type="text"
                value="{{ old('occupation', $data['occupation'] ?? '') }}"
                placeholder="e.g. Teacher, Farmer, Business Owner"
                maxlength="191"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            @error('occupation')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- Languages --}}
        <div class="space-y-4">
            <h3 class="text-base font-semibold text-gray-800">Languages</h3>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    Mother Tongue <span class="text-red-700">*</span>
                </label>
                <input name="mother_tongue" type="text" required
                    value="{{ old('mother_tongue', $data['mother_tongue'] ?? '') }}"
                    placeholder="e.g. Telugu, Hindi, Tamil"
                    maxlength="100"
                    class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                @error('mother_tongue')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    Additional Language <span class="text-xs text-gray-600 font-normal">(optional)</span>
                </label>
                <input name="additional_language_1" type="text"
                    value="{{ old('additional_language_1', $data['additional_language_1'] ?? '') }}"
                    placeholder="e.g. English"
                    maxlength="100"
                    class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                @error('additional_language_1')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    Additional Language 2 <span class="text-xs text-gray-600 font-normal">(optional)</span>
                </label>
                <input name="additional_language_2" type="text"
                    value="{{ old('additional_language_2', $data['additional_language_2'] ?? '') }}"
                    placeholder="e.g. Kannada"
                    maxlength="100"
                    class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                @error('additional_language_2')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>
        </div>

        @include('registration._draft_notice')

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('register.identity-documents') }}"
               class="inline-flex items-center px-5 py-3 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                &larr; Back
            </a>
            <button type="submit"
                class="flex-1 rounded-lg bg-brand-700 hover:bg-brand-800 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Save &amp; Continue &rarr;
            </button>
        </div>
    </form>
</div>
@endsection
