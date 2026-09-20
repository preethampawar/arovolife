@php
    use App\Modules\Commerce\Support\Bv;
    use App\Modules\Shared\Support\IndianNumber;
    use Illuminate\Support\Str;

    // The order book, not the profit report. Revenue is gated on
    // `sales.report.view` and the profit screens on `profit.report.view` — the
    // two are separate deliberately, so a cell on this card must not lead
    // somewhere its viewer may not be allowed to follow.
    $ordersUrl = route('admin.commerce.orders.index');

    $rupees = fn (int $paise): string => IndianNumber::rupees($paise);
    $count = fn (int $n): string => IndianNumber::format($n);
@endphp
<x-ui.card flush :title="$panelTitle">
    <x-slot:actions><x-ui.panel-actions :generated-at="$generated_at" :title="$panelTitle" /></x-slot:actions>

    {{-- Revenue is absent, not blank, for a viewer without `sales.report.view`:
         the controller does not compute it for them at all. The pipeline below
         is open to every admin, which is why this card is gated on nothing and
         its revenue row on the ability that gates the sales report itself. --}}
    @if($sales !== null)
        <x-ui.stat-row :columns="3">
            @foreach($sales['windows'] as $window)
                <x-ui.stat flush :label="$window['label']" :href="$ordersUrl"
                           :value="$rupees($window['totals']['gross_ex_gst_paise'])"
                           :hint="'Revenue ex GST · '.$count($window['totals']['orders']).' orders'">
                    {{-- The headline is revenue net of GST. Every figure under
                         it is named, because rupee amounts that differ by tax,
                         shipping and non-cash settlement are otherwise left to
                         the reader to tell apart. --}}
                    <dl class="mt-3 space-y-1 text-xs">
                        <div class="flex items-center justify-between gap-2 text-gray-500">
                            <dt>GST</dt>
                            <dd class="tabular-nums text-gray-700">{{ $rupees($window['totals']['gst_paise']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 text-gray-500">
                            <dt>Incl. GST</dt>
                            <dd class="tabular-nums text-gray-700">{{ $rupees($window['totals']['gross_ex_gst_paise'] + $window['totals']['gst_paise']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 text-gray-500">
                            <dt>Collected</dt>
                            <dd class="tabular-nums text-gray-700">{{ $rupees($window['totals']['cash_paise']) }}</dd>
                        </div>
                        {{-- Settled with repurchase-wallet credit rather than
                             money, and the usual reason Collected reads low
                             against revenue. Shown even at zero, unlike
                             refunds: it is a settlement line, not an exception. --}}
                        <div class="flex items-center justify-between gap-2 text-gray-500">
                            <dt>Repurchase wallet</dt>
                            <dd class="tabular-nums text-gray-700">{{ $rupees($window['repurchase_wallet_paise']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 text-gray-500">
                            <dt>BV</dt>
                            {{-- Bv::points, not a bare /100: BV is stored in paise and
                                 the platform truncates rather than showing a fractional
                                 point. The "BV" unit is the <dt>, so only the number
                                 goes here. --}}
                            <dd class="tabular-nums text-gray-700">{{ $count(Bv::points($window['bv_paise'])) }}</dd>
                        </div>
                        @if($window['refunds']['orders'] > 0)
                            <div class="flex items-center justify-between gap-2 text-red-600">
                                <dt>Refunds</dt>
                                <dd class="tabular-nums">
                                    −{{ $count($window['refunds']['orders']) }}
                                    ({{ $rupees($window['refunds']['cash_paise']) }})
                                </dd>
                            </div>
                        @endif
                    </dl>
                </x-ui.stat>
            @endforeach
        </x-ui.stat-row>
    @endif

    <x-ui.stat-row :columns="4" heading="Order pipeline">
        @foreach($orders['pipeline'] as $status => $orderCount)
            <x-ui.stat flush :label-lines="2"
                       :label="Str::headline($status)"
                       :value="$count($orderCount)"
                       :href="route('admin.commerce.orders.index', ['status' => $status])" />
        @endforeach
    </x-ui.stat-row>

    {{-- Exceptions render only when something is in them, and in their own row:
         a cancelled order is not a stage of the pipeline and must not be read
         as one. --}}
    @if(count($orders['exceptions']) > 0)
        <x-ui.stat-row :columns="4" heading="Exceptions">
            @foreach($orders['exceptions'] as $status => $orderCount)
                <x-ui.stat flush :label-lines="2"
                           :label="Str::headline($status)"
                           :value="$count($orderCount)"
                           :href="route('admin.commerce.orders.index', ['status' => $status])" />
            @endforeach
        </x-ui.stat-row>
    @endif
</x-ui.card>
