{{--
    The top of both company snapshots: goods, then sales, then gross profit.
    One file so the accrual page and the cash page cannot drift — they differ
    only in what they do BELOW gross profit, and a reader who found two
    different cost-of-goods figures on two pages of the same report would be
    right to stop trusting either.

    Expects $data, plus the $rupees and $signed formatters from the page.
--}}
<x-profit-section label="Goods" />
<x-profit-line label="Purchases (taxable, ex-GST)" :value="$rupees($data['purchases_paise'])"
    note="A cost. What suppliers charged for goods received in this period. GST is excluded — there it is input credit to reclaim, not a cost." />
<x-profit-line label="Add: Freight & other charges" :value="$rupees($data['purchase_charges_paise'])"
    note="A cost. Freight, insurance and handling entered on those receipts — the gap between cost price and landed price." />
<x-profit-line label="Landed purchases" :value="$rupees($data['landed_purchases_paise'])" strong
    note="A cost. What the goods bought this period actually cost to get onto the shelf." />
<x-profit-line label="Memo: Opening stock (at cost)" :value="$rupees($data['opening_stock_paise'])" tone="muted"
    note="Memo only, not a cost of this period. The value sitting in the warehouses the moment before this period began, re-derived from the stock ledger." />
<x-profit-line label="Memo: Closing stock (at cost)" :value="$rupees($data['closing_stock_paise'])" tone="muted"
    note="Memo only. The value still on the shelf at the end of the period. Goods bought but not yet sold are not a cost yet." />
<x-profit-line label="Cost of goods sold" :value="$rupees($data['cogs_paise'])" strong
    note="A cost. The landed batch cost stamped on each line when it was packed, net of returns. Read from the sales themselves, not from purchases." />
<x-profit-line label="Memo: Stock that left other than by sale" :value="$signed($data['reconciling_difference_paise'])" tone="muted"
    note="Memo only. Write-offs, damage, transfers and rounding. A real loss, but deliberately not charged to cost of sales — doing that would make trading look worse than it was and bury the loss." />

<x-profit-section label="Sales" />
<x-profit-line label="Gross sales (ex-GST)" :value="$rupees($data['gross_sales_paise'])"
    :tip="$data['orders'].' orders counted, on the basis chosen above.'"
    note="Income. The ex-GST value of the goods sold in this period — what the accounting ledger recognises as a sale." />
<x-profit-line label="Less: Returns & refunds" :value="$rupees($data['refunds_paise'])"
    :tip="$data['refunded_orders'].' refunds approved in this period, whenever the original sale happened.'"
    note="Income reversed. Refunds approved in this period, whichever period the original sale fell in." />
<x-profit-line label="Net sales" :value="$rupees($data['net_sales_paise'])" strong
    note="Income. The ex-GST value of goods sold, after returns." />
<x-profit-line label="Less: Cost of goods sold" :value="$rupees($data['cogs_paise'])"
    note="A cost, brought down from Goods above. Shown twice on purpose: once where it is worked out, once where it is subtracted." />
<x-profit-line label="Gross profit" :value="$signed($data['gross_profit_paise'])" strong
    note="arovolife's money. Net sales less the cost of those goods — trading profit on the goods alone, before anything is paid under the plan." />
