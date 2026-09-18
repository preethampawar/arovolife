@php
    $engineRunsUrl = route('admin.compensation.engine-runs.index');

    // EngineHealthReport carries no label map of its own — these five buckets
    // are the only ones this panel ever renders, so the mapping lives here
    // rather than on the DTO. Order matches the constructor's own order.
    $buckets = [
        ['label' => 'Failed runs', 'count' => count($report->failures), 'tone' => 'danger'],
        ['label' => 'Missing runs', 'count' => count($report->missing), 'tone' => 'warning'],
        ['label' => 'Stuck runs', 'count' => count($report->stuck), 'tone' => 'danger'],
        ['label' => 'Premature freezes', 'count' => count($report->prematureFreezes), 'tone' => 'warning'],
        ['label' => 'Chain alerts', 'count' => count($report->chainAlerts), 'tone' => 'danger'],
    ];
@endphp
<x-ui.card flush :title="$panelTitle">
    <x-slot:actions>
        <span class="text-[11px] tabular-nums text-gray-500">as of {{ $generated_at->format('H:i') }}</span>
        <button type="button" data-panel-refresh
                class="inline-flex items-center rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700"
                aria-label="Refresh {{ $panelTitle }}">
            {{ svg('lucide-refresh-cw', 'w-3.5 h-3.5') }}
        </button>
    </x-slot:actions>

    @if($report->isHealthy())
        <div class="flex items-center gap-2 p-5 text-sm font-medium text-green-600">
            {{ svg('lucide-circle-check', 'w-4 h-4') }}
            <span>All engines healthy</span>
        </div>
    @else
        <ul class="divide-y divide-gray-100">
            @foreach($buckets as $bucket)
                @continue($bucket['count'] === 0)
                <li>
                    <a href="{{ $engineRunsUrl }}"
                       class="flex items-center justify-between gap-3 px-5 py-3 transition-colors hover:bg-gray-50">
                        <span class="text-sm font-medium text-gray-900">{{ $bucket['label'] }}</span>
                        <x-ui.badge :tone="$bucket['tone']">{{ \App\Modules\Shared\Support\IndianNumber::format($bucket['count']) }}</x-ui.badge>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
