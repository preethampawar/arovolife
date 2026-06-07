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

{{-- Log table — Action/Subject/Actor collapsed into one friendly
     "Activity" column rendered via AuditLogPresenter. Technical
     event key + raw subject row are still discoverable inside the
     Details popover so engineers can still grep them. --}}
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50/50">
                    <th class="text-left px-4 py-3 text-xs text-gray-700 uppercase tracking-wider w-12">S.No</th>
                    <th class="text-left px-4 py-3 text-xs text-gray-700 uppercase tracking-wider w-36">Time</th>
                    <th class="text-left px-4 py-3 text-xs text-gray-700 uppercase tracking-wider">Activity</th>
                    <th class="text-left px-4 py-3 text-xs text-gray-700 uppercase tracking-wider w-24">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($logs as $log)
                <tr class="hover:bg-gray-50/50 transition-colors align-top">
                    <td class="px-4 py-4 text-gray-500">{{ $loop->iteration }}</td>
                    <td class="px-4 py-4 text-xs text-gray-700 whitespace-nowrap">
                        <p class="text-gray-800 font-medium">{{ \Carbon\Carbon::parse($log->created_at)->format('d M Y') }}</p>
                        <p class="text-gray-600">{{ \Carbon\Carbon::parse($log->created_at)->format('H:i:s') }}</p>
                    </td>
                    <td class="px-4 py-4">
                        <p class="text-sm text-gray-900 leading-snug">{{ $log->display_title }}</p>
                        @if($log->display_subtitle)
                            <p class="text-xs text-gray-600 mt-1">{{ $log->display_subtitle }}</p>
                        @endif
                        {{-- Quick-jump link to the distributor record when
                             the subject points at one. Non-distributor
                             subjects show only the friendly title. --}}
                        @if($log->subject_type === 'distributor' && $log->subject_id)
                            <a href="{{ route('admin.distributors.show', $log->subject_id) }}"
                               class="text-xs text-brand-600 hover:underline mt-1 inline-block">
                                Open distributor →
                            </a>
                        @endif
                    </td>
                    <td class="px-4 py-4 text-xs">
                        <details class="group">
                            <summary class="cursor-pointer text-gray-600 hover:text-gray-900 select-none">
                                <span class="group-open:hidden">Show</span>
                                <span class="hidden group-open:inline">Hide</span>
                            </summary>
                            <div class="mt-2 space-y-2 max-w-xs">
                                <div class="text-gray-700">
                                    <span class="font-semibold text-gray-500">Event key:</span>
                                    <code class="font-mono text-[11px] text-gray-800 break-all">{{ $log->action }}</code>
                                </div>
                                <div class="text-gray-700">
                                    <span class="font-semibold text-gray-500">Subject:</span>
                                    <code class="font-mono text-[11px] text-gray-800">{{ $log->subject_type }}{{ $log->subject_id ? '#'.$log->subject_id : '' }}</code>
                                </div>
                                <div class="text-gray-700">
                                    <span class="font-semibold text-gray-500">Actor:</span>
                                    <code class="font-mono text-[11px] text-gray-800">{{ $log->actor_email ?? 'system' }}</code>
                                </div>
                                @if($log->details)
                                    <div class="text-gray-700">
                                        <span class="font-semibold text-gray-500 block mb-1">Payload:</span>
                                        <pre class="text-gray-800 bg-gray-50 rounded p-2 text-[11px] overflow-x-auto">{{ json_encode(json_decode($log->details), JSON_PRETTY_PRINT) }}</pre>
                                    </div>
                                @endif
                            </div>
                        </details>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-700">No audit entries found.</td>
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
