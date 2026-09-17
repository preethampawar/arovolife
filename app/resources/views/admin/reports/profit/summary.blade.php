@extends('admin.layouts.admin')

@section('title', $title)
@section('heading', $title)

@section('content')
    @php
        $rupees = fn (int $p) => \App\Modules\Shared\Support\IndianNumber::rupees($p);
        $pct = fn (?float $v) => $v === null ? '—' : \App\Modules\Shared\Support\IndianNumber::format($v, 1).'%';

        $tiles = [
            ['Net sales', $rupees($data['net_sales_paise']), 'Ex-GST value of goods sold, after returns.'],
            ['Cost of goods sold', $rupees($data['cogs_paise']), 'What those goods actually cost us, landed.'],
            ['Gross profit', $rupees($data['gross_profit_paise']), 'Net sales less cost of goods sold.'],
            ['Margin', $pct($data['margin_pct']), 'Gross profit as a share of net sales.'],
        ];
    @endphp

    <div class="space-y-4">
        <a href="{{ route('admin.reports.profit.index') }}"
            class="inline-flex items-center gap-1.5 text-sm text-gray-600 hover:text-gray-900">
            {{ svg('lucide-arrow-left', 'w-3.5 h-3.5', ['aria-hidden' => 'true']) }}
            All profit reports
        </a>

        @include('admin.reports.profit._how-it-works')
        @include('admin.reports.profit._filters')

        @if ($data['estimated_lines'] > 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ $data['estimated_lines'] }} of {{ $data['total_lines'] }} sold lines had no stock movement
                behind them, so their cost is an estimate rather than what was actually paid. The profit below
                is only as good as those estimates — check the Cost basis column on the register to see which.
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ($tiles as [$label, $value, $tip])
                <x-ui.card padding="p-4">
                    <span class="block text-xs uppercase tracking-wider text-gray-600">{{ $label }} <x-help-tip :text="$tip" /></span>
                    <span class="block text-lg font-bold text-gray-900 mt-1">{{ $value }}</span>
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        <x-profit-section label="Cost of goods sold" />
                        <x-profit-line label="Opening stock (at cost)" :value="$rupees($data['opening_stock_paise'])"
                            tip="Value of everything in the warehouses the moment before this period began, re-derived from the stock ledger." />
                        <x-profit-line label="Add: Purchases (taxable, ex-GST)" :value="$rupees($data['purchases_paise'])"
                            tip="Supplier line value on goods receipts posted in this period. GST excluded — it is input credit, not a cost." />
                        <x-profit-line label="Add: Freight & other charges" :value="$rupees($data['purchase_charges_paise'])"
                            tip="Freight, insurance and handling entered on those receipts. This is the difference between cost price and landing price." />
                        <x-profit-line label="Landed purchases" :value="$rupees($data['landed_purchases_paise'])" strong />
                        <x-profit-line label="Less: Closing stock (at cost)" :value="$rupees($data['closing_stock_paise'])"
                            tip="Value still on the shelf at the end of the period. Goods bought but not yet sold are not a cost." />
                        @if ($data['unvalued_qty'] !== 0)
                            <x-profit-line label="Memo: unvalued stock movements (units)" :value="(string) $data['unvalued_qty']"
                                tip="Stock that moved without a cost attached — adjustments made before costs were recorded on them. They do not affect the figures above, but they are why the stock account may not reconcile to the paise." />
                        @endif
                        <x-profit-line label="Stock consumed (opening + purchases − closing)" :value="$rupees($data['implied_consumption_paise'])"
                            tip="What the stock account says left the warehouse this period. Cost of goods sold is worked out separately, from the sales themselves — the two should differ only by stock that left for a reason other than a sale." />
                        <x-profit-line label="Less: written off, damaged, transferred or rounded" :value="$rupees($data['reconciling_difference_paise'])"
                            tip="Stock that left without being sold. Deliberately NOT charged to cost of sales: doing that would make trading look worse than it was and bury the loss where nobody would find it." />
                        <x-profit-line label="Cost of goods sold" :value="$rupees($data['cogs_paise'])" strong
                            tip="Read from the sales themselves: the actual batch cost stamped on each line when it was packed, net of returns." />

                        <x-profit-section label="Sales" />
                        <x-profit-line label="Gross sales (ex-GST)" :value="$rupees($data['gross_sales_paise'])"
                            :tip="$data['orders'].' orders counted.'" />
                        <x-profit-line label="Less: Returns & refunds" :value="$rupees($data['refunds_paise'])"
                            :tip="$data['refunded_orders'].' refunds approved in this period, whenever the original sale happened.'" />
                        <x-profit-line label="Net sales" :value="$rupees($data['net_sales_paise'])" strong />
                        <x-profit-line label="Memo: discounts given" :value="$rupees($data['discount_paise'])"
                            tip="Coupons. Shown separately rather than netted off sales, so the top line stays the documented sale value." />
                        <x-profit-line label="Memo: points redeemed" :value="$rupees($data['points_paise'])" />

                        <x-profit-section label="Profit" />
                        <x-profit-line label="Gross profit" :value="$rupees($data['gross_profit_paise'])" strong />
                        <x-profit-line label="Margin %" :value="$pct($data['margin_pct'])"
                            tip="Gross profit divided by net sales — the share of each rupee of revenue you keep." />
                        <x-profit-line label="Markup %" :value="$pct($data['markup_pct'])"
                            tip="Gross profit divided by cost — how much you add on top of what you paid." />

                        <x-profit-section label="Contribution after commission" />
                        @foreach ($data['commission_by_type'] as $type => $paise)
                            <x-profit-line :label="'Less: '.\Illuminate\Support\Str::headline(str_replace('_credit', '', $type))"
                                :value="$rupees((int) $paise)" />
                        @endforeach
                        <x-profit-line label="Total commission credited" :value="$rupees($data['commission_paise'])"
                            tip="Every rupee credited to distributor wallets under the plan in this period." />
                        <x-profit-line label="Contribution after commission" :value="$rupees($data['contribution_paise'])" strong
                            tip="Gross profit less commission. NOT net profit: courier cost, gateway fees and overheads are not captured." />
                        <x-profit-line label="Contribution %" :value="$pct($data['contribution_pct'])" />

                        <x-profit-section label="Memo" />
                        <x-profit-line label="Shipping collected" :value="$rupees($data['shipping_collected_paise'])"
                            tip="What customers were charged for delivery. What the courier charged us is not recorded anywhere, so it is not deducted above." />
                        <x-profit-line label="Collection fees collected" :value="$rupees($data['collection_fee_collected_paise'])"
                            tip="What buyers who collected from an Arete Development Centre were charged. Kept separate from shipping: a collection is not a delivery, and only one of the two is ever charged on an order." />
                        <x-profit-line label="GST output (on sales)" :value="$rupees($data['gst_output_paise'])" />
                        <x-profit-line label="GST input credit (on purchases)" :value="$rupees($data['gst_input_paise'])" />
                        <x-profit-line label="BV released" :value="\App\Modules\Shared\Support\IndianNumber::format($data['bv_paise'] / 100, 2)"
                            tip="Business Volume attached to the goods sold in this period — what the compensation engine pays on." />
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
@endsection
