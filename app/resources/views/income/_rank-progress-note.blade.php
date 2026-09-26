{{-- Rank progress note (compliance-officer copy, 2026-09-26). Shown whenever
     the rank progress snapshot is on, because the figures above are live
     progress, not a rank, and they can go down after a cancellation or
     refund. Nothing is rendered while the flag is off (zero trace). --}}
@if($rankStatus->progressSnapshotOn)
<p class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-3 flex items-start gap-1.5">
    <x-lucide-info class="w-3.5 h-3.5 mt-0.5 shrink-0" />
    <span>
        @if($rankStatus->showsSnapshotCounts())
            Progress as of the end of {{ $rankStatus->progressAsOf->format('j M') }}.
        @else
            Progress so far this month.
        @endif
        This is not a rank. Ranks are decided on the 1st of next month from that month's final orders, and these figures can go down if orders are cancelled or refunded.
    </span>
</p>
@endif
