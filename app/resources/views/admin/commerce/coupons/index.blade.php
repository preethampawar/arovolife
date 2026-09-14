@extends('admin.layouts.admin')
@section('title', 'Coupons')
@section('heading', 'Coupons')

@section('content')
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <p class="text-sm text-gray-600">Promo codes &amp; discounts applied at checkout. A coupon only reduces what the customer pays — it never creates income.</p>
    <x-ui.button href="{{ route('admin.commerce.coupons.create') }}">
        {{ svg('lucide-plus', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} New coupon
    </x-ui.button>
</div>

<x-filter-bar :filters="$filters" />

<x-ui.card flush>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold w-12">S.No</th>
                <th class="px-4 py-3 font-semibold">Code</th>
                <th class="px-4 py-3 font-semibold">Discount</th>
                <th class="px-4 py-3 font-semibold">Scope</th>
                <th class="px-4 py-3 font-semibold">Window</th>
                <th class="px-4 py-3 font-semibold text-right">Used</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($coupons as $coupon)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-600">{{ $loop->iteration }}</td>
                    <td class="px-4 py-3 font-mono font-semibold text-gray-900">{{ $coupon->code }}</td>
                    <td class="px-4 py-3 text-gray-700">
                        {{ $coupon->displayValue() }}
                        @if($coupon->type === 'percent' && $coupon->max_discount_paise)
                            <span class="text-xs text-gray-600">(max ₹{{ \App\Modules\Shared\Support\IndianNumber::format($coupon->max_discount_paise / 100, 0) }})</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-700 capitalize">{{ $coupon->scope }}</td>
                    <td class="px-4 py-3 text-gray-600 text-xs">
                        {{ $coupon->starts_at?->format('d M Y') ?? '—' }} → {{ $coupon->ends_at?->format('d M Y') ?? '∞' }}
                    </td>
                    <td class="px-4 py-3 text-right text-gray-700">{{ $coupon->used_count }}{{ $coupon->usage_limit ? '/'.$coupon->usage_limit : '' }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $coupon->status === 'active' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-100 text-gray-600 border-gray-200' }}">{{ ucfirst($coupon->status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.commerce.coupons.edit', $coupon) }}" class="text-brand-700 hover:text-brand-800 font-medium">Edit</a>
                    </td>
                </tr>
            @empty
                <x-ui.empty-state colspan="8" title="No coupons yet.">
                    <x-ui.button href="{{ route('admin.commerce.coupons.create') }}" variant="secondary" size="sm" icon="plus">Create one</x-ui.button>
                </x-ui.empty-state>
            @endforelse
        </tbody>
    </table>
</x-ui.card>

<div class="mt-4">{{ $coupons->links() }}</div>
@endsection
