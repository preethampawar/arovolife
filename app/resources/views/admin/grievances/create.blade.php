@extends('admin.layouts.admin')
@section('title', 'Record a complaint')
@section('heading', 'Record a complaint')

@section('content')
<a href="{{ route('admin.grievances.index') }}" class="text-sm text-brand-700 underline hover:text-brand-800">← Grievance queue</a>

{{-- Form-purpose note. --}}
<div class="mt-4 mb-6 max-w-2xl rounded-xl border border-gray-200 bg-white shadow-sm px-5 py-4 text-sm leading-relaxed text-gray-700">
    <p class="mb-1 font-semibold text-gray-900">What this form does</p>
    <p>
        Records a complaint that arrived by phone, post, email or in person, so it lands in the same tracker
        as web submissions. The published policy §3 promises complainants that every channel routes here —
        this form is how that promise is kept for the channels a complainant cannot self-serve.
    </p>
    <p class="mt-2">
        <strong>Enter the date it reached us, not today's date.</strong> Every SLA in the published policy is
        measured from receipt, so a letter that arrived last Tuesday is already five days into its clock.
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

<form method="POST" action="{{ route('admin.grievances.store') }}" enctype="multipart/form-data"
      class="max-w-2xl space-y-5 rounded-xl border border-gray-200 bg-white shadow-sm p-6"
      data-confirm="Record this complaint?"
      data-confirm-title="Register a complaint on the complainant's behalf"
      data-confirm-impact="A complaint number is issued, SLA clocks start, and the owning officer is notified.">
    @csrf

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="channel" class="block text-sm font-medium text-gray-700 mb-1.5">
                How did it arrive?
                <x-help-tip text="The channel is recorded in the DSR register and in the monthly compliance report." />
            </label>
            <select id="channel" name="channel" required class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
                @foreach ($channels as $channelOption)
                    <option value="{{ $channelOption->value }}" @selected(old('channel') === $channelOption->value)>
                        {{ $channelOption->label() }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="received_at" class="block text-sm font-medium text-gray-700 mb-1.5">
                Date received
                <x-help-tip text="The date the complaint reached arovolife — the postmark, the call, the walk-in. NOT the date you are keying it in. All SLA clocks run from this date." />
            </label>
            <input id="received_at" name="received_at" type="date" required
                   value="{{ old('received_at', now()->toDateString()) }}"
                   max="{{ now()->toDateString() }}"
                   class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
        </div>

        <div>
            <label for="category" class="block text-sm font-medium text-gray-700 mb-1.5">Category</label>
            <select id="category" name="category" required class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
                <option value="">Choose a category</option>
                @foreach ($categories as $categoryOption)
                    <option value="{{ $categoryOption->value }}" @selected(old('category') === $categoryOption->value)>
                        {{ $categoryOption->label() }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label for="subject" class="block text-sm font-medium text-gray-700 mb-1.5">Summary</label>
        <input id="subject" name="subject" type="text" maxlength="255" required value="{{ old('subject') }}"
               class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
    </div>

    <div>
        <label for="body" class="block text-sm font-medium text-gray-700 mb-1.5">
            What the complainant said
            <x-help-tip text="Record it as close to their own words as you can. Do not write down PAN, Aadhaar numbers, passwords or OTPs." />
        </label>
        <textarea id="body" name="body" rows="7" maxlength="5000" required
                  class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">{{ old('body') }}</textarea>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="severity" class="block text-sm font-medium text-gray-700 mb-1.5">Severity</label>
            <select id="severity" name="severity" required class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
                @foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('severity', 'medium') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="adn" class="block text-sm font-medium text-gray-700 mb-1.5">
                ADN <span class="font-normal text-gray-500">(if a distributor)</span>
            </label>
            <input id="adn" name="adn" type="text" maxlength="16" value="{{ old('adn') }}"
                   class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 font-mono text-sm text-gray-900">
        </div>
    </div>

    <div class="rounded-lg bg-gray-50 px-4 py-3">
        <label class="flex items-start gap-2.5 text-sm text-gray-700">
            <input type="checkbox" name="is_anonymous" value="1" @checked(old('is_anonymous'))
                   class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
            <span>
                <span class="font-medium">The complainant asked to stay anonymous.</span>
                Contact details will be discarded and no outcome can be reported back to them.
            </span>
        </label>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div>
            <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">Name</label>
            <input id="name" name="name" type="text" maxlength="120" value="{{ old('name') }}"
                   class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
        </div>
        <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
            <input id="email" name="email" type="email" maxlength="255" value="{{ old('email') }}"
                   class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
        </div>
        <div>
            <label for="phone" class="block text-sm font-medium text-gray-700 mb-1.5">Phone</label>
            <input id="phone" name="phone" type="text" maxlength="20" value="{{ old('phone') }}"
                   class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
        </div>
    </div>

    <div>
        <label for="attachments" class="block text-sm font-medium text-gray-700 mb-1.5">
            Scan or evidence <span class="font-normal text-gray-500">(optional)</span>
            <x-help-tip text="Attach the scanned letter for a postal complaint — policy §3.4 promises postal complaints are scanned and time-stamped at receipt." />
        </label>
        <input id="attachments" name="attachments[]" type="file" multiple
               accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"
               class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:ring-brand-500">
    </div>

    <button type="submit" class="rounded-lg bg-brand-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-800">
        Record complaint
    </button>
</form>

<x-confirm-modal />
@endsection
