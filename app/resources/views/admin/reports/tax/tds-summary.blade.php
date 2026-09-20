@extends('admin.layouts.admin')

@section('title', $title)
@section('heading', $title)

@section('content')
    @php
        $rupees = fn (int $p) => \App\Modules\Shared\Support\IndianNumber::rupees($p);
        $count = fn (int $n) => \App\Modules\Shared\Support\IndianNumber::format($n);

        $tiles = [
            ['TDS deducted', $rupees($data['tds_paise']), 'Tax actually withheld from payouts dated in this period, read from the wallet-ledger debits.'],
            ['Deductees', $count($data['deductees']), 'Distinct distributors tax was withheld from in this period.'],
            ['Payable base', $rupees($data['payable_paise']), 'Gross bonus swept, less the repurchase deduction and the admin charge, on the lines tax was withheld from.'],
            ['Lines', $count($data['lines']), 'Payout lines that carry a TDS debit in this period.'],
        ];
    @endphp

    <div class="space-y-4">
        <a href="{{ route('admin.reports.profit.index') }}"
            class="inline-flex items-center gap-1.5 text-sm text-gray-600 hover:text-gray-900">
            {{ svg('lucide-arrow-left', 'w-3.5 h-3.5', ['aria-hidden' => 'true']) }}
            All profit reports
        </a>

        @include('admin.reports.tax._how-tds-works')
        @include('admin.reports.tax._filters')

        @if ($data['lines'] === 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                No tax was withheld in this period — no payout batch dated inside it carries a TDS debit.
            </div>
        @endif

        @if ($data['line_tds_mismatch'] > 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ $count($data['line_tds_mismatch']) }} payout lines carry a TDS figure that disagrees with the
                ledger debit behind it. This should read zero — a rebuild deletes the lines and their debits
                together — so a non-zero means a line or ledger row was edited outside the payout engine. Tell
                the platform team. The figures below follow the ledger; check those lines on the register before
                filing.
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ($tiles as [$label, $value, $tip])
                <x-ui.card padding="p-4">
                    <span class="block text-xs uppercase tracking-wider text-gray-600">{{ $label }} <x-help-tip :text="$tip" /></span>
                    <span class="block text-lg font-bold mt-1 text-gray-900">{{ $value }}</span>
                </x-ui.card>
            @endforeach
        </div>

        <p class="text-sm text-gray-600">
            The deductee-wise detail behind these totals — ADN, masked PAN, payable and tax per line — is on the
            <a href="{{ route('admin.reports.profit.tds-register', request()->query()) }}"
                class="text-brand-600 hover:text-brand-700 font-medium">TDS register →</a>
        </p>

        <x-ui.card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        <x-profit-section label="Tax deducted at source" />
                        @foreach ($data['by_batch_type'] as $group)
                            <x-profit-line :label="'Deducted on '.\Illuminate\Support\Str::headline($group['batch_type']).' batches'"
                                :value="$rupees($group['tds_paise'])" tone="liability"
                                :tip="$count($group['batches']).' batches, '.$count($group['lines']).' lines.'"
                                note="Withheld from distributor payouts and paid to the Income Tax Department in their name — not arovolife's income, a remittance liability until paid over." />
                            <x-profit-line :label="'Memo: payable base on those lines'" :value="$rupees($group['payable_paise'])" tone="muted"
                                note="Memo only, and not a tax figure. Gross less the repurchase deduction and the admin charge on the same lines — what the tax was computed off, before the monthly narrowing described above." />
                        @endforeach
                        <x-profit-line label="Total TDS deducted" :value="$rupees($data['tds_paise'])" strong tone="liability"
                            note="Owed to the government. Everything withheld from payouts dated in this period, across every batch type — the figure a Form 26Q return for this quarter is built from." />
                        <x-profit-line label="Payable base" :value="$rupees($data['payable_paise'])" tone="muted"
                            note="Memo only. The distributors' money before tax: gross bonus swept, less what was held back for goods and the admin charge arovolife kept." />
                        <x-profit-line label="Net paid to distributors" :value="$rupees($data['net_paise'])" tone="muted"
                            note="Memo only. What was left to transfer after this tax came off — gone to distributors, never arovolife's money." />

                        <x-profit-section label="By line status" />
                        @foreach ($data['by_status'] as $group)
                            <x-profit-line :label="\Illuminate\Support\Str::headline($group['status'])"
                                :value="$rupees($group['tds_paise'])"
                                :tip="$count($group['lines']).' lines.'"
                                note="Part of the total above. The status is the payout line's, not the tax's: the tax was withheld either way, and a line still pending or failed at the bank does not give it back." />
                        @endforeach

                        <x-profit-section label="Memo" />
                        <x-profit-line label="Below-minimum lines — computed, not deducted"
                            :value="$count($data['below_minimum_lines'])" tone="muted"
                            note="Memo only, and not in any total above. Lines too small to pay out: a TDS figure was computed and discarded with them, and no wallet was ever debited." />
                        <x-profit-line label="Below-minimum TDS — computed, not deducted"
                            :value="$rupees($data['below_minimum_tds_paise'])" tone="muted"
                            note="Memo only, and not owed to anyone. The tax those discarded lines would have carried. Nothing was withheld, so nothing is remittable." />
                        <x-profit-line label="Held lines at the latest batch of each type"
                            :value="$count($data['held_lines'])" tone="muted"
                            note="Memo only. Lines the latest weekly and monthly batch could not pay — KYC pending, no bank account, web-only, or bank details that would not decrypt. No tax was withheld on them; the income stays in the wallet until the block clears." />
                        <x-profit-line label="Lines where the line figure and the ledger debit disagree"
                            :value="$count($data['line_tds_mismatch'])"
                            :tone="$data['line_tds_mismatch'] > 0 ? 'liability' : 'muted'"
                            note="Memo only, and it should read zero. A payout line whose stored TDS no longer matches the debit behind it. A rebuild deletes the line and its debit together, so a non-zero means a line or ledger row was edited outside the payout engine — tell the platform team. Every figure above follows the ledger." />
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
@endsection
