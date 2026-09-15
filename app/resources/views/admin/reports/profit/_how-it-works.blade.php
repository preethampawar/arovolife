{{--
    The explanatory panel. Every number on these screens is a definition as
    much as a figure, and the definitions are not the obvious ones — so they
    are written down next to the numbers rather than left in a plan document
    nobody reading the report will open.
--}}
<details class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
    <summary class="cursor-pointer font-medium flex items-center gap-2">
        {{ svg('lucide-info', 'w-4 h-4 shrink-0', ['aria-hidden' => 'true']) }}
        How this report is calculated
    </summary>
    <div class="mt-3 space-y-3 text-[13px] leading-relaxed">
        <p>
            <strong>Purchases are not cost of goods sold.</strong> Buying 500 units this month and
            selling 20 of them is not a loss — the other 480 are still yours, sitting in a warehouse.
            So the summary runs the numbers the way a trading account does:
            <em>opening stock + purchases − closing stock = cost of goods sold</em>, and only then
            <em>net sales − cost of goods sold = gross profit</em>. That is what ties the purchases
            figure you asked for to the profit figure you asked for.
        </p>
        <p>
            <strong>Every figure is ex-GST, on both sides.</strong> Sale prices in this platform include
            GST and supplier prices exclude it, so comparing them raw would inflate the margin by the
            tax. Sales are shown net of the GST taken out of them; purchases exclude GST because it is
            input credit you reclaim, not a cost of the goods. GST in and out is shown as a memo instead.
        </p>
        <p>
            <strong>Cost means landed cost.</strong> Not what the supplier charged — what the goods
            actually cost to get onto your shelf, including the freight, insurance and handling entered
            on the goods receipt. Those charges are spread across the lines of that receipt, become the
            batch cost, and are stamped onto each sale when it is packed. That stamp is frozen at the
            moment of sale: editing a product later cannot rewrite a past month's profit.
        </p>
        <p>
            <strong>The “Cost basis” column is not decoration.</strong> A few sales genuinely have no
            stock movement behind them — an untracked product, or an order that shipped while the
            inventory feature was off. Rather than show those at zero cost and a flattering 100% margin,
            the report falls back to the product's landing price, then its cost price, and tells you
            which it used. Treat anything not marked <em>Actual</em> as an estimate.
        </p>
        <p>
            <strong>Contribution is not net profit.</strong> Commission paid out under the plan is
            deducted because in this business it is the largest cost after the goods themselves. What is
            <em>not</em> deducted: what you pay the courier (only what you charge the customer is
            recorded), payment gateway fees (not captured — see your Razorpay settlement reports), and
            any overhead, salary or rent.
        </p>
        <p>
            <strong>This will not tie to the Analytics screen.</strong> That page sums what customers
            paid — GST included, shipping included, after wallet credit. Useful for cash, wrong for
            revenue. This report uses the ex-GST value of the goods, which is what the accounting ledger
            recognises as a sale.
        </p>
    </div>
</details>
