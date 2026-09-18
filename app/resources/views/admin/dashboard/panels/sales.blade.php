@php
    // The order book, not the profit report. This panel is gated on
    // `sales.report.view` (revenue) and the profit screens are gated on
    // `profit.report.view` (supplier cost and margin) — the two are separate
    // deliberately, so a card on the revenue panel must not lead somewhere a
    // viewer of that panel may not be allowed to follow.
    $salesUrl = route('admin.commerce.orders.index');
@endphp
<x-ui.card flush :title="$panelTitle">
    <x-slot:actions>
        <span class="text-[11px] tabular-nums text-gray-500">as of {{ $generated_at->format('H:i') }}</span>
        <button type="button" data-panel-refresh
                class="inline-flex items-center rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700"
                aria-label="Refresh {{ $panelTitle }}">
            {{ svg('lucide-refresh-cw', 'w-3.5 h-3.5') }}
        </button>
    </x-slot:actions>

    <div class="grid grid-cols-1 divide-y divide-gray-100 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
        @foreach($windows as $window)
            <a href="{{ $salesUrl }}" class="block p-5 transition-colors hover:bg-gray-50">
                <p class="text-[13px] font-medium text-gray-600">{{ $window['label'] }}</p>

                <p class="mt-2.5 text-[28px] font-semibold leading-none tracking-tight tabular-nums text-gray-900">
                    {{ \App\Modules\Shared\Support\IndianNumber::rupees($window['totals']['gross_ex_gst_paise']) }}
                </p>
                {{-- The headline is revenue net of GST and the line below it is
                     the full amount the buyer paid. Two rupee figures that
                     differ by the tax need the headline named, or the reader
                     decides for themselves which is which. --}}
                <p class="mt-1.5 text-xs text-gray-500">
                    Revenue ex GST · {{ \App\Modules\Shared\Support\IndianNumber::format($window['totals']['orders']) }} orders
                </p>

                <dl class="mt-3 space-y-1 text-xs">
                    <div class="flex items-center justify-between gap-2 text-gray-500">
                        <dt>Collected</dt>
                        <dd class="tabular-nums text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::rupees($window['totals']['cash_paise']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 text-gray-500">
                        <dt>BV</dt>
                        {{-- Bv::points, not a bare /100: BV is stored in paise and the
                             platform truncates rather than showing a fractional point.
                             The "BV" unit is the <dt>, so only the number goes here. --}}
                        <dd class="tabular-nums text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::format(\App\Modules\Commerce\Support\Bv::points($window['bv_paise'])) }}</dd>
                    </div>
                    @if($window['refunds']['orders'] > 0)
                        <div class="flex items-center justify-between gap-2 text-red-600">
                            <dt>Refunds</dt>
                            <dd class="tabular-nums">
                                −{{ \App\Modules\Shared\Support\IndianNumber::format($window['refunds']['orders']) }}
                                ({{ \App\Modules\Shared\Support\IndianNumber::rupees($window['refunds']['cash_paise']) }})
                            </dd>
                        </div>
                    @endif
                </dl>
            </a>
        @endforeach
    </div>
</x-ui.card>
