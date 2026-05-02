@extends('admin.layouts.admin')
@section('title', 'Inquiry #'.$inquiry->id)
@section('heading', 'Inquiry #'.$inquiry->id)

@section('content')

<div class="mb-4">
    <a href="{{ route('admin.contact-inquiries.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Back to inbox</a>
</div>

@if(session('status'))
<div class="mb-6 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Message body (main column) --}}
    <div class="lg:col-span-2 space-y-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-6">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-bold text-gray-900">{{ $inquiry->name }}</h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Submitted {{ $inquiry->created_at->format('d M Y, H:i') }}
                        ({{ $inquiry->created_at->diffForHumans() }})
                    </p>
                </div>
                @if($inquiry->handled_at)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-leaf-50 text-leaf-700 text-xs font-semibold border border-leaf-200">
                        <span class="w-2 h-2 rounded-full bg-leaf-500"></span>Handled
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-sunrise-50 text-sunrise-700 text-xs font-semibold border border-sunrise-200">
                        <span class="w-2 h-2 rounded-full bg-sunrise-500"></span>Unhandled
                    </span>
                @endif
            </div>

            <p class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1.5">Message</p>
            <div class="rounded-lg bg-gray-50 border border-gray-200 p-4 text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{{ $inquiry->message }}</div>
        </div>

        {{-- Action panel --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-6">
            @if($inquiry->handled_at)
                <p class="text-sm text-gray-700 mb-3">
                    Marked handled on
                    <strong>{{ $inquiry->handled_at->format('d M Y, H:i') }}</strong>
                    @if($inquiry->handled_by)
                        by user&nbsp;<code class="bg-gray-100 px-1 rounded text-[11px]">#{{ $inquiry->handled_by }}</code>
                    @endif.
                </p>
                <form method="POST" action="{{ route('admin.contact-inquiries.unhandle', $inquiry->id) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-lg bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 font-medium px-4 py-2 text-sm transition-colors">
                        Reopen inquiry
                    </button>
                </form>
            @else
                <p class="text-sm text-gray-700 mb-3">
                    Mark this inquiry as handled once you've replied or escalated it.
                </p>
                <form method="POST" action="{{ route('admin.contact-inquiries.handle', $inquiry->id) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-leaf-500 hover:bg-leaf-600 text-white font-semibold px-4 py-2 text-sm transition-colors">
                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                        </svg>
                        Mark as handled
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- Sidebar (contact details + meta) --}}
    <div class="space-y-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <p class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-2">Contact details</p>
            <dl class="space-y-2 text-sm">
                <div>
                    <dt class="text-xs text-gray-500">Email</dt>
                    <dd class="text-gray-800">
                        <a href="mailto:{{ $inquiry->email }}" class="hover:text-brand-600 underline-offset-2 hover:underline">{{ $inquiry->email }}</a>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Phone</dt>
                    <dd class="text-gray-800 font-mono">{{ $inquiry->phone_e164 }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Address</dt>
                    <dd class="text-gray-800">{{ $inquiry->address }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Purpose</dt>
                    <dd class="text-gray-800">{{ str_replace('_', ' ', $inquiry->purpose) }}</dd>
                </div>
                @if($inquiry->reason)
                <div>
                    <dt class="text-xs text-gray-500">Reason</dt>
                    <dd class="text-gray-800">{{ $inquiry->reason }}</dd>
                </div>
                @endif
            </dl>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <p class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-2">Submission metadata</p>
            <dl class="space-y-2 text-xs">
                <div>
                    <dt class="text-gray-500">IP</dt>
                    <dd class="text-gray-800 font-mono">{{ $inquiry->ip }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">User agent</dt>
                    <dd class="text-gray-800 break-words leading-snug">{{ $inquiry->user_agent ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Privacy consent</dt>
                    <dd class="text-gray-800">
                        @if($inquiry->privacy_consent_at)
                            <span class="text-leaf-700 font-semibold">✓ Recorded</span> {{ $inquiry->privacy_consent_at->format('d M Y H:i') }}
                        @else
                            <span class="text-red-700 font-semibold">⚠ Missing</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</div>

@endsection
