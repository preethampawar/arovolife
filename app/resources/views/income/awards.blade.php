@extends('layouts.app')
@section('title', 'My Income — Awards & Rewards')

@section('content')
<div>
    <h1 class="text-2xl font-bold text-gray-900 mb-2">Awards &amp; Rewards</h1>

    @include('income._tabs')

    {{-- Page note: what an award is, in plan terms. Historical facts only — no award still to come is shown. --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        A Lifetime Award is paid once for each rank, the first time you achieve it. It is recorded on the 1st of the following month and paid to your bank account with the monthly payout on the 8th. TDS is deducted; no admin charge and no repurchase deduction apply.
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-sky-100 rounded-2xl border border-sky-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">Awards earned</p>
            <p class="text-2xl font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($counts['earned']) }}</p>
        </div>
        <div class="bg-amber-100 rounded-2xl border border-amber-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">To be paid</p>
            <p class="text-2xl font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($counts['to_be_paid']) }}</p>
        </div>
        <div class="bg-green-100 rounded-2xl border border-green-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">Paid</p>
            <p class="text-2xl font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($counts['paid']) }}</p>
        </div>
    </div>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-600 font-medium">No awards recorded.</p>
            <p class="text-sm text-gray-600 mt-1">An award is recorded here on the 1st of the month after the month in which you first achieve a rank.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[720px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600 w-12">S.No.</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">
                            Earned for
                            <x-help-tip text="The month in which you first achieved the rank." />
                        </th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Rank</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Award</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            Award amount
                            <x-help-tip text="The award amount for this rank when you earned it. TDS is deducted when it is paid." />
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">
                            Status
                            <x-help-tip text="Withdrawn: the reason is shown below. If you believe this is wrong, raise it through Grievance Redressal." />
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    @php
                        $state = $states[$row->id] ?? ['key' => 'to_be_paid', 'date' => null];
                        $on = fn ($date) => $date ? ' on '.$date->timezone('Asia/Kolkata')->format('j M Y') : '';
                        [$statusLabel, $statusClass] = match ($state['key']) {
                            'paid' => ['Paid'.$on($state['date']), 'bg-green-100 text-green-700'],
                            'delivered' => ['Handed over'.$on($state['date']).' (merchandise)', 'bg-green-100 text-green-700'],
                            'withdrawn' => ['Withdrawn', 'bg-gray-100 text-gray-600'],
                            'held:kyc_pending' => ['On hold — complete your KYC and bank details', 'bg-red-50 text-red-700'],
                            'held:no_bank_account' => ['On hold — add your bank details', 'bg-red-50 text-red-700'],
                            'held:bank_decrypt_failed' => ['On hold — enter your bank details again', 'bg-red-50 text-red-700'],
                            'held:web_only' => ['Held: the Compensation Plan pays to a bank account once your own purchases reach '.\App\Modules\Shared\Support\IndianNumber::format($neftMinBvPaise / 100, 0).' BV (§8)', 'bg-red-50 text-red-700'],
                            'held:award_requalification_check' => ['On hold — your rank achievement for this month is being checked again', 'bg-red-50 text-red-700'],
                            'held:account_inactive' => ['On hold — your account is not active', 'bg-red-50 text-red-700'],
                            default => str_starts_with($state['key'], 'held:')
                                ? ['On hold', 'bg-red-50 text-red-700']
                                : ['To be paid with the payout on the 8th', 'bg-amber-100 text-amber-700'],
                        };
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-500 tabular-nums">{{ $rows->firstItem() + $loop->index }}</td>
                        <td class="px-4 py-3 font-medium text-gray-800">{{ ($row->achieved_month ?? $row->triggered_month)->format('F Y') }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $row->rank_number }} · {{ $rankNames[$row->rank_number] ?? 'Rank '.$row->rank_number }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $row->award_description }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->amount_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
                            @if($state['key'] === 'withdrawn' && $row->notes)
                                <p class="mt-1 text-xs text-gray-600">Reason: {{ $row->notes }}</p>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(method_exists($rows, 'links'))
            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
        <p class="mt-4 text-xs text-gray-600">If you believe an award shown here is wrong, raise it through <a href="{{ url('/p/grievance') }}" class="text-brand-700 underline">Grievance Redressal</a>.</p>
    @endif
</div>
@endsection
