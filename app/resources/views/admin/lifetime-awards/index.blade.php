@extends('admin.layouts.admin')
@section('title', 'Lifetime Awards')
@section('heading', 'Lifetime Awards')

@section('content')

@php $rupees = fn ($paise) => '₹'.\App\Modules\Shared\Support\IndianNumber::format(($paise ?? 0) / 100, 0); @endphp

<div class="mb-6 rounded-lg border border-purple-200 bg-purple-50 p-4 text-sm text-purple-800">
    A Lifetime Award is a fixed cash amount per rank, paid once per lifetime (the client, 2026-11-10). Only the month's highest payable Rank Bonus rank earns one, and only if that rank's award was never taken — a lower rank not yet awarded is paid in a later month where it is the highest (2026-11-17). It is credited on the 1st with the Rank Bonus and paid with the monthly payout on the 8th: <strong>TDS only</strong> — no admin charge, no repurchase deduction, outside the ₹1 crore cap. A KYC, bank or web-only hold keeps it in the wallet until a later 8th; it is never forfeited.
</div>

<x-filter-bar :filters="$filters" />

<x-ui.card flush>
    @if($milestones->isEmpty())
        <x-ui.empty-state title="No Lifetime Awards yet." />
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-600 w-12">S.No.</th>
                    <th class="px-4 py-2 text-left text-gray-600">Partner</th>
                    <th class="px-4 py-2 text-left text-gray-600">ADN</th>
                    <th class="px-4 py-2 text-left text-gray-600">Rank</th>
                    <th class="px-4 py-2 text-left text-gray-600">
                        Earned for <x-help-tip text="The month in which the rank was the partner's highest payable rank and its award was not yet taken. The award is credited on the 1st of the next month and paid on the 8th." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-600">Amount</th>
                    <th class="px-4 py-2 text-center text-gray-600">
                        Status <x-help-tip text="Credited: in the wallet, not yet in a payout. In payout — awaiting bank: in a monthly payout batch, bank response not yet imported. Paid: the bank response marked the transfer paid." />
                    </th>
                    <th class="px-4 py-2 text-left text-gray-600">
                        Paid in batch <x-help-tip text="The monthly payout batch that swept the award to the bank. Blank while it is still in the wallet — not yet due, or held." />
                    </th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($milestones as $milestone)
                @php
                $sc = ['pending' => 'bg-amber-100 text-amber-700', 'credited' => 'bg-green-100 text-green-700', 'delivered' => 'bg-gray-100 text-gray-700', 'cancelled' => 'bg-red-100 text-red-700'];
                $labels = ['pending' => 'Pending', 'credited' => 'Credited', 'delivered' => 'Delivered (merchandise)', 'cancelled' => 'Withdrawn'];
                $batch = $milestone->walletCredit?->sweptByBatch;
                $withdrawable = in_array($milestone->status, ['pending', 'credited'], true) && $batch === null;
                $statusKey = $milestone->status;
                if ($statusKey === 'credited' && $batch !== null) {
                    $statusKey = isset($paidKeys[$batch->id.':'.$milestone->distributor_id]) ? 'paid' : 'in_payout';
                }
                $sc += ['paid' => 'bg-emerald-100 text-emerald-800', 'in_payout' => 'bg-sky-100 text-sky-700'];
                $labels += ['paid' => 'Paid', 'in_payout' => 'In payout — awaiting bank'];
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 text-gray-500 tabular-nums">{{ $milestones->firstItem() + $loop->index }}</td>
                    <td class="px-4 py-2 text-gray-800">{{ $milestone->distributor?->user?->full_name ?? '—' }}</td>
                    <td class="px-4 py-2 font-mono">{{ $milestone->distributor?->adn ?? '—' }}</td>
                    <td class="px-4 py-2">
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-medium">
                            {{ $rankNames[$milestone->rank_number] ?? 'Rank '.$milestone->rank_number }}
                        </span>
                        @if(str_contains((string) $milestone->award_description, ' Lifetime Award — '))
                        <div class="text-[11px] text-gray-600 mt-0.5">{{ \Illuminate\Support\Str::afterLast($milestone->award_description, ' Lifetime Award — ') }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-gray-600">{{ ($milestone->achieved_month ?? $milestone->triggered_month)->format('M Y') }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-gray-800 tabular-nums">{{ $rupees($milestone->amount_paise) }}</td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$statusKey] ?? 'bg-gray-100 text-gray-600' }}"
                              @if($milestone->status === 'cancelled' && $milestone->notes) title="{{ $milestone->notes }}" @endif>
                            {{ $labels[$statusKey] ?? ucfirst($statusKey) }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-gray-600">
                        {{ $batch ? '#'.$batch->id.' — '.$batch->batch_date->format('d M Y') : '—' }}
                    </td>
                    <td class="px-4 py-2">
                        @if($withdrawable)
                            @can('compliance.discipline')
                            <form method="POST" action="{{ route('admin.lifetime-awards.withdraw', $milestone->id) }}" class="flex items-center gap-1"
                                  data-confirm-title="Withdraw award"
                                  data-confirm="Withdraw this Lifetime Award? It will not be paid for this month."
                                  data-confirm-impact="Impact: the award's wallet credit is reversed and the partner sees it as Withdrawn, with your reason and the route to Grievance Redressal. Use it only for a termination, a proven breach or a correction — the reason is kept on the audit log. The rank is not used up: a later month where it is the partner's highest grants the award again.">
                                @csrf
                                <input type="text" name="reason" required maxlength="500" placeholder="Reason"
                                       class="w-28 rounded border border-gray-300 px-1.5 py-0.5 text-[10px]">
                                <button type="submit"
                                        class="px-2 py-1 rounded bg-red-50 text-red-700 hover:bg-red-100 text-[10px] font-medium">
                                    Withdraw
                                </button>
                            </form>
                            @endcan
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $milestones->links() }}</div>
    @endif
</x-ui.card>

@endsection
