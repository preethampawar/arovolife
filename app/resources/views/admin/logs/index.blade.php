@extends('admin.layouts.admin')
@section('title', 'Logs')
@section('heading', 'Logs')

@section('content')

@php
    $channelNotes = [
        'laravel' => 'Application log: errors, exceptions, warnings and engine output.',
        'payments' => 'Razorpay gateway: every API call, browser callback and webhook. Contact details, VPAs, card names and tokens are stripped before writing. Kept for '.config('logging.channels.payments.days').' days.',
    ];
@endphp

<p class="text-sm text-gray-600 mb-5 max-w-3xl">Every log file the app and the server's workers write to <code class="font-mono text-xs">storage/logs</code>, grouped by log, most recently written first. The application log can contain stack traces and request details. Every download is audit-logged.</p>

<div class="space-y-6">
@forelse($groups as $channel => $logs)
    <x-ui.card flush>
        <div class="px-4 py-3 border-b border-gray-200">
            <h2 class="text-base font-semibold text-gray-900">{{ $channel }}</h2>
            @isset($channelNotes[$channel])
                <p class="text-xs text-gray-500 mt-0.5">{{ $channelNotes[$channel] }}</p>
            @endisset
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase w-12">S.No.</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">File</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Day</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase">Size</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Last written</th>
                    <th></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($logs as $log)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500 tabular-nums">{{ $loop->iteration }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $log['file'] }}</td>
                    <td class="px-4 py-3 text-gray-800">{{ $log['date']?->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ \Illuminate\Support\Number::fileSize($log['bytes'], 1) }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $log['modified']->format('d M Y, h:i A') }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.logs.download', $log['file']) }}" class="inline-flex items-center gap-1.5 text-brand-700 font-medium hover:underline">
                            <x-lucide-download class="w-4 h-4" /> Download
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </x-ui.card>
@empty
    <x-ui.card>
        <p class="text-center text-gray-500 py-4">No log files yet.</p>
    </x-ui.card>
@endforelse
</div>

@endsection
