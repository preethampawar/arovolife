@extends('admin.layouts.admin')
@section('title', 'Lifetime Awards')
@section('heading', 'Lifetime Awards & Milestones')

@section('content')

@php $rupees = fn ($paise) => '₹'.\App\Modules\Shared\Support\IndianNumber::format(($paise ?? 0) / 100, 0); @endphp

<div class="mb-6 flex items-start justify-between gap-4">
    <div class="flex-1 rounded-lg border border-purple-200 bg-purple-50 p-4 text-sm text-purple-800">
        Lifetime awards are issued on a distributor's first achievement of a given rank, subject to re-qualification gates: Ranks 1–2 release immediately, Ranks 3–5 require 2 qualifications, Ranks 6–9 require 3. Choose <strong>Goods</strong> (no deductions) or <strong>Cash</strong> (Group C admin 3%/₹25k + 5% TDS) when marking delivered.
    </div>
    <a href="{{ route('admin.lifetime-awards.catalog') }}" class="shrink-0 px-4 py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">Reward catalog →</a>
</div>

{{-- Filter --}}
<form method="GET" class="flex gap-3 mb-6 items-end">
    <div>
        <label class="block text-xs text-gray-600 mb-1">Status <x-help-tip text="Filter the list by award status — pending, delivered or cancelled. Leave on All to show every milestone." /></label>
        <select name="status" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
            <option value="">All</option>
            <option value="pending" @selected(request('status') === 'pending')>Pending</option>
            <option value="delivered" @selected(request('status') === 'delivered')>Delivered</option>
            <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
        </select>
    </div>
    <button type="submit" class="px-4 py-1.5 bg-brand-700 text-white text-sm rounded-lg hover:bg-brand-800">Filter</button>
    @if(request('status'))
        <a href="{{ route('admin.lifetime-awards.index') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
    @endif
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($milestones->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-600 text-center">No lifetime award milestones yet.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-600">ADN</th>
                    <th class="px-4 py-2 text-left text-gray-600">Rank</th>
                    <th class="px-4 py-2 text-left text-gray-600">Triggered</th>
                    <th class="px-4 py-2 text-center text-gray-600">
                        Qualifications <x-help-tip text="Count / threshold. Award is only deliverable once the threshold is reached." />
                    </th>
                    <th class="px-4 py-2 text-left text-gray-600">Award</th>
                    <th class="px-4 py-2 text-center text-gray-600">Status</th>
                    <th class="px-4 py-2 text-left text-gray-600">Delivered at</th>
                    <th class="px-4 py-2 text-left text-gray-600">Disbursement</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($milestones as $milestone)
                @php
                $sc = ['pending' => 'bg-amber-100 text-amber-700', 'delivered' => 'bg-green-100 text-green-700', 'cancelled' => 'bg-red-100 text-red-700'];
                $threshold = \App\Modules\Compensation\Models\LifetimeAwardMilestone::releaseThreshold($milestone->rank_number);
                $releasable = $milestone->isReleasable();
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-mono">{{ $milestone->distributor?->adn ?? '—' }}</td>
                    <td class="px-4 py-2">
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-medium">
                            {{ $rankNames[$milestone->rank_number] ?? 'Rank '.$milestone->rank_number }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-gray-600">{{ \Illuminate\Support\Carbon::parse($milestone->triggered_month)->format('M Y') }}</td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex items-center gap-1 text-xs font-semibold {{ $releasable ? 'text-green-700' : 'text-amber-700' }}">
                            {{ $milestone->qualification_count }} / {{ $threshold }}
                            @if($releasable)
                            <span class="text-green-500" title="Releasable">✓</span>
                            @endif
                        </span>
                    </td>
                    <td class="px-4 py-2 text-gray-700">
                        @php $rc = $catalog[$milestone->rank_number] ?? ['budget_paise' => 0, 'rewards' => []]; @endphp
                        <div class="font-semibold text-gray-800">{{ $rupees($rc['budget_paise']) }} budget</div>
                        @if(!empty($rc['rewards']))
                        <ul class="mt-1 space-y-0.5 text-[11px] text-gray-600 list-disc list-inside">
                            @foreach($rc['rewards'] as $reward)
                            <li>{{ $reward['item'] }} <span class="text-gray-600">— {{ $rupees($reward['worth_paise']) }}</span></li>
                            @endforeach
                        </ul>
                        @else
                        <span class="text-[11px] text-gray-600">{{ $milestone->award_description }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$milestone->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($milestone->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-gray-600">
                        {{ $milestone->delivered_at ? $milestone->delivered_at->format('d M Y') : '—' }}
                    </td>
                    <td class="px-4 py-2 text-gray-600 text-xs">
                        @if($milestone->disbursement_type)
                            <span class="inline-flex px-2 py-0.5 rounded {{ $milestone->disbursement_type === 'cash' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600' }} text-[10px] font-medium">
                                {{ ucfirst($milestone->disbursement_type) }}
                            </span>
                            @if($milestone->disbursement_type === 'cash' && $milestone->net_paise)
                            <div class="mt-0.5 text-[10px] text-gray-600">Net {{ $rupees($milestone->net_paise) }}</div>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-2">
                        @if($milestone->status === 'pending')
                            @if($releasable)
                            <form method="POST" action="{{ route('admin.lifetime-awards.deliver', $milestone->id) }}"
                                  data-confirm-title="Mark award as delivered"
                                  data-confirm="Mark this lifetime award as delivered? Choose the disbursement method before submitting."
                                  data-confirm-impact="Impact: milestone is recorded as fulfilled. Cash awards credit net amount to the distributor's wallet after Group C admin charge + TDS.">
                                @csrf
                                <div class="flex items-center gap-1.5 mb-1">
                                    <select name="disbursement_type" required
                                            class="rounded border border-gray-300 px-1.5 py-0.5 text-[10px]">
                                        <option value="goods">Goods</option>
                                        <option value="cash">Cash</option>
                                    </select>
                                </div>
                                <button type="submit"
                                        class="px-2 py-1 rounded bg-green-100 text-green-700 hover:bg-green-200 text-[10px] font-medium">
                                    Mark Delivered
                                </button>
                            </form>
                            @else
                            <span class="text-[10px] text-amber-700 font-medium">
                                Awaiting {{ $threshold - $milestone->qualification_count }} more re-qual{{ $threshold - $milestone->qualification_count > 1 ? 's' : '' }}
                            </span>
                            @endif
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $milestones->links() }}</div>
    @endif
</div>

@endsection
