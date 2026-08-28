@extends('layouts.app')
@section('title', 'Help')

@section('content')
<div class="max-w-3xl mx-auto">

    <h1 class="text-2xl font-bold text-gray-900 mb-2">Help</h1>
    <p class="text-sm text-gray-600 mb-8">
        Where to go when something is wrong, and who handles it.
    </p>

    <div class="grid gap-4 sm:grid-cols-2 mb-8">
        <a href="{{ route('my.grievances.create') }}"
           class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm hover:border-brand-300 hover:shadow transition">
            <p class="text-2xl mb-2">📣</p>
            <p class="font-semibold text-gray-900 mb-1">Raise a grievance</p>
            <p class="text-sm text-gray-600">
                A formal complaint with a complaint number, an SLA clock and a published escalation route.
            </p>
        </a>

        <a href="{{ route('my.grievances.index') }}"
           class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm hover:border-brand-300 hover:shadow transition">
            <p class="text-2xl mb-2">📂</p>
            <p class="font-semibold text-gray-900 mb-1">My grievances</p>
            <p class="text-sm text-gray-600">
                @if ($openGrievances > 0)
                    You have {{ $openGrievances }} open {{ \Illuminate\Support\Str::plural('grievance', $openGrievances) }}.
                @else
                    Everything you have raised, and where each one stands.
                @endif
            </p>
        </a>

        <a href="{{ route('contact.show') }}"
           class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm hover:border-brand-300 hover:shadow transition">
            <p class="text-2xl mb-2">✉️</p>
            <p class="font-semibold text-gray-900 mb-1">General enquiry</p>
            <p class="text-sm text-gray-600">
                A question rather than a complaint — no ticket number, no SLA clock.
            </p>
        </a>

        <a href="{{ route('content.show', 'grievance') }}"
           class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm hover:border-brand-300 hover:shadow transition">
            <p class="text-2xl mb-2">📖</p>
            <p class="font-semibold text-gray-900 mb-1">Grievance Redressal Policy</p>
            <p class="text-sm text-gray-600">
                Our SLAs, the four internal escalation steps, and the statutory authorities beyond them.
            </p>
        </a>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6">
        <h2 class="text-sm font-semibold text-gray-900 mb-3">Who handles what</h2>
        <dl class="space-y-2 text-sm">
            @foreach ($officers as $role => $mailbox)
                <div class="flex flex-wrap justify-between gap-2">
                    <dt class="text-gray-600">{{ $role }}</dt>
                    <dd><a href="mailto:{{ $mailbox }}" class="font-medium text-brand-700 underline">{{ $mailbox }}</a></dd>
                </div>
            @endforeach
            <div class="flex flex-wrap justify-between gap-2 pt-2 border-t border-gray-200">
                <dt class="text-gray-600">Helpline (10:00–18:00 IST, Mon–Sat)</dt>
                <dd><a href="tel:+918886662949" class="font-medium text-brand-700 underline">+91 88866 62949</a></dd>
            </div>
        </dl>
        <p class="mt-4 text-xs text-gray-600 leading-relaxed">
            You may approach the National Consumer Helpline (1800-11-4000 / 1915), the Central Consumer
            Protection Authority or a Consumer Disputes Redressal Commission directly. You do not have to
            exhaust our internal steps first.
        </p>
    </div>
</div>
@endsection
