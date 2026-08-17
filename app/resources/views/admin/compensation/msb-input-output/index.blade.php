@extends('admin.layouts.admin')
@section('title', 'MSB Input & Output Per Day')
@section('heading', 'Input & Output Per Day Calculation Table for MSB')

@section('content')

<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Per-day Mentorship Bonus pool economics. Each day shows the company's total received BV, the
    <strong>MSB pool</strong> (the configured pool rate of that day's BV), every sponsor who accrued MSB score
    points, the <strong>point value</strong> the day resolved to and each sponsor's income.
    <span class="font-medium">MSB score point value = (MSB pool) ÷ (total MSB score points)</span>, floored to whole
    rupees — so the day's total income equals the pool apart from that remainder. Search by day number, week number
    or date range.
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="number" name="day" value="{{ $day ?? '' }}" min="1" placeholder="Day #"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-24">
    <input type="number" name="week" value="{{ $week ?? '' }}" min="1" placeholder="Week #"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-24">
    <input type="date" name="from" value="{{ $from ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <input type="date" name="to" value="{{ $to ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-700 text-white text-sm font-medium">Apply</button>
    @if($day || $week || $from || $to)
    <a href="{{ route('admin.compensation.msb-input-output.index') }}"
       class="text-sm text-gray-600 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.msb-input-output.export', array_filter(['day' => $day, 'week' => $week, 'from' => $from, 'to' => $to])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

@if($pools->isEmpty())
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <p class="px-6 py-8 text-sm text-gray-600 text-center">
        No pooled cut-off days yet — rows appear once the daily cut-off runs with the Mentorship Bonus enabled.
    </p>
</div>
@else
<div class="space-y-6">
    @foreach($pools->items() as $pool)
    @php
        $dateStr = $pool->cutoff_date->toDateString();
        $dayNo = $anchor === null ? null : (int) $anchor->diffInDays($pool->cutoff_date->copy()->startOfDay()) + 1;
        $weekNo = $dayNo === null ? null : intdiv($dayNo - 1, 7) + 1;
        $rows = collect($earners[$dateStr] ?? []);
        $totalPoints = (int) $rows->sum('msb_points');
        $totalIncome = (int) $rows->sum('income_paise');
    @endphp
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
            <span class="font-semibold text-gray-800">{{ $pool->cutoff_date->format('d/m/Y') }}</span>
            <span class="text-gray-600">Day <strong class="text-gray-700">{{ $dayNo ?? '—' }}</strong></span>
            <span class="text-gray-600">Week <strong class="text-gray-700">{{ $weekNo ?? '—' }}</strong></span>
            <span class="text-gray-600">Day total received BV <strong class="text-gray-700">@bv($pool->company_bv_paise)</strong></span>
            <span class="text-gray-600">MSB pool ({{ rtrim(rtrim(number_format($pool->pool_rate_bp / 100, 2), '0'), '.') }}%)
                <strong class="text-gray-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($pool->pool_paise / 100, 2) }}</strong></span>
            <span class="text-gray-600">Point value
                <strong class="text-gray-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($pool->point_value_paise / 100, 2) }}</strong></span>
        </div>

        @if($pool->total_points === 0 && $pool->pool_paise > 0)
        <div class="px-4 py-2 bg-amber-50 border-b border-amber-100 text-[11px] text-amber-800">
            No MSB score points were accrued on this day, so the pool went unspent and the day's point value is
            frozen at ₹0. A later retry of this day's cut-off therefore credits ₹0.
        </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left text-gray-600 font-medium">S.no</th>
                        <th class="px-3 py-2 text-right text-gray-600 font-medium">Day total received BV</th>
                        <th class="px-3 py-2 text-right text-gray-600 font-medium">MSB — {{ rtrim(rtrim(number_format($pool->pool_rate_bp / 100, 2), '0'), '.') }}%</th>
                        <th class="px-3 py-2 text-left text-gray-600 font-medium">Individual distributor MSB points <x-help-tip text="Each sponsor who accrued Mentorship Bonus points on this day, with the points they accrued. A sponsor credited by more than one sponsee appears once with their points summed." /></th>
                        <th class="px-3 py-2 text-right text-gray-600 font-medium">Point value <x-help-tip text="The MSB pool divided by the day's total MSB score points, floored to whole rupees. One value applies to every earner that day." /></th>
                        <th class="px-3 py-2 text-right text-gray-600 font-medium">Income</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($rows as $i => $row)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 text-gray-600">{{ $i + 1 }}</td>
                        <td class="px-3 py-2 text-right text-gray-700">@bv($pool->company_bv_paise)</td>
                        <td class="px-3 py-2 text-right text-gray-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($pool->pool_paise / 100, 2) }}</td>
                        <td class="px-3 py-2">
                            <a href="{{ route('admin.compensation.distributors.show', [$row->sponsor_id, 'tab' => 'mb']) }}"
                               class="text-brand-700 hover:underline font-medium">{{ $row->full_name ?: 'Distributor' }}</a>
                            <span class="text-gray-600">({{ $row->adn }})</span>
                            <span class="ml-1 inline-flex px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700 font-semibold">{{ \App\Modules\Shared\Support\IndianNumber::format((int) $row->msb_points) }} pts</span>
                        </td>
                        <td class="px-3 py-2 text-right text-gray-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format(((int) $row->point_value_paise) / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->income_paise / 100, 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-3 py-4 text-center text-gray-600">No Mentorship Bonus earners this day.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-200 text-gray-800">
                    <tr>
                        <td class="px-3 py-1.5 text-right text-xs" colspan="3">Total MSB score points</td>
                        <td class="px-3 py-1.5 font-semibold">{{ \App\Modules\Shared\Support\IndianNumber::format($totalPoints) }}</td>
                        <td class="px-3 py-1.5 text-right text-[11px] text-gray-600">
                            {{ $totalPoints > 0 ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->pool_paise / 100, 0).' ÷ '.\App\Modules\Shared\Support\IndianNumber::format($totalPoints) : '—' }}
                        </td>
                        <td></td>
                    </tr>
                    <tr class="font-semibold">
                        <td class="px-3 py-2 text-right text-xs" colspan="4">Total income</td>
                        <td class="px-3 py-2 text-right {{ $pool->leftover_paise < 0 ? 'text-red-600' : 'text-gray-600' }} text-[11px]">
                            leftover {{ $pool->leftover_paise < 0 ? '−' : '' }}₹{{ \App\Modules\Shared\Support\IndianNumber::format(abs($pool->leftover_paise) / 100, 2) }}
                        </td>
                        <td class="px-3 py-2 text-right text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($totalIncome / 100, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @endforeach
</div>
<div class="mt-4">{{ $pools->links() }}</div>
@endif

@endsection
