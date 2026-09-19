{{-- When a scheduled run last fired and when it fires next, as instants.
     Fed a \App\Modules\Compensation\Support\RunClock — every wording it shows
     belongs to that object, so the pages cannot drift apart. --}}
@props(['clock'])

<div {{ $attributes->merge(['class' => 'rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700']) }}>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        {{ svg('lucide-clock', 'w-3.5 h-3.5 shrink-0 text-gray-500', ['aria-hidden' => 'true']) }}
        <span class="font-semibold text-gray-900">{{ $clock->name() }}</span>

        <span class="text-gray-400">·</span>
        <span>last ran <strong class="text-gray-900">{{ $clock->lastRunLabel() }}</strong></span>
        <span class="inline-flex px-2 py-0.5 rounded font-medium {{ $clock->lastRunPillClasses() }}">{{ $clock->lastRunStatus() }}</span>
        @if($clock->lastRunPeriodLabel() !== null)
        <span class="text-gray-600">for {{ $clock->lastRunPeriodLabel() }}</span>
        @endif

        <span class="text-gray-400">·</span>
        <span>next run <strong class="text-gray-900">{{ $clock->nextRunLabel() }}</strong></span>
        @if($clock->nextRunRelative() !== null)
        <span class="text-gray-600">({{ $clock->nextRunRelative() }})</span>
        @endif
    </div>
</div>
