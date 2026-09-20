{{--
    The explanatory panel for the accrual company snapshot. Same markup as
    _how-it-works.blade.php: the figures on this page are definitions as much
    as numbers, and the definitions belong next to them.
--}}
<details class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
    <summary class="cursor-pointer font-medium flex items-center gap-2">
        {{ svg('lucide-info', 'w-4 h-4 shrink-0', ['aria-hidden' => 'true']) }}
        How this statement is calculated
    </summary>
    <div class="mt-3 space-y-3 text-[13px] leading-relaxed">
        <p>
            <strong>One page, one period, one basis.</strong> Goods, sales and profit follow the Profit
            summary exactly and tie to it rupee for rupee on the same filters. The basis applies to
            sales; the warehouse filter narrows purchases and stock only — sales, cost of goods sold
            and BV stay company-wide, so a warehouse view will not reconcile purchases to sales. A
            wallet has no warehouse either, so every bonus, payout and balance figure below is
            company-wide.
        </p>
        <p>
            <strong>Bonus credited and bonus paid are not the same period.</strong> Bonuses are credited
            to wallets when they are earned and paid out by weekly and monthly batches later.
            “What arovolife keeps” deducts what was <em>credited</em> in this window; “Paid to
            distributors” covers the payout lines <em>built</em> in this window, and splits them by
            what the bank says about each line <em>today</em> — not by what it said on the To date, and
            not by when the money settled, which this platform does not record. A line built inside the
            window and confirmed a fortnight later therefore counts as paid here. The two blocks
            describe different weeks and will not tie to each other inside a short window. The unpaid
            difference sits in “Bonus credited but not yet paid out”.
        </p>
        <p>
            <strong>TDS is not income and the admin charge is not a cost.</strong> TDS is withheld from
            the distributor and remitted to the Income Tax Department in their name. The admin charge is
            deducted from the distributor's payable and stays with the company, so it is added back.
            Reversals and cap forfeits are bonuses taken back, so they are added back too.
        </p>
        <p>
            <strong>Repurchase deductions are owed as goods.</strong> They are held in repurchase
            wallets and can only be spent on arovolife products — a liability, shown here at sale value,
            so the cash it will eventually cost the company is lower than the figure on the page.
        </p>
        <p>
            <strong>Money left with arovolife is not net profit.</strong> Courier cost, payment gateway
            fees, salaries and rent are not captured anywhere on this platform. The balance rows at the
            bottom are as at the To date, not movements in the period.
        </p>
    </div>
</details>
