@php
    $bv = fn ($paise) => \App\Modules\Shared\Support\IndianNumber::format(($paise ?? 0) / 100, 0).' BV';
    $statusClass = [
        'completed' => 'bg-green-100 text-green-700',
        'active' => 'bg-blue-100 text-blue-700',
        'suspended' => 'bg-red-100 text-red-700',
    ];
    $current = (! empty($rows) && ! $rows->isEmpty()) ? $rows->first() : null;
    $money = fn ($paise) => '₹'.\App\Modules\Shared\Support\IndianNumber::format(($paise ?? 0) / 100, 2);
    $reasonLabel = [
        'bv_short' => 'BV short',
        'wallet_nonzero' => 'Wallet not ₹0',
        'both' => 'BV short + wallet not ₹0',
    ];
    // The days the cycle forfeited: due + 1 up to the day before fulfilment.
    $forfeited = function ($cycle) {
        $window = $cycle->forfeitedWindow();

        if ($window === null) {
            return '—';
        }

        [$from, $to] = $window;

        return $to === null
            ? $from->format('d M Y').' → ongoing'
            : $from->format('d M Y').' → '.$to->format('d M Y');
    };
@endphp

@developer
<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    A 30-day repurchase window: the due date falls 30 days after the day the distributor first reached 600
    personal BV, and a failed window re-anchors on the day they fulfil it. It passes only if BOTH conditions
    hold: self-purchase BV inside the window at least the required amount, AND a repurchase wallet of ₹0 on
    the window's LAST day — which is why a window is never judged before it closes. From the day after the
    due date until the day they fulfil, the distributor's Genos BV for those days is not counted and the
    income that would have followed from it is permanently forfeited. Maintained by the daily
    <code class="font-mono">repurchase:evaluate</code> command; only active when the Repurchase engine
    feature flag is on.
</div>
@enddeveloper

@if($current)
<div class="mb-4 grid grid-cols-2 md:grid-cols-4 gap-3">
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-[11px] uppercase tracking-wide text-gray-600">Current status</div>
        <span class="inline-flex mt-1 px-2 py-0.5 rounded text-xs font-semibold {{ $statusClass[$current->status] ?? 'bg-gray-100 text-gray-600' }}">
            {{ ucfirst($current->status) }}
        </span>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-[11px] uppercase tracking-wide text-gray-600">Completed / required</div>
        <div class="mt-1 text-sm font-semibold text-gray-800">{{ $bv($current->completed_bv_paise) }} / {{ $bv($current->required_bv_paise) }}</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-[11px] uppercase tracking-wide text-gray-600">Due date</div>
        <div class="mt-1 text-sm font-semibold text-gray-800">{{ $current->due_date?->format('d M Y') ?? '—' }}</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-[11px] uppercase tracking-wide text-gray-600">Why it failed</div>
        <div class="mt-1 text-sm font-semibold text-gray-800">{{ $reasonLabel[$current->failure_reason] ?? '—' }}</div>
    </div>
</div>
@endif

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No repurchase cycles yet (the distributor has not reached 600 personal BV, or the engine has not been run).</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-600 w-12">S.No.</th>
                <th class="px-3 py-2 text-left text-gray-600">Cycle</th>
                <th class="px-3 py-2 text-left text-gray-600">Due</th>
                <th class="px-3 py-2 text-right text-gray-600">Required</th>
                <th class="px-3 py-2 text-right text-gray-600">Completed</th>
                <th class="px-3 py-2 text-right text-gray-600">Wallet at close <x-help-tip text="The repurchase wallet balance frozen at the last instant of this window — the answer to condition (B). Blank while the window is still open." /></th>
                <th class="px-3 py-2 text-left text-gray-600">Fulfilled</th>
                <th class="px-3 py-2 text-left text-gray-600">Days not counted <x-help-tip text="The days this cycle forfeited — from the day after the due date up to the day before it was fulfilled. Their Genos BV is never counted and the income from it is never paid." /></th>
                <th class="px-3 py-2 text-center text-gray-600">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $cycle)
            <tr>
                <td class="px-3 py-2 text-gray-500 tabular-nums">{{ $rows->firstItem() + $loop->index }}</td>
                <td class="px-3 py-2 font-medium">{{ $cycle->cycle_start_date?->format('d M Y') ?? '—' }}</td>
                <td class="px-3 py-2">{{ $cycle->due_date?->format('d M Y') ?? '—' }}</td>
                <td class="px-3 py-2 text-right">{{ $bv($cycle->required_bv_paise) }}</td>
                <td class="px-3 py-2 text-right font-semibold {{ $cycle->completed_bv_paise >= $cycle->required_bv_paise ? 'text-green-700' : 'text-gray-700' }}">{{ $bv($cycle->completed_bv_paise) }}</td>
                <td class="px-3 py-2 text-right {{ $cycle->wallet_zeroed === false ? 'font-semibold text-red-700' : 'text-gray-600' }}">
                    {{ $cycle->resolved_at === null ? '—' : $money($cycle->wallet_balance_paise) }}
                </td>
                <td class="px-3 py-2 text-gray-600">
                    {{ $cycle->fulfilled_on?->format('d M Y') ?? '—' }}
                    @if($cycle->fulfilled_on && ! $cycle->fulfilledOnTime())
                    <span class="text-[10px] text-orange-700">(late)</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-gray-600">{{ $forfeited($cycle) }}</td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $statusClass[$cycle->status] ?? 'bg-gray-100 text-gray-600' }}">
                        {{ ucfirst($cycle->status) }}
                    </span>
                    @if($cycle->failure_reason)
                    <div class="mt-0.5 text-[10px] text-gray-500">{{ $reasonLabel[$cycle->failure_reason] ?? $cycle->failure_reason }}</div>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>
