@extends('admin.layouts.admin')
@section('title', 'Audit Log')
@section('heading', 'Audit Log')

@section('content')

{{-- Filters --}}
<form method="GET" action="{{ route('admin.audit-log') }}" class="flex flex-wrap gap-3 mb-6">
    <input name="action" type="text" value="{{ request()->query('action') }}"
        placeholder="Filter by action…"
        class="rounded-lg bg-white border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 w-48">
    <select name="subject_type"
        class="rounded-lg bg-white border border-gray-200 px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500">
        <option value="">All subjects</option>
        @foreach(['distributor','user','settings','system'] as $t)
        <option value="{{ $t }}" {{ request()->query('subject_type') === $t ? 'selected' : '' }}>{{ $t }}</option>
        @endforeach
    </select>
    <input name="from" type="date" value="{{ request()->query('from') }}"
        class="rounded-lg bg-white border border-gray-200 px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500">
    <input name="to" type="date" value="{{ request()->query('to') }}"
        class="rounded-lg bg-white border border-gray-200 px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500">
    <button type="submit" class="px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors">
        Filter
    </button>
    @if(request()->hasAny(['action','subject_type','from','to']))
    <a href="{{ route('admin.audit-log') }}" class="px-4 py-2 rounded-lg bg-white border border-gray-200 text-sm text-gray-800 hover:text-white transition-colors">
        Clear
    </a>
    @endif
</form>

{{-- Top action groups --}}
@if($actionGroups->count())
<div class="flex flex-wrap gap-2 mb-6">
    @foreach($actionGroups as $grp => $cnt)
    <a href="{{ route('admin.audit-log', ['action' => $grp]) }}"
       class="px-2.5 py-1 rounded-full text-xs border border-gray-200 bg-white text-gray-800 hover:border-brand-500 hover:text-brand-600 transition-colors">
        {{ $grp }} <span class="text-gray-600">({{ $cnt }})</span>
    </a>
    @endforeach
</div>
@endif

{{-- Log table --}}
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50/50">
                    <th class="text-left px-4 py-3 text-xs text-gray-700">Time</th>
                    <th class="text-left px-4 py-3 text-xs text-gray-700">Action</th>
                    <th class="text-left px-4 py-3 text-xs text-gray-700">Subject</th>
                    <th class="text-left px-4 py-3 text-xs text-gray-700">Actor</th>
                    <th class="text-left px-4 py-3 text-xs text-gray-700">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($logs as $log)
                <tr class="hover:bg-white/40 transition-colors">
                    <td class="px-4 py-3 text-xs text-gray-700 whitespace-nowrap">
                        {{ \Carbon\Carbon::parse($log->created_at)->format('d M Y') }}<br>
                        <span class="text-gray-600">{{ \Carbon\Carbon::parse($log->created_at)->format('H:i:s') }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="font-mono text-xs
                            {{ str_contains($log->action, 'rejected') || str_contains($log->action, 'frozen') || str_contains($log->action, 'terminated') ? 'text-red-700'
                             : (str_contains($log->action, 'created') || str_contains($log->action, 'completed') ? 'text-green-700'
                             : (str_contains($log->action, 'changed') || str_contains($log->action, 'exported') ? 'text-amber-700'
                             : 'text-gray-800')) }}">
                            {{ $log->action }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-800">
                        {{ $log->subject_type }}
                        @if($log->subject_id)
                            @if($log->subject_type === 'distributor')
                            <a href="{{ route('admin.distributors.show', $log->subject_id) }}" class="text-brand-600 hover:underline">#{{ $log->subject_id }}</a>
                            @else
                            <span>#{{ $log->subject_id }}</span>
                            @endif
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-700">{{ $log->actor_email ?? 'system' }}</td>
                    <td class="px-4 py-3 text-xs">
                        @if($log->details)
                        <details>
                            <summary class="text-gray-700 cursor-pointer hover:text-gray-900">view</summary>
                            <pre class="mt-1 text-gray-700 bg-white rounded p-2 text-xs overflow-x-auto max-w-xs">{{ json_encode(json_decode($log->details), JSON_PRETTY_PRINT) }}</pre>
                        </details>
                        @else
                        <span class="text-gray-500">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-700">No audit entries found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())
    <div class="px-4 py-4 border-t border-gray-200">
        {{ $logs->links() }}
    </div>
    @endif
</div>

@endsection
