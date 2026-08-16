@extends('layouts.app')
@section('title', 'Raise a grievance')

@section('content')
<div class="max-w-2xl mx-auto">

    <h1 class="text-2xl font-bold text-gray-900 mb-2">Raise a grievance</h1>

    {{-- Form-purpose note. --}}
    <div class="mb-8 rounded-xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm leading-relaxed text-blue-900">
        <p class="font-semibold mb-1">What this form does</p>
        <p>
            It registers a formal complaint and issues a complaint number immediately. We acknowledge within
            <strong>48 hours</strong>, respond substantively within <strong>5 working days</strong>, and
            resolve within <strong>30 days</strong> — up to 60 where a bank, gateway or authority must
            respond, with an update to you at least every 15 days.
        </p>
        <p class="mt-2">
            Your name, email and mobile are attached automatically. For a question rather than a complaint,
            use <a href="{{ route('contact.show') }}" class="underline font-medium">general enquiry</a> instead.
        </p>
        <p class="mt-2">
            Raising something about your upline or a member of staff and would rather not be named? File it
            through the <a href="{{ route('grievance.create') }}" class="underline font-medium">anonymous public form</a>
            instead. We will investigate as far as the evidence allows, but we will have no way to tell you
            the outcome.
        </p>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-5 py-4">
            <ul class="list-disc list-inside space-y-1 text-sm text-red-700">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('my.grievances.store') }}" enctype="multipart/form-data"
          class="space-y-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm"
          data-confirm="Submit this grievance?"
          data-confirm-title="Register a formal complaint"
          data-confirm-impact="A complaint number will be issued and the grievance team notified. You can track it from My grievances.">
        @csrf

        <div>
            <label for="category" class="block text-sm font-medium text-gray-700 mb-1.5">
                What is this about?
                <x-help-tip text="Pick the closest match. The category decides which officer picks it up first." />
            </label>
            <select id="category" name="category" required
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">Choose a category</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->value }}" @selected(old('category') === $category->value)>
                        {{ $category->label() }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="subject" class="block text-sm font-medium text-gray-700 mb-1.5">
                Summary
                <x-help-tip text="One line, e.g. 'GSB credit missing for the week of 4 August'." />
            </label>
            <input id="subject" name="subject" type="text" maxlength="255" required value="{{ old('subject') }}"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>

        <div>
            <label for="body" class="block text-sm font-medium text-gray-700 mb-1.5">
                What happened?
                <x-help-tip text="Dates, ADNs, order numbers and amounts help us resolve faster. Never include passwords, OTPs or your full Aadhaar number." />
            </label>
            <textarea id="body" name="body" rows="7" maxlength="5000" required
                      class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('body') }}</textarea>
        </div>

        <div>
            <label for="attachments" class="block text-sm font-medium text-gray-700 mb-1.5">
                Evidence <span class="font-normal text-gray-500">(optional)</span>
            </label>
            <input id="attachments" name="attachments[]" type="file" multiple
                   accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700">
            <p class="mt-1.5 text-xs text-gray-500">
                Up to {{ $maxAttachments }} files, {{ $maxKilobytes / 1024 }} MB each.
            </p>
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-brand-500 px-5 py-3 text-sm font-semibold text-white hover:bg-brand-600">
            Submit grievance
        </button>
    </form>
</div>

<x-confirm-modal />
@endsection
