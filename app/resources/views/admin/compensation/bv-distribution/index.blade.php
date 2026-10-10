@extends('admin.layouts.admin')
@section('title', 'BV Distribution')
@section('heading', 'Company BV Distribution')

@section('content')
@php
    use App\Modules\Shared\Support\IndianNumber;

    $bv = static fn (int $paise): string => IndianNumber::format(intdiv($paise, 100), 0);
    $pct = static fn (int $bp): string => IndianNumber::percentFromBp($bp);

    // One colour per bonus, in the order of the client's chart.
    $colours = [
        1 => ['bar' => 'bg-green-500', 'badge' => 'bg-green-500', 'text' => 'text-green-700'],
        2 => ['bar' => 'bg-orange-500', 'badge' => 'bg-orange-500', 'text' => 'text-orange-700'],
        3 => ['bar' => 'bg-sky-500', 'badge' => 'bg-sky-500', 'text' => 'text-sky-700'],
        4 => ['bar' => 'bg-lime-500', 'badge' => 'bg-lime-600', 'text' => 'text-lime-700'],
        5 => ['bar' => 'bg-rose-500', 'badge' => 'bg-rose-500', 'text' => 'text-rose-700'],
        6 => ['bar' => 'bg-slate-500', 'badge' => 'bg-slate-600', 'text' => 'text-slate-700'],
        7 => ['bar' => 'bg-amber-500', 'badge' => 'bg-amber-500', 'text' => 'text-amber-700'],
    ];

    $periodLabel = $distribution->from->isSameDay($distribution->to)
        ? $distribution->from->format('d M Y')
        : $distribution->from->format('d M Y').' – '.$distribution->to->format('d M Y');
    $totalRateBp = $distribution->totalRateBp();
@endphp

<div class="mb-5 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Shows how the company's total BV for the chosen period divides across each bonus at the percentage configured in Plan Settings. These are allocations at plan rates, not the amounts the engines actually paid.
</div>

{{-- Period search: Day / Week / Month / Year --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-5">
    <div class="inline-flex rounded-lg border border-gray-300 bg-white p-0.5" role="group" aria-label="Period">
        @foreach(['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $value => $label)
            <label class="cursor-pointer">
                <input type="radio" name="period" value="{{ $value }}" class="peer sr-only" @checked($period === $value)>
                <span class="block px-3 py-1 rounded-md text-sm text-gray-700 peer-checked:bg-slate-800 peer-checked:text-white">{{ $label }}</span>
            </label>
        @endforeach
    </div>
    <label class="flex items-center gap-2 text-sm text-gray-700">
        Containing date
        <x-help-tip text="Pick any date: the page shows the day, the Wednesday-to-Tuesday earning week, the calendar month or the calendar year that contains it." />
        <input type="date" name="date" value="{{ $date }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    </label>
    <x-ui.button>Show</x-ui.button>
</form>

@if($distribution->rows === [])
    <x-ui.card>
        <x-ui.empty-state title="No bonus is switched on yet, so there is nothing to distribute." />
    </x-ui.card>
@else
{{-- Chart --}}
<x-ui.card class="mb-6">
    <div class="flex flex-col items-center text-center" data-bv-distribution-chart>
        <p class="text-xs uppercase tracking-wider font-semibold text-gray-600">Company BV · {{ $periodLabel }}</p>
        <p class="mt-1 text-3xl font-bold text-gray-900 tabular-nums">{{ $bv($distribution->companyBvPaise) }} BV</p>
        <span class="mt-1 inline-block rounded border border-gray-300 px-2 py-0.5 text-xs font-semibold text-gray-700">100%</span>
        <x-lucide-arrow-down class="w-6 h-6 text-slate-500 mt-2" aria-hidden="true" />
    </div>

    {{-- Proportional bar: each segment's width is its configured share. --}}
    <div class="mt-2 flex h-4 w-full overflow-hidden rounded-full bg-gray-100" aria-hidden="true">
        @foreach($distribution->rows as $row)
            <div class="{{ $colours[$row->number]['bar'] }} h-4" style="width: {{ $totalRateBp > 0 ? round($row->rateBp / max($totalRateBp, 10_000) * 100, 2) : 0 }}%"
                 title="{{ $row->label }} {{ $pct($row->rateBp) }}"></div>
        @endforeach
    </div>

    <div class="mt-5 grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
        @foreach($distribution->rows as $row)
            <div class="flex flex-col items-center text-center rounded-xl border border-gray-200 p-3" data-bv-distribution-bonus="{{ $row->number }}">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full {{ $colours[$row->number]['badge'] }} text-white text-sm font-bold">{{ $row->number }}</span>
                <x-lucide-arrow-down class="w-4 h-4 text-gray-400 my-1" aria-hidden="true" />
                <span class="rounded border border-gray-300 px-2 py-0.5 text-xs font-semibold text-gray-800">{{ $pct($row->rateBp) }}</span>
                <p class="mt-2 text-xs font-semibold {{ $colours[$row->number]['text'] }}">{{ $row->label }}</p>
                <p class="mt-1 text-sm font-bold text-gray-900 tabular-nums">{{ $bv($row->bvPaise) }} BV</p>
            </div>
        @endforeach
    </div>

    @if($totalRateBp > 10_000)
        <p class="mt-4 text-xs text-amber-700">The configured percentages add up to {{ $pct($totalRateBp) }}, which is more than 100% of company BV. Check the rates on Plan Settings.</p>
    @endif
</x-ui.card>

{{-- Table --}}
<x-ui.card flush>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-bv-distribution-table>
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-600 w-12">S.No.</th>
                    <th class="px-4 py-2 text-left text-gray-600">Bonus</th>
                    <th class="px-4 py-2 text-right text-gray-600">Configured %</th>
                    <th class="px-4 py-2 text-right text-gray-600">BV allocated</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($distribution->rows as $row)
                    <tr>
                        <td class="px-4 py-2 text-gray-500 tabular-nums">{{ $loop->iteration }}</td>
                        <td class="px-4 py-2 font-medium text-gray-900">{{ $row->label }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $pct($row->rateBp) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $bv($row->bvPaise) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-gray-50 font-semibold">
                <tr>
                    <td class="px-4 py-2"></td>
                    <td class="px-4 py-2 text-gray-900">Total allocated</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $pct($totalRateBp) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $bv($distribution->totalBvPaise()) }}</td>
                </tr>
                <tr>
                    <td class="px-4 py-2"></td>
                    <td class="px-4 py-2 text-gray-900">Company BV</td>
                    <td class="px-4 py-2 text-right tabular-nums">100%</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $bv($distribution->companyBvPaise) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-ui.card>
@endif
@endsection
