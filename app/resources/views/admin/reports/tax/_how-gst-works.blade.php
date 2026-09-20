{{--
    The explanatory panel for the GST report. Every head on this page is a
    decision made at a particular moment from a particular column, and the
    page says which — a head derived from the wrong column is tax paid to the
    wrong government.
--}}
<details class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
    <summary class="cursor-pointer font-medium flex items-center gap-2">
        {{ svg('lucide-info', 'w-4 h-4 shrink-0', ['aria-hidden' => 'true']) }}
        How this report is calculated
    </summary>
    <div class="mt-3 space-y-3 text-[13px] leading-relaxed">
        <p>
            <strong>Dated by the tax invoice, not by the shipment.</strong> An outward figure belongs to
            the period its invoice was issued in, because that is the document the return reports and
            the invoice cannot be re-dated afterwards. The Profit summary counts a sale when the goods
            shipped, so these two pages will not tie. The memo line carries the GST on the order book
            for the same window on the shipped-date clock, so the size of the gap is a figure on the
            page rather than a question.
        </p>
        <p>
            <strong>The head was decided at invoice time.</strong> CGST and SGST when the place of
            supply equalled the supply-from state, IGST when it did not, with CGST taking the floor of
            the half and SGST the odd paise. That split is frozen onto the invoice and this report
            reads it; it never recomputes it from today's settings.
        </p>
        <p>
            <strong>Credit notes are only the refunds that actually reversed GST (CGST §34).</strong>
            A cooling-off cancellation refunds the tax; a buyback outside that window does not, and
            writes no reversal at all. The report reads the reversal from the accounting entry rather
            than from the order, so a refund that kept the tax cannot reduce the output figure. Refunds
            approved with no tax credit are counted in the memo block. Where a reversal does not match
            its invoice's tax exactly — a partial refund — the row is flagged <em>Partial — check</em>
            and split across the heads the invoice carried, never invented line by line.
        </p>
        <p>
            <strong>No numbered credit-note documents exist yet.</strong> Nothing on this platform
            issues a credit note with its own consecutive number; the register identifies each one by
            the invoice and order it reverses. That is a known gap, not an omission on this page.
        </p>
        <p>
            <strong>Input heads are derived from the supplier's state as recorded today.</strong> A
            goods receipt stores no head of its own, so the report compares the supplier's state with
            the supply-from state at the moment you open it. Editing a supplier's state changes a past
            period's split. A supplier with no state, or a state we cannot read, lands in
            <em>Unclassified</em> — never allocated to a head to make a total look complete, and never
            included in the net payable.
        </p>
        <p>
            <strong>Inward is dated by the supplier's invoice date</strong>, not by when the goods
            receipt was posted, because that is the date on the document the credit is claimed against.
            A receipt posted this month against last quarter's invoice belongs to last quarter.
        </p>
        <p>
            <strong>Net payable is per head, with no cross-utilisation.</strong> CGST cannot be set off
            against SGST, and the order in which IGST credit is consumed is a return-preparation
            decision this report does not make for you. A negative figure is credit carried forward,
            not money coming back.
        </p>
    </div>
</details>
