@extends('admin.layouts.admin')

@section('title', $title)
@section('heading', $title)

@section('content')
    @php
        $rupees = fn (int $p) => \App\Modules\Shared\Support\IndianNumber::rupees($p);
        $count = fn (int $n) => \App\Modules\Shared\Support\IndianNumber::format($n);
        // Net payable and net outward can legitimately go negative — that is
        // credit carried forward — so they must read as negative rather than
        // lose the sign to the currency formatter.
        $signed = fn (int $p) => ($p < 0 ? '−' : '').$rupees(abs($p));
        $rate = fn (?int $bp) => $bp === null ? '—' : \App\Modules\Shared\Support\IndianNumber::percentFromBp($bp);

        $output = $data['output_by_head'];
        $credit = $data['credit_notes_by_head'];
        $input = $data['input_by_head'];
        $net = $data['net_payable_by_head'];

        $outputTax = $output['cgst_paise'] + $output['sgst_paise'] + $output['igst_paise'] + $output['cess_paise'];
        $creditTax = $credit['cgst_paise'] + $credit['sgst_paise'] + $credit['igst_paise'];
        $inputTax = $input['cgst_paise'] + $input['sgst_paise'] + $input['igst_paise'];
        $netTax = $net['cgst_paise'] + $net['sgst_paise'] + $net['igst_paise'];

        $tiles = [
            ['Taxable value supplied', $rupees($output['taxable_paise']), 'Ex-GST value of the goods on the tax invoices issued in this period.', false],
            ['Output tax', $rupees($outputTax), 'CGST, SGST, IGST and cess charged on those invoices, before credit notes.', false],
            ['Input tax credit', $rupees($inputTax), 'GST on goods receipts posted against supplier invoices dated in this period.', false],
            ['Net payable', $signed($netTax), 'Output less credit notes less input credit, summed across the heads. No cross-utilisation is applied — the per-head figures below are the ones to file.', $netTax < 0],
        ];
    @endphp

    <div class="space-y-4">
        <a href="{{ route('admin.reports.profit.index') }}"
            class="inline-flex items-center gap-1.5 text-sm text-gray-600 hover:text-gray-900">
            {{ svg('lucide-arrow-left', 'w-3.5 h-3.5', ['aria-hidden' => 'true']) }}
            All profit reports
        </a>

        @include('admin.reports.tax._how-gst-works')
        @include('admin.reports.tax._filters')

        @if ($data['receipts_without_gstin'] > 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ $count($data['receipts_without_gstin']) }} documents in this period were issued without a seller
                GSTIN, as receipts rather than tax invoices; their tax is shown but they are not GSTR-1 supplies
                until re-issued.
            </div>
        @endif

        @if ($data['seller_gstin'] === null)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Seller GSTIN is not set today (Admin → Settings → Commerce), so any invoice issued from now on will
                be a receipt too.
            </div>
        @endif

        @if ($data['orders_without_invoice'] > 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ $count($data['orders_without_invoice']) }} orders were paid in this period and hold no tax
                invoice (CGST §31). Their tax is not on this page. Issue them from Admin → Payments before filing.
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
            The documents behind these totals are on the
            <a href="{{ route('admin.reports.profit.gst-outward', request()->query()) }}"
                class="text-brand-600 hover:text-brand-700 font-medium">outward register →</a>
            and the
            <a href="{{ route('admin.reports.profit.gst-inward', request()->query()) }}"
                class="text-brand-600 hover:text-brand-700 font-medium">inward register →</a>
        </p>

        <x-ui.card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    {{--
                        Tone says whose money a row is, and it is the same rule
                        on every block of this statement:

                        · `liability` (amber) — owed to the government. Tax
                          collected, any net of it, and anything payable.
                        · default — reduces what is owed. Tax given back on a
                          credit note, and tax paid to suppliers, including the
                          input-credit subtotal: amber there would read as a
                          debt when it is the opposite of one.
                        · `muted` — a memo that is in no chain at all, plus any
                          taxable value, which is a base and not a tax.

                        A count is a memo whatever it counts, and a row that
                        wants somebody's attention when it is non-zero carries
                        `liability` only while it is.
                    --}}
                    <tbody class="divide-y divide-gray-100">
                        <x-profit-section label="Outward supplies (tax invoices issued)" />
                        <x-profit-line label="Taxable value" :value="$rupees($output['taxable_paise'])" tone="muted"
                            note="Memo only, and not tax. The ex-GST value of the goods the tax below was charged on." />
                        <x-profit-line label="CGST collected" :value="$rupees($output['cgst_paise'])" tone="liability"
                            note="Collected from customers on the government's behalf — never arovolife's money. The central half of the tax on supplies inside the supply-from state." />
                        <x-profit-line label="SGST collected" :value="$rupees($output['sgst_paise'])" tone="liability"
                            note="Collected from customers on the government's behalf — never arovolife's money. The state half of the tax on supplies inside the supply-from state." />
                        <x-profit-line label="IGST collected" :value="$rupees($output['igst_paise'])" tone="liability"
                            note="Collected from customers on the government's behalf — never arovolife's money. The whole tax on supplies to another state." />
                        <x-profit-line label="Cess collected" :value="$rupees($output['cess_paise'])"
                            :tone="$output['cess_paise'] > 0 ? 'liability' : 'muted'"
                            note="Collected from customers on the government's behalf. Always nil today — no listed product attracts cess — and carried so the head cannot silently disappear if one ever does." />
                        <x-profit-line label="Output tax collected" :value="$rupees($outputTax)" strong tone="liability"
                            :tip="$count($output['invoices']).' tax invoices in this period.'"
                            note="Collected from customers on the government's behalf — never arovolife's money. Every head together, before the credit notes below." />

                        <x-profit-section label="Less: credit notes (GST-reversing refunds)" />
                        <x-profit-line label="Taxable value reversed" :value="$rupees($credit['taxable_paise'])" tone="muted"
                            note="Memo only, and not tax. The ex-GST value of the goods that came back." />
                        <x-profit-line label="CGST reversed" :value="$rupees($credit['cgst_paise'])"
                            note="Given back to customers and no longer owed to the government. Tax already collected that a refund returned." />
                        <x-profit-line label="SGST reversed" :value="$rupees($credit['sgst_paise'])"
                            note="Given back to customers and no longer owed to the government. Tax already collected that a refund returned." />
                        <x-profit-line label="IGST reversed" :value="$rupees($credit['igst_paise'])"
                            note="Given back to customers and no longer owed to the government. Tax already collected that a refund returned." />
                        @if ($credit['unclassified_gst_paise'] > 0)
                            <x-profit-line label="Reversed with no tax invoice on record" :value="$rupees($credit['unclassified_gst_paise'])" tone="liability"
                                note="Not in any head above, and deliberately not allocated to one. Tax a refund reversed on an order that holds no invoice, so there is no document to credit it against — find it on the outward register and resolve it before filing." />
                        @endif
                        <x-profit-line label="Credit notes" :value="$count($credit['notes'])" tone="muted"
                            note="Memo only. How many credit-note rows the register carries for this period. No numbered credit-note document is issued yet." />

                        <x-profit-section label="Net outward tax" />
                        <x-profit-line label="Net CGST" :value="$signed($output['cgst_paise'] - $credit['cgst_paise'])" tone="liability"
                            note="Owed to the government before input credit. Collected less given back, for this head only." />
                        <x-profit-line label="Net SGST" :value="$signed($output['sgst_paise'] - $credit['sgst_paise'])" tone="liability"
                            note="Owed to the government before input credit. Collected less given back, for this head only." />
                        <x-profit-line label="Net IGST" :value="$signed($output['igst_paise'] - $credit['igst_paise'])" tone="liability"
                            note="Owed to the government before input credit. Collected less given back, for this head only." />
                        <x-profit-line label="Net output tax" :value="$signed($outputTax - $creditTax)" strong tone="liability"
                            note="Owed to the government before input credit — still never arovolife's money. Everything collected in this period less everything given back in it." />

                        <x-profit-section label="Input tax credit (goods receipts posted)" />
                        <x-profit-line label="Taxable value purchased" :value="$rupees($input['taxable_paise'])" tone="muted"
                            note="Memo only, and not tax. The ex-GST value of the goods the input tax below was charged on." />
                        <x-profit-line label="CGST paid to suppliers" :value="$rupees($input['cgst_paise'])"
                            note="GST paid to suppliers, set off against output tax — reduces what is owed. Charged on goods bought inside the supply-from state." />
                        <x-profit-line label="SGST paid to suppliers" :value="$rupees($input['sgst_paise'])"
                            note="GST paid to suppliers, set off against output tax — reduces what is owed. Charged on goods bought inside the supply-from state." />
                        <x-profit-line label="IGST paid to suppliers" :value="$rupees($input['igst_paise'])"
                            note="GST paid to suppliers, set off against output tax — reduces what is owed. Charged on goods bought from another state." />
                        <x-profit-line label="Unclassified — supplier state missing or unreadable"
                            :value="$rupees($input['unclassified_gst_paise'])"
                            :tone="$input['unclassified_gst_paise'] > 0 ? 'liability' : 'muted'"
                            :tip="$rupees($input['unclassified_taxable_paise']).' of taxable value sits behind this.'"
                            note="Not in any head above, and never added into one. Input tax on receipts whose supplier has no readable state on file, so the head cannot be decided. Fix the supplier in Admin → Inventory → Suppliers and this moves into a head." />
                        <x-profit-line label="Input tax credit" :value="$rupees($inputTax)" strong
                            :tip="$count($input['invoices']).' goods receipts posted in this period.'"
                            note="GST paid to suppliers, set off against output tax — reduces what is owed. Unclassified input is NOT included." />

                        <x-profit-section label="Net payable by head" />
                        <x-profit-line label="CGST payable" :value="$signed($net['cgst_paise'])" tone="liability"
                            note="What is owed to the GST department for this period, per head, with no cross-utilisation applied." />
                        <x-profit-line label="SGST payable" :value="$signed($net['sgst_paise'])" tone="liability"
                            note="What is owed to the GST department for this period, per head, with no cross-utilisation applied." />
                        <x-profit-line label="IGST payable" :value="$signed($net['igst_paise'])" tone="liability"
                            note="What is owed to the GST department for this period, per head, with no cross-utilisation applied." />
                        <x-profit-line label="Net payable" :value="$signed($netTax)" strong tone="liability"
                            note="What is owed to the GST department for this period, summed for convenience only. A head is filed on its own line: a negative head is credit carried forward, not money coming back, and it cannot pay down another head." />

                        <x-profit-section label="Memo" />
                        <x-profit-line label="Paid orders in this period with no tax invoice"
                            :value="$count($data['orders_without_invoice'])"
                            :tone="$data['orders_without_invoice'] > 0 ? 'liability' : 'muted'"
                            note="Memo only, and not in any figure above. Orders whose payment confirmed but whose invoice was never issued (CGST §31) — their tax is missing from this page until they are issued from Admin → Payments." />
                        <x-profit-line label="GST on the order book for the same window"
                            :value="$signed($data['order_book_gst_paise'])" tone="muted"
                            note="Memo only, for reconciling against the Profit summary. The same tax counted on the shipped-date clock instead of the invoice-date one — the two will differ, and this is the size of the difference." />
                        <x-profit-line label="Refunds approved with no tax credit"
                            :value="$count($data['refunds_without_tax_credit'])" tone="muted"
                            note="Memo only. Refunds where the tax was not returned to the buyer — a buyback outside the cooling-off window — so the output tax stays remitted and no credit note arises." />
                        <x-profit-line label="Documents issued without a seller GSTIN"
                            :value="$count($data['receipts_without_gstin'])"
                            :tone="$data['receipts_without_gstin'] > 0 ? 'liability' : 'muted'"
                            :tip="$rupees($data['receipts_without_gstin_gst_paise']).' of tax sits on those documents.'"
                            note="Memo only, and read off the documents rather than off the setting — each invoice freezes the GSTIN it was issued under. A document with none is a receipt, and its tax is counted above but is not a GSTR-1 supply until the document is re-issued." />
                        <x-profit-line label="Seller GSTIN" :value="$data['seller_gstin'] ?? 'Not set'" tone="muted"
                            note="Memo only, and it is the setting as it stands today, not what the documents above carry. Without it a new invoice is issued as a receipt, not a tax invoice." />
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <x-ui.card flush>
                <div class="px-4 py-3 border-b border-gray-100">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-700">Outward by rate</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs uppercase tracking-wider text-gray-600">
                                <th class="px-4 py-3">Rate</th>
                                <th class="px-4 py-3 text-right">Taxable</th>
                                <th class="px-4 py-3 text-right">CGST</th>
                                <th class="px-4 py-3 text-right">SGST</th>
                                <th class="px-4 py-3 text-right">IGST</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($data['output_by_rate'] as $row)
                                <tr>
                                    <td class="px-4 py-2.5">{{ $rate($row['rate_bp']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['taxable_paise']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['cgst_paise']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['sgst_paise']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['igst_paise']) }}</td>
                                </tr>
                            @empty
                                <x-ui.empty-state :colspan="5" title="No invoices were issued in this period." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card flush>
                <div class="px-4 py-3 border-b border-gray-100">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-700">Input credit by rate</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs uppercase tracking-wider text-gray-600">
                                <th class="px-4 py-3">Rate</th>
                                <th class="px-4 py-3 text-right">Taxable</th>
                                <th class="px-4 py-3 text-right">CGST</th>
                                <th class="px-4 py-3 text-right">SGST</th>
                                <th class="px-4 py-3 text-right">IGST</th>
                                <th class="px-4 py-3 text-right">Unclassified</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($data['input_by_rate'] as $row)
                                <tr>
                                    <td class="px-4 py-2.5">{{ $rate($row['rate_bp']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['taxable_paise']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['cgst_paise']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['sgst_paise']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['igst_paise']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['unclassified_gst_paise']) }}</td>
                                </tr>
                            @empty
                                <x-ui.empty-state :colspan="6" title="No goods receipts were posted in this period." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        @if ($data['credit_notes_by_rate'] !== [])
            <x-ui.card flush>
                <div class="px-4 py-3 border-b border-gray-100">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-700">Credit notes by rate</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs uppercase tracking-wider text-gray-600">
                                <th class="px-4 py-3">Rate</th>
                                <th class="px-4 py-3 text-right">Taxable</th>
                                <th class="px-4 py-3 text-right">CGST</th>
                                <th class="px-4 py-3 text-right">SGST</th>
                                <th class="px-4 py-3 text-right">IGST</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($data['credit_notes_by_rate'] as $row)
                                <tr>
                                    <td class="px-4 py-2.5">{{ $rate($row['rate_bp']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['taxable_paise']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['cgst_paise']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['sgst_paise']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $rupees($row['igst_paise']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    </div>
@endsection
