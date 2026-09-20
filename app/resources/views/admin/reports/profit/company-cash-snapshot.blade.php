@extends('admin.layouts.admin')

@section('title', $title)
@section('heading', $title)

@section('content')
    @php
        $rupees = fn (int $p) => \App\Modules\Shared\Support\IndianNumber::rupees($p);
        $pct = fn (?float $v) => $v === null ? '—' : \App\Modules\Shared\Support\IndianNumber::format($v, 1).'%';
        $signed = fn (int $p) => ($p < 0 ? '−' : '').$rupees(abs($p));

        $noBatch = $data['payout_lines'] === 0;
        $dash = fn (int $p) => $noBatch ? '—' : $rupees($p);
        $nothingHappened = $data['orders'] === 0
            && $data['refunded_orders'] === 0
            && $data['landed_purchases_paise'] === 0
            && $data['commission_paise'] === 0
            && $noBatch;

        $tiles = [
            ['Net sales', $rupees($data['net_sales_paise']), 'Ex-GST value of goods sold, after returns.', false],
            ['Gross profit', $signed($data['gross_profit_paise']), 'Net sales less cost of goods sold.', $data['gross_profit_paise'] < 0],
            ['Money actually left', $signed($data['cash_money_left_paise']), 'Gross profit less the payout lines built in this period that the bank has since confirmed — status as of now, not as at the To date. Bonuses credited but not yet transferred are not deducted here.', $data['cash_money_left_paise'] < 0],
            ['Free after commitments', $signed($data['cash_free_after_commitments_paise']), 'What is left once TDS still to remit, payouts in flight, unpaid wallet balances and repurchase wallets have all gone.', $data['cash_free_after_commitments_paise'] < 0],
        ];
    @endphp

    <div class="space-y-4">
        <a href="{{ route('admin.reports.profit.index') }}"
            class="inline-flex items-center gap-1.5 text-sm text-gray-600 hover:text-gray-900">
            {{ svg('lucide-arrow-left', 'w-3.5 h-3.5', ['aria-hidden' => 'true']) }}
            All profit reports
        </a>

        @include('admin.reports.profit._how-cash-snapshot-works')
        @include('admin.reports.profit._filters')

        @if ($warehouseCode !== '')
            <p class="-mt-2 text-xs text-gray-500">Warehouse filter: purchases and stock only.</p>
        @endif

        @if ($data['negative_balances_notice'])
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Wallet balances are negative for this date, which cannot happen in normal operation — on a
                test environment it is the trace of a recompute run under live traffic. The raw figure is
                shown; the cash page treats it as nothing owed.
            </div>
        @endif

        @if ($nothingHappened)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Nothing was sold, bought or paid out in this period.
            </div>
        @elseif ($noBatch)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                No payout batch was built in this period, so nothing was confirmed paid to a distributor
                bank account; what is owed still appears under commitments below.
            </div>
        @endif

        @if ($data['estimated_lines'] > 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ $data['estimated_lines'] }} of {{ $data['total_lines'] }} sold lines had no stock movement
                behind them, so their cost is an estimate rather than what was actually paid. The profit below
                is only as good as those estimates — check the Cost basis column on the register to see which.
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ($tiles as [$label, $value, $tip, $negative])
                <x-ui.card padding="p-4">
                    <span class="block text-xs uppercase tracking-wider text-gray-600">{{ $label }} <x-help-tip :text="$tip" /></span>
                    <span class="block text-lg font-bold mt-1 {{ $negative ? 'text-red-600' : 'text-gray-900' }}">{{ $value }}</span>
                </x-ui.card>
            @endforeach
        </div>

        <p class="text-sm text-gray-600">
            This page counts a bonus only once the bank has confirmed the transfer, on the lines this period built.
            <a href="{{ route('admin.reports.profit.company-snapshot', request()->query()) }}"
                class="text-brand-600 hover:text-brand-700 font-medium">See the accrual footing →</a>
        </p>

        <x-ui.card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @include('admin.reports.profit._goods-and-sales', ['rupees' => $rupees, 'signed' => $signed])

                        <x-profit-section label="Paid to distributors (bank-confirmed)" />
                        <x-profit-line label="Net paid to distributors" :value="$dash($data['net_transferred_paise'])" strong
                            :tip="$data['transferred_lines'].' of '.$data['payout_lines'].' payout lines transferred.'"
                            note="Gone to distributors. Payout lines built in this period that the bank has since confirmed — the status is as of now, not as at the To date. After repurchase deduction, admin charge and TDS." />
                        <x-profit-line label="Memo: Gross swept into those batches" :value="$dash($data['gross_swept_paise'])" tone="muted"
                            note="Memo only. Bonus swept into the payout batches built in this window, before any deduction." />
                        <x-profit-line label="Memo: Repurchase deduction held for goods" :value="$dash($data['repurchase_swept_paise'])" tone="muted"
                            :note="'Memo only, and owed to distributors as goods. '.$rates['repurchase_rate'].' of each bonus, capped '.$rates['repurchase_cap'].' a calendar month, held in the repurchase wallet and spendable only on arovolife products.'" />
                        <x-profit-line label="Memo: Admin charge retained by arovolife" :value="$dash($data['admin_charge_paise'])" tone="muted"
                            :note="'Memo only, and arovolife\'s money. '.$rates['admin_rate'].' of gross, '.$rates['admin_cap_note'].'. Deducted from the distributor and never paid out, so it never left the company.'" />
                        <x-profit-line label="Memo: TDS deducted on those batches" :value="$dash($data['tds_paise'])" tone="muted"
                            note="Memo only, and owed to the government. Withheld from the distributor and remitted to the Income Tax Department in their name. It is subtracted once, under commitments below." />

                        <x-profit-section label="What actually left arovolife" />
                        <x-profit-line label="Gross profit" :value="$signed($data['gross_profit_paise'])"
                            note="arovolife's money, brought down from Sales." />
                        <x-profit-line label="Less: Net paid to distributors' banks" :value="$dash($data['net_transferred_paise'])"
                            note="Gone to distributors. The only bonus figure this page deducts — lines this period built that a bank has since confirmed leaving." />
                        <x-profit-line label="Money actually left with arovolife" :value="$signed($data['cash_money_left_paise'])" strong
                            note="Gross profit less what the bank has confirmed on the lines this period built. Cash footing: bonuses credited but not yet transferred are NOT deducted here — they appear below as commitments. Before courier, gateway fees, salaries and rent, which the platform does not record." />
                        <x-profit-line label="Memo: Money actually left as % of net sales" :value="$pct($data['cash_money_left_pct'])" tone="muted"
                            note="Memo only. Money actually left with arovolife divided by net sales." />

                        <x-profit-section label="Still committed to others (will leave)" />
                        <x-profit-line label="Less: TDS to remit" :value="$rupees($data['tds_paise'])" tone="liability"
                            note="Owed to the government. Withheld from distributors and payable to the Income Tax Department in their name. Not arovolife's money at any point." />
                        <x-profit-line label="Less: Payouts in flight (pending / failed)" :value="$rupees($data['net_in_flight_paise'])" tone="liability"
                            note="Owed to distributors. Built and deducted from the wallet, but not confirmed by the bank as of now. Retried, never re-credited." />
                        <x-profit-line label="Less: Bonus credited but not yet paid out" :value="$signed($data['wallet_balances_paise'])" tone="liability"
                            note="Owed to distributors. Main-wallet balances across all distributors as at the To date — earned and credited, waiting for a batch to pay them." />
                        <x-profit-line label="Less: Repurchase-wallet balances outstanding" :value="$signed($data['repurchase_balances_paise'])" tone="liability"
                            note="Owed to distributors, as goods. Repurchase-wallet balances as at the To date, spendable only on arovolife products — so the cash this will cost is lower than the figure shown." />
                        <x-profit-line label="Free after commitments" :value="$signed($data['cash_free_after_commitments_paise'])" strong
                            note="What is left once every rupee already owed to distributors and the tax department has gone. Balances are as at the To date, not period movements; repurchase balances are owed as goods, so the cash cost is lower than shown." />

                        <x-profit-section label="GST" />
                        <x-profit-line label="GST collected on sales" :value="$rupees($data['gst_output_paise'])"
                            note="Owed to the government. Output GST on the sales counted above, collected from customers on the government's behalf. Never income." />
                        <x-profit-line label="Less: GST paid on purchases (input credit)" :value="$rupees($data['gst_input_paise'])"
                            note="Reclaimable from the government. Input GST on the goods receipts counted above, set off against the output GST." />
                        <x-profit-line label="Net GST payable" :value="$signed($data['gst_net_payable_paise'])" strong tone="liability"
                            note="Owed to the government. Output less input for this period, before any other adjustment on the return. A negative figure is input credit carried forward, not money coming back." />

                        <x-profit-section label="Memo" />
                        <x-profit-line label="Memo: Shipping collected" :value="$rupees($data['shipping_collected_paise'])" tone="muted"
                            note="Memo only. What customers were charged for delivery. What the courier charged arovolife is not recorded anywhere, so it is not deducted above." />
                        <x-profit-line label="Memo: Collection fees collected" :value="$rupees($data['collection_fee_collected_paise'])" tone="muted"
                            note="Memo only. What buyers collecting from an Arete Development Centre were charged. Only one of shipping and collection is ever charged on an order." />
                        <x-profit-line label="Memo: Discounts given" :value="$rupees($data['discount_paise'])" tone="muted"
                            note="Memo only. Coupon value on the counted sales, shown separately rather than netted off so the top line stays the documented sale value." />
                        <x-profit-line label="Memo: Points redeemed" :value="$rupees($data['points_paise'])" tone="muted"
                            note="Memo only. Loyalty points customers spent on the counted sales." />
                        <x-profit-line label="Memo: Sales settled with repurchase credit" :value="$rupees($data['repurchase_spent_on_orders_paise'])" tone="muted"
                            note="Memo only. The part of the sales above that was paid for out of repurchase wallets rather than fresh money — a liability being worked off, not new income." />
                        <x-profit-line label="Memo: BV released" :value="\App\Modules\Shared\Support\IndianNumber::format($data['bv_paise'] / 100, 2)" tone="muted"
                            note="Memo only, and not money. Business Volume attached to the goods sold in this period — what the compensation engine pays on." />
                        <x-profit-line label="Memo: Refunded orders" :value="(string) $data['refunded_orders']" tone="muted"
                            note="Memo only. How many orders were refunded in this period, whenever they were originally sold." />
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
@endsection
