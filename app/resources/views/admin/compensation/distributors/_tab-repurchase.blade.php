@php
    $bv = fn ($paise) => \Illuminate\Support\Number::format(($paise ?? 0) / 100, 0).' BV';
    $statusClass = [
        'completed' => 'bg-green-100 text-green-700',
        'active' => 'bg-blue-100 text-blue-700',
        'grace' => 'bg-amber-100 text-amber-700',
        'suspended' => 'bg-red-100 text-red-700',
    ];
    $current = (! empty($rows) && ! $rows->isEmpty()) ? $rows->first() : null;
@endphp

<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Monthly repurchase obligation, anchored to the day the distributor first reached 600 personal BV.
    Missing it holds income during the grace window, then suspends GSB / Fortune / Growth Booster
    (never Mentorship or Rank). Maintained by the daily <code class="font-mono">repurchase:evaluate</code>
    command; only active when the Repurchase engine feature flag is on.
</div>

@if($current)
<div class="mb-4 grid grid-cols-2 md:grid-cols-4 gap-3">
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-[11px] uppercase tracking-wide text-gray-400">Current status</div>
        <span class="inline-flex mt-1 px-2 py-0.5 rounded text-xs font-semibold {{ $statusClass[$current->status] ?? 'bg-gray-100 text-gray-500' }}">
            {{ ucfirst($current->status) }}
        </span>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-[11px] uppercase tracking-wide text-gray-400">Completed / required</div>
        <div class="mt-1 text-sm font-semibold text-gray-800">{{ $bv($current->completed_bv_paise) }} / {{ $bv($current->required_bv_paise) }}</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-[11px] uppercase tracking-wide text-gray-400">Due date</div>
        <div class="mt-1 text-sm font-semibold text-gray-800">{{ $current->due_date?->format('d M Y') ?? '—' }}</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-[11px] uppercase tracking-wide text-gray-400">Grace ends</div>
        <div class="mt-1 text-sm font-semibold text-gray-800">{{ $current->grace_end_date?->format('d M Y') ?? '—' }}</div>
    </div>
</div>
@endif

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No repurchase cycles yet (the distributor has not reached Retailer, or the engine has not been run).</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">Cycle</th>
                <th class="px-3 py-2 text-left text-gray-500">Due</th>
                <th class="px-3 py-2 text-left text-gray-500">Grace ends</th>
                <th class="px-3 py-2 text-right text-gray-500">Required</th>
                <th class="px-3 py-2 text-right text-gray-500">Completed</th>
                <th class="px-3 py-2 text-center text-gray-500">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $cycle)
            <tr>
                <td class="px-3 py-2 font-medium">{{ $cycle->cycle_start_date?->format('d M Y') ?? '—' }}</td>
                <td class="px-3 py-2">{{ $cycle->due_date?->format('d M Y') ?? '—' }}</td>
                <td class="px-3 py-2 text-gray-500">{{ $cycle->grace_end_date?->format('d M Y') ?? '—' }}</td>
                <td class="px-3 py-2 text-right">{{ $bv($cycle->required_bv_paise) }}</td>
                <td class="px-3 py-2 text-right font-semibold {{ $cycle->completed_bv_paise >= $cycle->required_bv_paise ? 'text-green-700' : 'text-gray-700' }}">{{ $bv($cycle->completed_bv_paise) }}</td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $statusClass[$cycle->status] ?? 'bg-gray-100 text-gray-500' }}">
                        {{ ucfirst($cycle->status) }}
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>
