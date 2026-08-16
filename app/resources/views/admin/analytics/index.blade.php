@extends('admin.layouts.admin')
@section('title', 'Analytics')
@section('heading', 'Analytics')

@section('content')

@php
    use App\Modules\Shared\Support\IndianNumber;
@endphp

<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <p class="max-w-2xl text-sm leading-relaxed text-gray-600">
        Where people stop, and who keeps buying. Every figure is a record of what already happened —
        nothing on this page projects or extrapolates, because a chart that forecasts ends up in front
        of a prospect sooner or later.
    </p>
    <form method="GET" action="{{ route('admin.analytics.index') }}" class="flex flex-wrap items-end gap-2">
        <div>
            <label for="from" class="mb-1 block text-xs font-medium text-gray-500">From</label>
            <input id="from" name="from" type="date" value="{{ $from->toDateString() }}"
                   class="rounded-lg border-gray-300 text-sm">
        </div>
        <div>
            <label for="to" class="mb-1 block text-xs font-medium text-gray-500">To</label>
            <input id="to" name="to" type="date" value="{{ $to->toDateString() }}"
                   class="rounded-lg border-gray-300 text-sm">
        </div>
        <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
            Apply
        </button>
    </form>
</div>

{{-- ── Headline numbers (window-scoped) ─────────────────────────────── --}}
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Paid orders</p>
        <p class="mt-1 text-lg font-bold text-gray-900">{{ IndianNumber::format($totals['orders']) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Gross value</p>
        <p class="mt-1 text-lg font-bold text-brand-700 whitespace-nowrap">₹{{ IndianNumber::format($totals['gross_paise'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Average order</p>
        <p class="mt-1 text-lg font-bold text-gray-900 whitespace-nowrap">₹{{ IndianNumber::format($totals['average_order_paise'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">BV generated</p>
        <p class="mt-1 text-lg font-bold text-green-700 whitespace-nowrap">@bv($totals['bv_paise'] / 100)</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Cancelled / refunded</p>
        <p class="mt-1 text-lg font-bold text-red-600">
            {{ IndianNumber::format($totals['cancelled']) }} / {{ IndianNumber::format($totals['refunded']) }}
        </p>
    </div>
</div>

{{-- ── The two funnels ──────────────────────────────────────────────── --}}
<div class="grid gap-4 lg:grid-cols-2 mb-6">
    @foreach ([['Registration funnel', $registrationFunnel], ['Commerce funnel', $commerceFunnel]] as [$funnelTitle, $stages])
        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ $funnelTitle }}</h2>

            <ol class="space-y-3">
                @foreach ($stages as $stage)
                    <li>
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-sm font-medium text-gray-800">{{ $stage->label }}</span>
                            <span class="text-sm font-bold text-gray-900">{{ IndianNumber::format($stage->count) }}</span>
                        </div>

                        {{-- Bar width is the share of the FIRST stage, so the
                             shape of the whole funnel reads at a glance. --}}
                        <div class="mt-1 h-1.5 w-full rounded-full bg-gray-100">
                            <div class="h-1.5 rounded-full bg-brand-500" style="width: {{ min(100, $stage->shareOfFirst ?? 0) }}%"></div>
                        </div>

                        <p class="mt-1 text-xs text-gray-500">
                            {{ $stage->note }}
                            @if ($stage->dropFromPrevious() !== null)
                                <span class="{{ $stage->dropFromPrevious() > 50 ? 'text-red-600 font-medium' : 'text-gray-500' }}">
                                    — {{ $stage->dropFromPrevious() }}% lost from the step before.
                                </span>
                            @endif
                        </p>
                    </li>
                @endforeach
            </ol>

            <p class="mt-4 text-xs text-gray-400 leading-relaxed">
                Counts are of distinct people reaching each milestone inside the window. Somebody who
                started before it and finished inside it appears only where they finished, so a short
                window can show a later step ahead of an earlier one.
            </p>
        </div>
    @endforeach
</div>

{{-- ── The base ─────────────────────────────────────────────────────── --}}
<div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm mb-6">
    <h2 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500">The distributor base (as of today)</h2>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        @foreach ([
            'Distributors' => $baseShape['total'],
            'Active status' => $baseShape['active_status'],
            'Bought this month' => $baseShape['bought_this_month'],
            'Bought in 90 days' => $baseShape['bought_last_90_days'],
            'Never bought' => $baseShape['never_bought'],
        ] as $label => $value)
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ $label }}</p>
                <p class="mt-1 text-lg font-bold {{ $label === 'Never bought' && $value > 0 ? 'text-amber-600' : 'text-gray-900' }}">
                    {{ IndianNumber::format($value) }}
                </p>
            </div>
        @endforeach
    </div>

    <p class="mt-4 text-xs text-gray-400 leading-relaxed">
        "Never bought" is the number worth watching. A distributor who has never placed an order has
        nothing the compensation plan can pay on, and twelve months of it makes them liable to the
        Agreement §21 dormancy rule.
    </p>
</div>

{{-- ── Retention ────────────────────────────────────────────────────── --}}
<div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm mb-6">
    <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Monthly buyer retention</h2>
    <p class="mt-1 mb-4 text-xs text-gray-500 leading-relaxed">
        Measured on purchases, not logins — a distributor who signs in every month and never buys is not
        retained in any sense the business cares about. The percentage is the share of <em>last</em>
        month's buyers who bought again, so new buyers are never counted as retained.
    </p>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                    <th class="py-2 pr-4">Month</th>
                    <th class="py-2 pr-4">Buyers</th>
                    <th class="py-2 pr-4">Returning</th>
                    <th class="py-2">Retention</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($retention as $row)
                    <tr>
                        <td class="py-2 pr-4 font-medium text-gray-800">{{ $row['month'] }}</td>
                        <td class="py-2 pr-4 text-gray-600">{{ IndianNumber::format($row['buyers']) }}</td>
                        <td class="py-2 pr-4 text-gray-600">{{ IndianNumber::format($row['returning']) }}</td>
                        <td class="py-2 text-gray-900 font-medium">
                            {{ $row['retention_pct'] === null ? '—' : $row['retention_pct'].'%' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-gray-400">
        A dash means nobody bought in the month before, so there was nothing to retain.
    </p>
</div>

{{-- ── Top by volume ────────────────────────────────────────────────── --}}
<div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
    <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Highest volume in the window</h2>
    <p class="mt-1 mb-4 text-xs text-gray-500 leading-relaxed">
        BV attributed in the selected window. No rank and no earnings — this is an operational view of
        where volume came from, and it must not become a league table anybody shows a prospect.
    </p>

    @if ($topByVolume === [])
        <p class="text-sm text-gray-500">No BV in this window.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                        <th class="py-2 pr-4">ADN</th>
                        <th class="py-2 pr-4">Name</th>
                        <th class="py-2 pr-4">BV</th>
                        <th class="py-2 pr-4">Orders</th>
                        <th class="py-2">Genos team</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($topByVolume as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="py-2 pr-4 font-mono text-xs">
                                <a href="{{ route('admin.distributors.show', $row['distributor_id']) }}"
                                   class="text-brand-700 hover:underline">{{ $row['adn'] }}</a>
                            </td>
                            <td class="py-2 pr-4 text-gray-700">{{ $row['name'] ?? '—' }}</td>
                            <td class="py-2 pr-4 font-medium text-gray-900">@bv($row['bv_paise'] / 100)</td>
                            <td class="py-2 pr-4 text-gray-600">{{ IndianNumber::format($row['orders']) }}</td>
                            <td class="py-2 text-gray-600">{{ IndianNumber::format($row['team_size']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
