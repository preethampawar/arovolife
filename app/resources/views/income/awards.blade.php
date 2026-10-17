@extends('layouts.app')
@section('title', 'My Income — Awards & Rewards')

@section('content')
<div>
    <h1 class="text-2xl font-bold text-gray-900 mb-2">Awards &amp; Rewards</h1>

    @include('income._tabs')

    {{-- Page note: what an award is, in plan terms. Historical facts only — no part still to come is shown. --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        Lifetime Awards are merchandise only — never cash. A part is earned each month you qualify for a rank (from the second time, also meeting that rank's own-purchase condition): Part A the first time, Part B the second, Part C the third. Each part is recorded on the 1st of the following month and handed over as merchandise.
    </div>

    @php
        $awaiting = (int) ($counts[\App\Modules\Compensation\Models\LifetimeAwardMilestone::STATUS_PENDING] ?? 0);
        $handedOver = (int) ($counts[\App\Modules\Compensation\Models\LifetimeAwardMilestone::STATUS_DELIVERED] ?? 0);
    @endphp

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-sky-100 rounded-2xl border border-sky-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">Parts earned</p>
            <p class="text-2xl font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($awaiting + $handedOver) }}</p>
        </div>
        <div class="bg-amber-100 rounded-2xl border border-amber-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">Awaiting hand-over</p>
            <p class="text-2xl font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($awaiting) }}</p>
        </div>
        <div class="bg-green-100 rounded-2xl border border-green-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">Handed over</p>
            <p class="text-2xl font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($handedOver) }}</p>
        </div>
    </div>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-600 font-medium">No award parts recorded.</p>
            <p class="text-sm text-gray-600 mt-1">A part is recorded here on the 1st of the month after a month in which you qualify for a rank.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[720px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600 w-12">S.No.</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">
                            Earned for
                            <x-help-tip text="The month in which you qualified for the rank. The part is recorded on the 1st of the month after it." />
                        </th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Rank</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">
                            Part
                            <x-help-tip text="Part A is earned the first month you qualify for this rank, Part B the second and Part C the third. The plan provides one part for Ranks 1–2, two for Ranks 3–5 and three for Ranks 6–9." />
                        </th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Award</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            Merchandise value
                            <x-help-tip text="The value of the merchandise for this part as published in the Compensation Plan. It is not money owed and cannot be paid out or exchanged for cash. Tax under section 194R is settled before hand-over, as set out in the Compensation Plan (§8, §11.5)." />
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">
                            Status
                            <x-help-tip text="Withdrawn: a later correction showed the part was not earned. If you believe this is wrong, raise it through Grievance Redressal." />
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    @php
                        [$statusLabel, $statusClass] = match ($row->status) {
                            \App\Modules\Compensation\Models\LifetimeAwardMilestone::STATUS_DELIVERED => [
                                'Handed over'.($row->delivered_at ? ' on '.$row->delivered_at->timezone('Asia/Kolkata')->format('j M Y') : ''),
                                'bg-green-100 text-green-700',
                            ],
                            \App\Modules\Compensation\Models\LifetimeAwardMilestone::STATUS_CANCELLED => ['Withdrawn', 'bg-gray-100 text-gray-600'],
                            default => ['Awaiting hand-over', 'bg-amber-100 text-amber-700'],
                        };
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-500 tabular-nums">{{ $rows->firstItem() + $loop->index }}</td>
                        <td class="px-4 py-3 font-medium text-gray-800">{{ $row->triggered_month->format('F Y') }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $row->rank_number }} · {{ $rankNames[$row->rank_number] ?? 'Rank '.$row->rank_number }}</td>
                        <td class="px-4 py-3 text-gray-700">Part {{ $row->trancheLetter() }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $row->award_description }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->amount_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap {{ $statusClass }}">{{ $statusLabel }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(method_exists($rows, 'links'))
            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
        <p class="mt-4 text-xs text-gray-600">If you believe a part shown here is wrong, raise it through <a href="{{ url('/p/grievance') }}" class="text-brand-700 underline">Grievance Redressal</a>.</p>
    @endif
</div>
@endsection
