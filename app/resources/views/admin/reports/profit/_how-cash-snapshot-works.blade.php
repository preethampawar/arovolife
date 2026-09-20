{{--
    The explanatory panel for the cash-footing company snapshot. It exists
    only to keep the reader from comparing this page's bottom line with the
    accrual page's and concluding one of them is wrong.
--}}
<details class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
    <summary class="cursor-pointer font-medium flex items-center gap-2">
        {{ svg('lucide-info', 'w-4 h-4 shrink-0', ['aria-hidden' => 'true']) }}
        How this statement is calculated
    </summary>
    <div class="mt-3 space-y-3 text-[13px] leading-relaxed">
        <p>
            <strong>This page counts money only where the bank has confirmed it.</strong> Goods, sales
            and gross profit are identical to the Company snapshot and to the Profit summary. Below
            gross profit the two pages part company: this one deducts only the lines a bank has
            confirmed, so it answers “what has actually left arovolife”, not “what has been promised”.
            The period is the date each payout line was <em>built</em> — there is no settlement date on
            a line — and whether it counts as confirmed is the bank's answer <em>as of today</em>, not
            as at the To date. A line built in this window and confirmed later still counts. The basis
            applies to sales; the warehouse filter narrows purchases and stock only — sales, cost of
            goods sold and BV stay company-wide, so a warehouse view will not reconcile purchases to
            sales.
        </p>
        <p>
            <strong>Everything promised but unpaid is listed as a commitment.</strong> Bonuses credited
            to wallets, payouts built but still pending or failed, repurchase-wallet balances and TDS
            still to remit are all money that will leave. They are subtracted once, together, to give
            “Free after commitments”. Balances are as at the To date, not movements in the period, so
            this block will not tie to a single week or month.
        </p>
        <p>
            <strong>Use the accrual page to judge the plan, this one to judge the bank balance.</strong>
            The <a href="{{ route('admin.reports.profit.company-snapshot') }}" class="underline">Company
            snapshot</a> deducts every bonus the moment it is credited, which is the honest measure of
            what the compensation plan costs on this period's sales. Neither page is net profit: courier
            cost, gateway fees, salaries and rent are not recorded on this platform.
        </p>
    </div>
</details>
