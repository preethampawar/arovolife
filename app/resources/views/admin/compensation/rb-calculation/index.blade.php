@extends('admin.layouts.admin')
@section('title', 'Rank Bonus Calculation Report')
@section('heading', 'Rank Bonus Monthly Calculation Report')

@section('content')

@php
    $statusBadges = [
        'credited' => 'bg-green-100 text-green-700',
        'pending'  => 'bg-amber-100 text-amber-700',
        'reversed' => 'bg-red-100 text-red-700',
        'requalification_held' => 'bg-orange-100 text-orange-700',
    ];
@endphp

@developer
<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Global monthly Rank Bonus (RB) calculation — one row per distributor per month.
    Rank 1 is points-based (achievers earn RAP, AO-GO grantees earn offer points; income = points × point value).
    Ranks 2–9 split each pool equally among achievers. "requalification_held" = re-qualified but missed the
    month's requalification conditions (rank's repurchase BV + cleared repurchase wallet) — recorded, not paid.
</div>
@enddeveloper

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="text" name="q" value="{{ $q ?? '' }}"
           placeholder="Search ADN or name…"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-52">
    <input type="month" name="month" value="{{ $month ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <input type="number" name="rank" value="{{ $rank ?? '' }}" min="2" max="9" placeholder="Rank # (2–9)"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-32">
    <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All statuses</option>
        <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
        <option value="credited" {{ $status === 'credited' ? 'selected' : '' }}>Credited</option>
        <option value="reversed" {{ $status === 'reversed' ? 'selected' : '' }}>Reversed</option>
        <option value="requalification_held" {{ $status === 'requalification_held' ? 'selected' : '' }}>Requalification held</option>
    </select>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-700 text-white text-sm font-medium">Apply</button>
    @if($q || $month || $rank || $status)
    <a href="{{ route('admin.compensation.rb-calculation.index') }}"
       class="text-sm text-gray-600 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.rb-calculation.export', array_filter(['q' => $q, 'month' => $month, 'rank' => $rank, 'status' => $status])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

{{-- ── Rank 1 — points model (RAP + AO-GO) ─────────────────────────────── --}}
<h2 class="text-sm font-semibold text-gray-800 mb-2">Rank 1 — Silver (RAP &amp; AO-GO points)</h2>
@foreach($rank1Blocks as $block)
    @include('admin.compensation._formulas.rb-month', ['rank1' => $block['rank1'], 'date' => $block['date'], 'rankNames' => $rankNames, 'open' => count($rank1Blocks) === 1])
@endforeach
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-8">
    @if($rank1Rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No Rank-1 records found.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium w-10">#</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">ADN</th>
                    @if($adcOn)<th class="px-3 py-2 text-left text-gray-600 font-medium">Arete Center</th>@endif
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Name</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Title</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Month</th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">RAP</th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">AO-GO Points</th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">Point Value</th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">Total Income</th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($rank1Rows->items() as $i => $row)
                @php
                    $titleObj = $titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0);
                    $rowNumber = ($rank1Rows->currentPage() - 1) * $rank1Rows->perPage() + $i + 1;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-600">{{ $rowNumber }}</td>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', [$row->distributor_id, 'tab' => 'rank-bonus']) }}"
                           class="text-brand-700 hover:underline">
                            {{ $row->adn }}
                        </a>
                    </td>
                    @if($adcOn)<td class="px-3 py-2 text-gray-600">{{ $areteCenterMap[$row->distributor_id] ?? '—' }}</td>@endif
                    <td class="px-3 py-2 text-gray-700">{{ $row->full_name ?? '—' }}</td>
                    <td class="px-3 py-2">
                        @if($titleObj->title !== null)
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-700">
                            {{ $titleObj->title }}
                        </span>
                        @else
                        <span class="text-gray-600">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600 whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($row->month_start)->format('M Y') }}
                    </td>
                    <td class="px-3 py-2 text-center font-semibold text-purple-700">{{ $row->rap_points ?? '—' }}</td>
                    <td class="px-3 py-2 text-center font-semibold text-teal-700">{{ $row->aogo_points ?? '—' }}</td>
                    <td class="px-3 py-2 text-right text-gray-600">
                        {{ $row->point_value_paise !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($row->point_value_paise / 100, 0) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right">
                        <span class="font-semibold {{ $row->status === 'reversed' ? 'text-red-600' : 'text-green-700' }}">
                            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->net_paise / 100, 2) }}
                        </span>
                        @if($row->tds_paise > 0)
                        <span class="block text-[10px] text-gray-600 font-normal">
                            gross ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gross_paise / 100, 2) }} · TDS ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}
                        </span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $statusBadges[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ str_replace('_', ' ', $row->status) }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rank1Rows->links() }}</div>
    @endif
</div>

{{-- ── Ranks 2–9 — equal split ─────────────────────────────────────────── --}}
<h2 class="text-sm font-semibold text-gray-800 mb-2">Ranks 2–9 — equal split per rank pool</h2>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rankRows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No Rank 2–9 records found.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium w-10">#</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">ADN</th>
                    @if($adcOn)<th class="px-3 py-2 text-left text-gray-600 font-medium">Arete Center</th>@endif
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Name</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Title</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Month</th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">Rank</th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">Income</th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($rankRows->items() as $i => $row)
                @php
                    $titleObj = $titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0);
                    $rowNumber = ($rankRows->currentPage() - 1) * $rankRows->perPage() + $i + 1;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-600">{{ $rowNumber }}</td>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', [$row->distributor_id, 'tab' => 'rank-bonus']) }}"
                           class="text-brand-700 hover:underline">
                            {{ $row->adn }}
                        </a>
                    </td>
                    @if($adcOn)<td class="px-3 py-2 text-gray-600">{{ $areteCenterMap[$row->distributor_id] ?? '—' }}</td>@endif
                    <td class="px-3 py-2 text-gray-700">{{ $row->full_name ?? '—' }}</td>
                    <td class="px-3 py-2">
                        @if($titleObj->title !== null)
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-700">
                            {{ $titleObj->title }}
                        </span>
                        @else
                        <span class="text-gray-600">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600 whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($row->month_start)->format('M Y') }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-purple-100 text-purple-700 font-bold text-[11px]">
                            {{ $row->rank_number }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right">
                        <span class="font-semibold {{ $row->status === 'reversed' ? 'text-red-600' : 'text-green-700' }}">
                            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->net_paise / 100, 2) }}
                        </span>
                        @if($row->tds_paise > 0)
                        <span class="block text-[10px] text-gray-600 font-normal">
                            gross ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gross_paise / 100, 2) }} · TDS ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}
                        </span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $statusBadges[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ str_replace('_', ' ', $row->status) }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rankRows->links() }}</div>
    @endif
</div>

@endsection
