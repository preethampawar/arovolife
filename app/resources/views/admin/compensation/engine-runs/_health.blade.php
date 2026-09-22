{{-- "Runs needing attention" — the EngineHealthService report, the same one the
     dashboard tile counts and the 08:00 digest mails.

     It has to live here because the engine cards below cannot show it: a
     missing run leaves NO `engine_runs` row, so it has no card, and the failure
     banners above cover only the three root scheduled runs. Before this section
     the dashboard tile linked to a page where none of its items existed.

     INFORMATION AND INSTRUCTIONS, NOT A CONTROL. Each item carries the numbered
     steps the controller settled for this environment; the triggers, where an
     engine has one, are on its own card further down. --}}
@php
    $healthTotal = array_sum(array_map(static fn (array $group): int => count($group['items']), $healthGroups));
@endphp

@if($healthGroups !== [])
<div id="runs-needing-attention" class="mb-6 rounded-xl border-2 border-amber-400 bg-amber-50 p-4 text-sm text-amber-900">
    <div class="flex items-start gap-3">
        <x-lucide-triangle-alert class="w-5 h-5 mt-0.5 shrink-0 text-amber-600" />
        <div class="flex-1">
            <strong class="block text-base mb-1">
                {{ $healthTotal }} {{ \Illuminate\Support\Str::plural('item', $healthTotal) }} needing attention
            </strong>
            <p class="mb-4">
                The same items the daily engine-health email lists at 08:00 IST. Each one below says what to do.
            </p>

            @foreach($healthGroups as $group)
            <div id="{{ $group['anchor'] }}" class="mb-4 last:mb-0 scroll-mt-24">
                <p class="font-medium mb-2">{{ $group['title'] }} ({{ count($group['items']) }})</p>

                <ol class="space-y-3">
                    @foreach($group['items'] as $item)
                    <li class="rounded border border-amber-200 bg-white p-3">
                        <p class="font-medium mb-1">{{ $item['line'] }}</p>

                        @if($item['detail'] !== null && $item['detail'] !== '')
                        <div class="mb-2 rounded border border-amber-200 bg-amber-50 p-2 font-mono text-xs whitespace-pre-line">{{ $item['detail'] }}</div>
                        @endif

                        @if($item['steps'] !== [])
                        <p class="text-xs font-medium mb-1">What to do:</p>
                        <ol class="list-decimal ml-5 space-y-0.5 text-xs">
                            @foreach($item['steps'] as $step)
                            <li>{{ $step }}</li>
                            @endforeach
                        </ol>
                        @endif
                    </li>
                    @endforeach
                </ol>
            </div>
            @endforeach

            <p class="mt-4 mb-0 border-t border-amber-200 pt-3">
                A re-run never credits anybody twice: an engine only fills what the missed run left empty, and every
                trigger is audit-logged with your name and reason. If a step fails again after one retry, stop and send
                the recorded error to the platform team instead of retrying.
            </p>
        </div>
    </div>
</div>
@endif
