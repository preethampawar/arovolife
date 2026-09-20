@extends('admin.layouts.admin')

@section('title', $title)
@section('heading', $title)

@section('content')
    @php
        $rupees = fn (int $p) => \App\Modules\Shared\Support\IndianNumber::rupees($p);
        $pct = fn (?float $v) => $v === null ? '—' : \App\Modules\Shared\Support\IndianNumber::format($v, 1).'%';
        // A statement line that can legitimately go negative — gross profit, what
        // is left, net GST — must read as a negative rather than silently lose
        // its sign to the currency formatter.
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
            ['Net paid to distributors', $rupees($data['net_transferred_paise']), 'Payout lines built in this period that the bank has since confirmed — status as of now, not as at the To date. After repurchase, admin charge and TDS.', false],
            ['Money left with arovolife', $signed($data['money_left_paise']), 'Gross profit less plan bonuses, plus what the company took back: reversals, cap forfeits and the admin charge. Before courier, gateway fees and overheads.', $data['money_left_paise'] < 0],
        ];
    @endphp

    <div class="space-y-4">
        <a href="{{ route('admin.reports.profit.index') }}"
            class="inline-flex items-center gap-1.5 text-sm text-gray-600 hover:text-gray-900">
            {{ svg('lucide-arrow-left', 'w-3.5 h-3.5', ['aria-hidden' => 'true']) }}
            All profit reports
        </a>

        @include('admin.reports.profit._how-snapshot-works')
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
                No payout batch was built in this period, so the Paid to distributors section is empty;
                bonus credited is still shown.
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
            This page counts a bonus the moment it is credited to a wallet.
            <a href="{{ route('admin.reports.profit.company-cash-snapshot', request()->query()) }}"
                class="text-brand-600 hover:text-brand-700 font-medium">See the cash footing →</a>
        </p>

        <x-ui.card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @include('admin.reports.profit._goods-and-sales', ['rupees' => $rupees, 'signed' => $signed])

                        <x-profit-section label="Paid to distributors" />
                        <x-profit-line label="Gross swept into payout batches" :value="$dash($data['gross_swept_paise'])" tone="muted"
                            note="Memo only, and a different period from the bonus figure below: bonus swept into the payout batches BUILT in this window, before any deduction." />
                        <x-profit-line label="Less: Repurchase deduction (held for goods)" :value="$dash($data['repurchase_swept_paise'])" tone="liability"
                            :note="'Owed to distributors, as goods. '.$rates['repurchase_rate'].' of each bonus, capped '.$rates['repurchase_cap'].' a calendar month, held in the distributor\'s repurchase wallet and spendable only on arovolife products.'" />
                        <x-profit-line label="Less: Admin charge retained by arovolife" :value="$dash($data['admin_charge_paise'])"
                            :note="'arovolife\'s money. '.$rates['admin_rate'].' of gross, '.$rates['admin_cap_note'].', read from the wallet-ledger debits. Deducted from the distributor and kept by the company.'" />
                        <x-profit-line label="Less: TDS deducted, to be remitted" :value="$dash($data['tds_paise'])" tone="liability"
                            :note="'Owed to the government. '.$rates['tds_rate'].' of payable, withheld and paid to the Income Tax Department in the distributor\'s name. Not arovolife\'s income — a remittance liability until paid over.'" />
                        <x-profit-line label="Net paid to distributors" :value="$dash($data['net_transferred_paise'])" strong
                            :tip="$data['transferred_lines'].' of '.$data['payout_lines'].' payout lines transferred.'"
                            note="Gone to distributors. Payout lines built in this period that the bank has since confirmed — the status is as of now, not as at the To date, so a line built here and confirmed later still counts." />
                        <x-profit-line label="Built, not yet in the bank — pending / failed" :value="$dash($data['net_in_flight_paise'])" tone="muted"
                            note="Owed to distributors. Lines built this period that are still pending or failed at the bank as of now; they are retried, never re-credited to the wallet." />
                        <x-profit-line label="Memo: Repurchase share withheld at credit time this period" :value="$rupees($data['repurchase_withheld_paise'])" tone="muted"
                            note="Memo only, and a different period from the batch rows above: moved to repurchase wallets when the bonuses were credited. The distributor's, spendable on products." />

                        <x-profit-section label="What arovolife keeps" />
                        <x-profit-line label="Gross profit" :value="$signed($data['gross_profit_paise'])"
                            note="arovolife's money, brought down from Sales." />
                        <x-profit-line label="Less: Bonus credited under the plan" :value="$rupees($data['commission_paise'])"
                            note="A cost, and owed to distributors. Every rupee credited to distributor wallets under the plan in this period, on the sales above. Deducted here in full: paid out or not, it is owed." />
                        @foreach ($data['commission_by_type'] as $type => $paise)
                            <x-profit-line :label="'Memo: '.\Illuminate\Support\Str::headline(str_replace('_credit', '', (string) $type))"
                                :value="$rupees((int) $paise)" tone="muted"
                                note="Memo only. Part of the bonus credited above, broken out by plan bonus — not an extra cost." />
                        @endforeach
                        <x-profit-line label="Add back: Reversed by admin" :value="$rupees($data['reversed_paise'])"
                            note="Back to arovolife. Bonus credits an admin later unwound. Taken back out of the wallet, so no longer a cost." />
                        <x-profit-line label="Add back: Forfeited under income caps" :value="$rupees($data['forfeited_paise'])"
                            note="Back to arovolife. Bonus the rank and monthly caps forfeited at payout time. Never paid, so no longer a cost." />
                        <x-profit-line label="Add: Admin charge retained" :value="$rupees($data['admin_charge_paise'])"
                            :note="'arovolife\'s money. The '.$rates['admin_rate'].' retained on batches built this period. Deducted from the distributor but it never left the company, so it is added back.'" />
                        <x-profit-line label="Money left with arovolife" :value="$signed($data['money_left_paise'])" strong
                            note="arovolife's money. Gross profit less plan bonuses, plus what the company took back. NOT net profit: courier cost, gateway fees, salaries and rent are not captured anywhere on this platform." />
                        <x-profit-line label="Memo: Money left as % of net sales" :value="$pct($data['money_left_pct'])" tone="muted"
                            note="Memo only. Money left with arovolife divided by net sales." />

                        <x-profit-section label="Owed to others (not arovolife's money)" />
                        <x-profit-line label="GST collected on sales" :value="$rupees($data['gst_output_paise'])"
                            note="Owed to the government. Output GST on the sales counted above, collected from customers on the government's behalf. Never income." />
                        <x-profit-line label="Less: GST paid on purchases (input credit)" :value="$rupees($data['gst_input_paise'])"
                            note="Reclaimable from the government. Input GST on the goods receipts counted above, set off against the output GST." />
                        <x-profit-line label="Net GST payable" :value="$signed($data['gst_net_payable_paise'])" strong tone="liability"
                            note="Owed to the government. Output less input for this period, before any other adjustment on the return. A negative figure is input credit carried forward, not money coming back." />
                        <x-profit-line label="TDS deducted, to be remitted" :value="$rupees($data['tds_paise'])" tone="liability"
                            note="Owed to the government. The same figure as above, listed again so every liability sits in one place." />
                        <x-profit-line label="Bonus credited but not yet paid out" :value="$signed($data['wallet_balances_paise'])" tone="liability"
                            note="Owed to distributors. Main-wallet balances across all distributors as at the To date — credited but not yet swept into a paid batch. A balance, not a movement in this period." />
                        <x-profit-line label="Repurchase-wallet balances outstanding" :value="$signed($data['repurchase_balances_paise'])" tone="liability"
                            note="Owed to distributors, as goods. Repurchase-wallet balances across all distributors as at the To date. Spendable only on arovolife products, so the cash cost is lower than the figure shown." />
                        <x-profit-line label="Payouts in flight (pending / failed)" :value="$rupees($data['net_in_flight_paise'])" tone="liability"
                            note="Owed to distributors. Built and deducted, but not confirmed by the bank as of now." />
                        <x-profit-line label="Memo: Bonus held at the latest payout" :value="$signed($data['held_paise'])" tone="muted"
                            :tip="$data['held_distributors'].' distributors held at the latest weekly and monthly batch. Latest batch of each type only, never summed across batches.'"
                            note="Bonus the latest payout could not pay out — KYC pending, no bank account, web-only, or bank details that would not decrypt. Already included in the wallet balances above — shown so you can see how much of that is blocked on KYC or bank details, not an extra liability." />

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
