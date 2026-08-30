@extends('layouts.app')
@section('title', 'Request '.$item->request_no)

@section('content')
@php
    $badge = [
        'submitted' => 'bg-amber-100 text-amber-800', 'under_review' => 'bg-blue-100 text-blue-800',
        'approved' => 'bg-green-100 text-green-700', 'rejected' => 'bg-red-100 text-red-700',
    ];
    $ist = fn ($t) => $t?->timezone('Asia/Kolkata')->format('d M Y, H:i') ?? '—';
@endphp
<div class="max-w-3xl mx-auto">
    <div class="flex items-center gap-3 mb-2">
        <a href="{{ route('my.requests.index') }}" class="text-sm text-gray-600 hover:text-gray-700">← My requests</a>
        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badge[$item->status] ?? 'bg-gray-100 text-gray-600' }}">{{ $item->statusLabel() }}</span>
    </div>
    <h1 class="text-2xl font-bold mb-6">{{ $item->typeLabel() }} <span class="font-mono text-base text-gray-500">{{ $item->request_no }}</span></h1>

    <section class="rounded-2xl border border-gray-200 bg-white p-6 mb-6">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div><dt class="text-xs uppercase tracking-wider text-gray-500">Requested</dt><dd class="text-gray-900">{{ $item->requestedSummary() }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wider text-gray-500">Filed</dt><dd class="text-gray-900">{{ $ist($item->submitted_at) }}</dd></div>
            @if($item->reviewed_at)
            <div><dt class="text-xs uppercase tracking-wider text-gray-500">Last reviewed</dt><dd class="text-gray-900">{{ $ist($item->reviewed_at) }}</dd></div>
            @endif
            @if($item->applied_at)
            <div><dt class="text-xs uppercase tracking-wider text-gray-500">Record updated</dt><dd class="text-gray-900">{{ $ist($item->applied_at) }}</dd></div>
            @endif
            <div class="sm:col-span-2"><dt class="text-xs uppercase tracking-wider text-gray-500">Your reason</dt><dd class="text-gray-900 whitespace-pre-line">{{ $item->reason }}</dd></div>
            @if($item->admin_notes)
            <div class="sm:col-span-2"><dt class="text-xs uppercase tracking-wider text-gray-500">Note from arovolife</dt><dd class="text-gray-900 whitespace-pre-line">{{ $item->admin_notes }}</dd></div>
            @endif
        </dl>
    </section>

    @if($item->documents->isNotEmpty())
    <section class="rounded-2xl border border-gray-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-gray-900 mb-2">Documents you uploaded</h2>
        <ul class="text-sm text-gray-700 space-y-1">
            @foreach($item->documents as $doc)
            <li>{{ $doc->typeLabel() }} — <span class="text-gray-500">{{ $doc->original_name }}</span></li>
            @endforeach
        </ul>
    </section>
    @endif
</div>
@endsection
