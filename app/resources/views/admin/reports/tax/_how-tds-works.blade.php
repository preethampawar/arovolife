{{--
    The explanatory panel for the TDS report. Same markup as the profit
    panels: the figures here are definitions as much as numbers, and a
    deduction register read on the wrong definition is filed wrong.
--}}
<details class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
    <summary class="cursor-pointer font-medium flex items-center gap-2">
        {{ svg('lucide-info', 'w-4 h-4 shrink-0', ['aria-hidden' => 'true']) }}
        How this report is calculated
    </summary>
    <div class="mt-3 space-y-3 text-[13px] leading-relaxed">
        <p>
            <strong>Read from the ledger debits, never from the payout lines.</strong> TDS is counted
            only where a <em>tds_debit</em> entry was actually written to a distributor's wallet.
            A <em>below-minimum</em> line carries a TDS figure that was computed and then discarded
            because the payout never went out, and a held line — KYC pending, no bank account,
            web-only, or bank details that would not decrypt — is written afresh by every batch
            over the same unswept credits. Summing the
            column on the payout lines would report tax that was never withheld, several times over.
            Both are shown in the memo block instead, as figures that exist but were not deducted.
        </p>
        <p>
            <strong>Dated by the batch date.</strong> A line belongs to the period its payout batch is
            dated in, which is the deduction date a Form 26Q return is built around. The Company
            snapshot dates the same rupees by when the ledger debit was written, so the two pages will
            not agree inside a short window. Neither is wrong; they answer different questions.
        </p>
        <p>
            <strong>TDS may be less than {{ $tds_rate }} of the payable shown.</strong> Payable here is
            <em>gross − repurchase deduction − admin charge</em>. On a monthly batch the tax is
            computed on a narrower base that leaves out Lifetime Award cash already taxed when it was
            delivered, and that base is not stored anywhere, so it cannot be shown as a column. The
            register therefore shows payable and TDS and never a "TDS base".
        </p>
        <p>
            <strong>PAN is masked, and only masked.</strong> The register reads the last four digits
            held on the distributor record; the full number is never selected by this report on any
            code path. For a Form 26Q filing the full PAN comes from the KYC documents in
            Admin → KYC, through the audited route, by someone who holds that permission.
        </p>
        <p>
            <strong>A rebuilt batch moves its TDS with it.</strong> Rebuilding a period deletes that
            batch's lines and their ledger debits together and writes both again, so a figure read
            before a rebuild and one read after can differ — but the two stay in step. The "line figure
            and ledger debit disagree" memo should read zero; a non-zero means a line or ledger row was
            edited outside the payout engine — tell the platform team.
        </p>
    </div>
</details>
