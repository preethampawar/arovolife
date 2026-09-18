<x-ui.card flush :title="$panelTitle">
    <x-slot:actions>
        <span class="text-[11px] tabular-nums text-gray-500">as of {{ $generated_at->format('H:i') }}</span>
        <button type="button" data-panel-refresh
                class="inline-flex items-center rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700"
                aria-label="Refresh {{ $panelTitle }}">
            {{ svg('lucide-refresh-cw', 'w-3.5 h-3.5') }}
        </button>
    </x-slot:actions>

    @if($groups->every(fn ($rows) => count($rows) === 0))
        <x-ui.empty-state title="Nothing needs attention right now." icon="check-circle" />
    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 p-5">
            @foreach($groups as $group => $rows)
                @continue(count($rows) === 0)
                {{-- Rendered in the order the service returns: catalogue order,
                     criticals first within each group (ActionCenterService::summary()).
                     This panel used to re-sort by count, which put a breached
                     statutory clock with one item outstanding below a non-statutory
                     warning with fifty — the summary and the Action Center screen
                     disagreeing about what was most urgent, on the panel whose whole
                     job is to say what to do first. Do not re-sort here; if the order
                     is wrong it is wrong on both surfaces and belongs in the service. --}}
                <div class="rounded-xl border border-gray-200">
                    <div class="border-b border-gray-100 bg-gray-50 px-4 py-2.5">
                        <h4 class="text-sm font-semibold text-gray-900">{{ \App\Modules\ActionCenter\Support\ActionGroup::label($group) }}</h4>
                    </div>
                    <ul class="divide-y divide-gray-100">
                        @foreach($rows as $row)
                            @php
                                $badgeTone = match ($row['severity']) {
                                    \App\Modules\ActionCenter\Support\Severity::CRITICAL => 'danger',
                                    \App\Modules\ActionCenter\Support\Severity::WARNING => 'warning',
                                    default => 'neutral',
                                };
                            @endphp
                            <li>
                                <a href="{{ route('admin.action-center.show', $row['key']) }}"
                                   class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-gray-50 transition-colors">
                                    <div class="min-w-0">
                                        <p class="flex items-center gap-1.5 text-sm font-medium text-gray-900">
                                            <span class="truncate">{{ $row['label'] }}</span>
                                            @if($row['statutory'])
                                                <span class="shrink-0 text-amber-600" aria-label="Statutory deadline" title="Statutory deadline">{{ svg('lucide-scale', 'w-3.5 h-3.5') }}</span>
                                            @endif
                                        </p>
                                        <p class="truncate text-xs text-gray-500">{{ $row['description'] }}</p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        @if($row['oldest_age_hours'] !== null)
                                            <span class="text-xs text-gray-500">oldest {{ \App\Modules\Shared\Support\IndianNumber::format($row['oldest_age_hours']) }}h</span>
                                        @endif
                                        <x-ui.badge :tone="$badgeTone">{{ \App\Modules\Shared\Support\IndianNumber::format($row['count']) }}</x-ui.badge>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
</x-ui.card>
