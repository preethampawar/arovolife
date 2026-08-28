@extends('layouts.app')
@section('title', 'Apply for a Franchise')

@section('content')

<div class="max-w-xl mx-auto py-10">
    <h1 class="text-2xl font-bold mb-2">Apply for a Franchise</h1>

    {{-- Form-purpose note (platform convention). --}}
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold mb-1">What this form does</p>
        <p class="leading-relaxed">
            This submits a franchise application to arovolife. An admin will review your
            application and contact you. Submitting this form does <strong>not</strong>
            guarantee approval. There is no charge to apply.
        </p>
    </div>

    @if($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-700">
        @foreach($errors->all() as $error)
        <p>{{ $error }}</p>
        @endforeach
    </div>
    @endif

    @if(session('success'))
    <div class="rounded-xl border border-green-200 bg-green-50 p-4 mb-6 text-sm text-green-800">
        {{ session('success') }}
    </div>
    @endif

    <form method="POST" action="{{ route('franchise.apply.submit') }}" class="space-y-5"
        data-confirm="Submit your franchise application?"
        data-confirm-title="Confirm franchise application"
        data-confirm-impact="Your application will be reviewed by an admin. There is no charge to apply.">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Address Line
                <x-help-tip text="Street address of the proposed franchise outlet location." />
            </label>
            <input type="text" name="address_line" value="{{ old('address_line') }}"
                maxlength="255" required
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500"
                placeholder="Building / street / area">
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    Pincode
                    <x-help-tip text="6-digit PIN code of the proposed location." />
                </label>
                <input type="text" name="pincode" value="{{ old('pincode') }}"
                    pattern="\d{6}" maxlength="6" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:border-brand-500 focus:ring-brand-500"
                    placeholder="500001">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    District
                </label>
                <input type="text" name="district" value="{{ old('district') }}"
                    maxlength="100" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500"
                    placeholder="Hyderabad">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                State
            </label>
            <input type="text" name="state" value="{{ old('state') }}"
                maxlength="100" required
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500"
                placeholder="Telangana">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Preferred ADC Centre
                <x-help-tip text="Optional — if you have a preferred arovolife Development Centre you would like to be associated with, select it here. You can leave this blank if you are not sure." />
            </label>
            <select name="arete_center_id"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">None / Not sure</option>
                @foreach($areteCentres as $centre)
                <option value="{{ $centre->id }}" {{ old('arete_center_id') == $centre->id ? 'selected' : '' }}>
                    {{ $centre->name }}
                    @if($centre->district || $centre->state)
                        — {{ $centre->district }}{{ $centre->district && $centre->state ? ', ' : '' }}{{ $centre->state }}
                    @endif
                </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Motivation / Notes
                <x-help-tip text="Optional — tell us why you want to open a franchise and any other details relevant to your application. Maximum 1,000 characters." />
            </label>
            <textarea name="notes" rows="4" maxlength="1000"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500"
                placeholder="Briefly describe your motivation and any relevant experience.">{{ old('notes') }}</textarea>
        </div>

        <button type="submit"
            class="w-full inline-flex justify-center items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-6 py-3 text-sm transition-colors">
            Submit Application
        </button>
    </form>
</div>

@endsection
