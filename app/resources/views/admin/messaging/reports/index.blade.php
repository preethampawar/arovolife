@extends('admin.layouts.admin')
@section('title', 'Reported messages')
@section('heading', 'Reported messages')

@section('content')
@php
    use App\Modules\Messaging\Models\MessageReport;
    use App\Modules\Shared\Support\IndianNumber;
    $badge = [
        MessageReport::STATUS_OPEN => 'bg-amber-100 text-amber-800',
        MessageReport::STATUS_REVIEWED => 'bg-gray-100 text-gray-700',
        MessageReport::STATUS_ACTIONED => 'bg-red-100 text-red-700',
    ];
    $tabs = [
        MessageReport::STATUS_OPEN => 'Open',
        MessageReport::STATUS_REVIEWED => 'Reviewed',
        MessageReport::STATUS_ACTIONED => 'Actioned',
        'all' => 'All',
    ];
@endphp

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Distributors report messages they received. This is the only route by which anything said in a private message reaches the company — the copy audit scans our own templates, not what one member types to another, so an income claim made in a chat arrives here or nowhere. Opening a report is audit-logged. Deciding one never changes the message: account consequences are applied with the account tools on the distributor's page.
</div>

@if(session('status'))
    <div class="mb-4 rounded-lg border border-leaf-200 bg-leaf-50 px-4 py-3 text-sm text-leaf-800">{{ session('status') }}</div>
@endif

<div class="mb-4 flex flex-wrap gap-2">
    @foreach($tabs as $value => $label)
        <a href="{{ route('admin.messaging.reports.index', ['status' => $value]) }}"
           class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $status === $value ? 'bg-brand-700 text-white' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
            {{ $label }}
            @if($value !== 'all')
                <span class="ml-1 opacity-75">{{ IndianNumber::format($counts[$value] ?? 0) }}</span>
            @endif
        </a>
    @endforeach
</div>

@if($reports->isEmpty())
    <div class="rounded-xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-600">
        Nothing here. No messages are waiting for review.
    </div>
@else
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-600">
                <tr>
                    <th class="px-4 py-3">Reported</th>
                    <th class="px-4 py-3">Why</th>
                    <th class="px-4 py-3">Sender</th>
                    <th class="px-4 py-3">Reported by</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($reports as $report)
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $report->created_at->format('d M Y, h:i A') }}</td>
                        <td class="px-4 py-3">{{ $report->categoryLabel() }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $report->message?->fromUser?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $report->reporter?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$report->status] ?? 'bg-gray-100 text-gray-700' }}">
                                {{ ucfirst($report->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.messaging.reports.show', ['report' => $report->id]) }}"
                               class="text-sm font-medium text-brand-700 hover:text-brand-800">Review</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $reports->links() }}</div>
@endif
@endsection
