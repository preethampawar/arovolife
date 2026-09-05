@extends('admin.layouts.admin')
@section('title', 'Monthly Payouts')
@section('heading', 'Monthly Payouts')

@section('content')

@developer
<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Monthly batches run on the 8th of each month covering GBB, Rank Bonus, Fortune Bonus, Lifetime Awards (cash), and ADC Bonus. Each batch shows total gross, deductions (admin charge 3% + TDS 5%), and net transferred. Minimum payout is ₹{{ $minPayout }} — below-minimum wallets roll over.
</div>
@enddeveloper

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($batches->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-600 text-center">No monthly payout batches yet.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-600">Batch date</th>
                    <th class="px-4 py-2 text-right text-gray-600">
                        Distributors <x-help-tip text="Number of distributors included in this payout batch (wallet ≥ ₹{{ $minPayout }})." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-600">
                        Total gross <x-help-tip text="Sum of all wallet balances included in the batch before deductions." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-600">
                        Deductions <x-help-tip text="Admin charge (3% of gross, capped ₹25,000 per group) + TDS (5% of payable)." />
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
                        'completed'        => 'bg-green-100 text-green-700',
                        'failed'           => 'bg-red-100 text-red-700',
                        'partially_failed' => 'bg-red-100 text-red-700',
                        'processing'       => 'bg-amber-100 text-amber-700',
                        'pending'          => 'bg-gray-100 text-gray-600',
                    ];
                @endphp
                <tr>
                    <td class="px-4 py-2 font-medium">{{ $b->batch_date->format('d M Y') }} ({{ $b->batch_date->format('D') }})</td>
                    <td class="px-4 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::format($b->distributor_count) }}</td>
                    <td class="px-4 py-2 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format($b->total_gross_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($b->total_deductions_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($b->total_net_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $bc[$b->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst(str_replace('_', ' ', $b->status)) }}
                        </span>
                    </td>
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.compensation.monthly-payouts.show', $b) }}"
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
