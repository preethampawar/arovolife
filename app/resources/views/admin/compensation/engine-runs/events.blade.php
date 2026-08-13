@extends('admin.layouts.admin')
@section('title', 'Engine Run Events')
@section('heading', 'Compensation — Engine Run Events')

@section('content')

@php use App\Modules\Compensation\Models\EngineRun; @endphp

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-gray-600">
        Every recorded engine run — scheduled, manual, and dependency runs pulled in by a manual trigger.
        <x-help-tip text="Rows are written the moment an engine starts and finalised when it finishes. Rows sharing a chain ID belong to one manual trigger and the prerequisites it ran first." />
    </p>
    <div class="flex items-center gap-2">
        <form method="GET" action="{{ route('admin.compensation.engine-runs.events') }}" class="flex items-center gap-2">
            <label class="text-xs font-medium text-gray-700">Engine</label>
            <select name="engine" onchange="this.form.submit()"
                    class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs focus:ring-2 focus:ring-brand-400 focus:outline-none">
                <option value="">All engines</option>
                @foreach($filterOptions as $key => $definition)
                <option value="{{ $key }}" @selected($engineKey === $key)>{{ $definition->label }}</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('admin.compensation.engine-runs.index') }}" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">← Engine Runs</a>
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-x-auto">
    @if($runs->isEmpty())
    <p class="px-5 py-8 text-sm text-gray-400 text-center">No engine runs recorded yet. Runs appear here from the moment an engine starts.</p>
    @else
    <table class="min-w-full text-xs">
        <thead>
            <tr class="border-b border-gray-100 text-left text-[10px] font-semibold uppercase tracking-wider text-gray-400">
                <th class="px-4 py-3">Engine</th>
                <th class="px-4 py-3">Period</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Trigger</th>
                <th class="px-4 py-3">By</th>
                <th class="px-4 py-3">Started</th>
                <th class="px-4 py-3">Duration</th>
                <th class="px-4 py-3">Chain</th>
                <th class="px-4 py-3">Details</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($runs as $run)
            @php
                $definition = $definitions[$run->engine_key] ?? null;
                $pill = match(true) {
                    $run->isStale() => ['bg-amber-100 text-amber-700', 'stale'],
                    $run->status === EngineRun::STATUS_RUNNING => ['bg-blue-100 text-blue-700', 'running'],
                    $run->status === EngineRun::STATUS_SUCCEEDED => ['bg-green-100 text-green-700', 'succeeded'],
                    $run->status === EngineRun::STATUS_FAILED => ['bg-red-100 text-red-700', 'failed'],
                    default => ['bg-gray-100 text-gray-600', $run->status],
                };
                $duration = $run->durationSeconds();
            @endphp
            <tr class="text-gray-600 align-top">
                <td class="px-4 py-3 font-medium text-gray-900 whitespace-nowrap">{{ $definition?->label ?? $run->engine_key }}</td>
                <td class="px-4 py-3 whitespace-nowrap">{{ $definition?->displayPeriod($run->period_start) ?? $run->period_start->toDateString() }}</td>
                <td class="px-4 py-3"><span class="inline-flex px-2 py-0.5 rounded font-medium {{ $pill[0] }}">{{ $pill[1] }}</span></td>
                <td class="px-4 py-3">{{ $run->trigger }}</td>
                <td class="px-4 py-3 whitespace-nowrap">{{ $run->actor?->email ?? 'scheduler / CLI' }}</td>
                <td class="px-4 py-3 whitespace-nowrap">{{ $run->started_at->format('d M Y H:i:s') }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    @if($duration === null) — @elseif($duration < 60) {{ $duration }}s @else {{ intdiv($duration, 60) }}m {{ $duration % 60 }}s @endif
                </td>
                <td class="px-4 py-3 font-mono text-[10px] text-gray-400">{{ $run->chain_id ? substr($run->chain_id, 0, 8) : '—' }}</td>
                <td class="px-4 py-3 max-w-xs">
                    @if(! empty($run->summary))
                    <details>
                        <summary class="cursor-pointer text-indigo-600 hover:text-indigo-800 select-none">summary</summary>
                        <pre class="mt-1 whitespace-pre-wrap break-words rounded bg-gray-50 p-2 text-[10px] text-gray-600">{{ json_encode($run->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                    @endif
                    @if($run->error !== null)
                    <details>
                        <summary class="cursor-pointer text-red-600 hover:text-red-800 select-none">error</summary>
                        <pre class="mt-1 whitespace-pre-wrap break-words rounded bg-red-50 p-2 text-[10px] text-red-700">{{ Str::limit($run->error, 1000) }}</pre>
                    </details>
                    @endif
                    @if(empty($run->summary) && $run->error === null) — @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

<div class="mt-4">
    {{ $runs->links() }}
</div>

@endsection
