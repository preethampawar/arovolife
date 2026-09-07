@extends('admin.layouts.admin')
@section('title', 'Weekly Payouts')
@section('heading', 'Weekly Payouts')

@section('content')

@developer
<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    The earning week runs Wednesday to Tuesday and is paid the Tuesday one week after it closes: the batch dated Tuesday 18 August pays income earned from Wednesday 5 August to Tuesday 11 August. "Earnings through" is the last day each batch pays for; anything earned after it waits for the next Tuesday. The week is keyed on the day the income was earned — the GSB cut-off date, the mentorship cut-off day — not on when the credit reached the wallet. Each batch shows total gross, deductions (repurchase + admin charge + TDS), and net transferred. Minimum payout is ₹{{ $minPayout }} — below-minimum balances roll over to the next week. Use <a href="{{ route('admin.compensation.manual-controls.index') }}" class="underline">Manual Controls → Force Payout</a> only if the automated batch failed for a specific distributor.
</div>
@enddeveloper

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($batches->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-600 text-center">No payout batches yet — weekly payout not yet active.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-600 w-12">S.No.</th>
                    <th class="px-4 py-2 text-left text-gray-600">Batch date</th>
                    <th class="px-4 py-2 text-left text-gray-600">
                        Earnings through <x-help-tip text="The last day of the Wednesday-to-Tuesday earning week this batch pays — the Tuesday one week before the batch date. Income earned after it is paid by the next Tuesday's batch. Each batch records this when it is created; batches from before the rule was deployed, and legacy GSB-weekly batches, swept the whole wallet balance instead and show a dash." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-600">
                        Distributors <x-help-tip text="Distributors being paid in this batch (net ≥ ₹{{ $minPayout }}). 'Held' counts those whose income stays in the wallet — KYC pending, no bank account, web-only or bank details unreadable." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-600">
                        Total gross <x-help-tip text="Sum of bonus credits across every line in the batch, held lines included, before deductions." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-600">
                        Deductions <x-help-tip text="Repurchase deduction (10% per credit, monthly cap ₹10,000, already moved to the repurchase wallet at credit time) + admin charge (3%) + TDS (5%). Held lines carry only the repurchase part." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-600">Net transferred</th>
                    <th class="px-4 py-2 text-center text-gray-600">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($batches as $b)
                @php
                    $bc = [
                        'completed'  => 'bg-green-100 text-green-700',
                        'failed'     => 'bg-red-100 text-red-700',
                        'partially_failed' => 'bg-red-100 text-red-700',
                        'processing' => 'bg-amber-100 text-amber-700',
                        'pending'    => 'bg-gray-100 text-gray-600',
                    ];
                @endphp
                <tr>
                    <td class="px-4 py-2 text-gray-500 tabular-nums">{{ $batches->firstItem() + $loop->index }}</td>
                    <td class="px-4 py-2 font-medium">{{ $b->batch_date->format('d M Y') }} ({{ $b->batch_date->format('D') }})</td>
                    <td class="px-4 py-2 text-gray-600">{{ $b->weeklyEarningThrough()?->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-2 text-right">
                        {{ \App\Modules\Shared\Support\IndianNumber::format($b->distributor_count) }}
                        @if($b->held_count > 0)
                        <span class="text-gray-500">· {{ \App\Modules\Shared\Support\IndianNumber::format($b->held_count) }} held</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format($b->total_gross_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($b->total_deductions_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($b->total_net_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $bc[$b->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst(str_replace('_', ' ', $b->status)) }}
                        </span>
                    </td>
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.compensation.weekly-payouts.show', $b) }}"
                           class="text-brand-700 text-xs hover:underline">View →</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $batches->links() }}</div>
    @endif
</div>

@endsection
