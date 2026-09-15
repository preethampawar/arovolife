@extends('admin.layouts.admin')

@section('title', 'Landing price history')
@section('heading', 'Landing price history')

@section('content')
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('admin.catalog.products.edit', $variant->product) }}"
                class="inline-flex items-center gap-1.5 text-sm text-gray-600 hover:text-gray-900">
                {{ svg('lucide-arrow-left', 'w-3.5 h-3.5', ['aria-hidden' => 'true']) }}
                Back to product
            </a>
        </div>

        <x-ui.card padding="p-6 space-y-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900">{{ $variant->product->name }}</h2>
                <p class="text-xs text-gray-600 font-mono mt-0.5">{{ $variant->variant_sku }}</p>
            </div>
            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
                <div>
                    <span class="block text-xs uppercase tracking-wider text-gray-600">Current landing price</span>
                    <span class="text-lg font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::rupees((int) $variant->landing_price_paise) }}</span>
                </div>
                <div>
                    <span class="block text-xs uppercase tracking-wider text-gray-600">Maintained by</span>
                    <span class="text-sm text-gray-900">{{ $derived ? 'The system, from posted GRNs' : 'Admin (no GRN yet)' }}</span>
                </div>
            </div>
            <p class="text-xs text-gray-600">
                @if ($derived)
                    This price is the weighted-average landed cost of the stock currently on hand — what the
                    goods actually cost to buy and bring in, including the freight and other charges entered
                    on each goods receipt. To change it, change those charges on a GRN. It is what the profit
                    report costs every sale of this product against.
                @else
                    This product has no posted goods receipt yet, so the price is whatever an admin entered.
                    The system takes it over automatically once the first GRN is posted.
                @endif
            </p>
        </x-ui.card>

        <x-ui.card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs uppercase tracking-wider text-gray-600">
                            <th class="px-4 py-3">Changed at</th>
                            <th class="px-4 py-3">Source</th>
                            <th class="px-4 py-3 text-right">From</th>
                            <th class="px-4 py-3 text-right">To</th>
                            <th class="px-4 py-3 text-right">Change</th>
                            <th class="px-4 py-3">Reference</th>
                            <th class="px-4 py-3">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            @php($delta = $row->deltaPaise())
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $row->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-4 py-3">
                                    <x-ui.badge :tone="$row->source === 'grn' ? 'info' : 'neutral'">
                                        {{ ['grn' => 'Goods receipt', 'manual' => 'Admin entry', 'backfill' => 'Backfill'][$row->source] ?? $row->source }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-4 py-3 text-right font-mono">{{ \App\Modules\Shared\Support\IndianNumber::rupees($row->old_paise) }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ \App\Modules\Shared\Support\IndianNumber::rupees($row->new_paise) }}</td>
                                <td class="px-4 py-3 text-right font-mono {{ $delta > 0 ? 'text-red-700' : 'text-green-700' }}">
                                    {{ $delta > 0 ? '+' : '' }}{{ \App\Modules\Shared\Support\IndianNumber::rupees($delta) }}
                                </td>
                                <td class="px-4 py-3">
                                    @if ($row->purchaseInvoice !== null)
                                        <a href="{{ route('admin.inventory.grns.show', $row->purchase_invoice_id) }}"
                                            class="font-mono text-brand-600 hover:underline">{{ $row->purchaseInvoice->grn_no }}</a>
                                    @else
                                        <span class="text-gray-500">—</span>
                                    @endif
                                    @if ($row->note !== null)
                                        <span class="block text-xs text-gray-600">{{ $row->note }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $row->changedBy->name ?? 'System' }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-state colspan="7" title="No landing price changes recorded yet." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
        </x-ui.card>
    </div>
@endsection
