{{-- Reusable storefront product card.
     Vars: $product (Product), $tone (one entry of the $cardTones palette).
     Renders nothing if the product has no active variant. --}}
@php
    $variant = $product->primaryVariant();
    $cardImage = $product->galleryImages->first()?->url() ?? $product->image_url;
    $catLabel = $product->productCategory?->name ?? ($product->category ? str_replace('-', ' ', $product->category) : null);
@endphp
@if($variant !== null)
<div class="bg-white rounded-xl border border-gray-200 {{ $tone['borderHover'] }} overflow-hidden shadow-sm hover:shadow-lg {{ $tone['shadow'] }} hover:-translate-y-0.5 transition-all duration-300 group flex flex-col">
    <a href="{{ route('shop.product', $product->slug) }}"
       class="relative block aspect-square bg-gradient-to-br {{ $tone['gradient'] }} flex items-center justify-center overflow-hidden">
        @if($cardImage)
            <img src="{{ $cardImage }}" alt="{{ $product->name }}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110">
        @else
            <div class="text-center {{ $tone['iconColor'] }}">
                <x-lucide-image class="w-12 h-12 mx-auto mb-2 opacity-70" />
            </div>
        @endif
        @if($variant->hasDiscount())
            <span class="absolute top-2 left-2 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-red-500 text-white shadow-md">
                −{{ $variant->discountPercent() }}%
            </span>
        @endif
        @if($catLabel)
            <span class="absolute top-2 right-2 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider {{ $tone['badgeBg'] }} {{ $tone['badgeTxt'] }} backdrop-blur-sm shadow-sm">
                {{ $catLabel }}
            </span>
        @endif
    </a>
    <div class="p-3 flex-1 flex flex-col">
        <a href="{{ route('shop.product', $product->slug) }}" class="block">
            <h3 class="text-sm font-semibold text-gray-900 group-hover:text-brand-800 transition-colors leading-snug flex items-start gap-1.5">
                <x-veg-mark :type="$product->food_type" size="sm" class="mt-0.5" />
                <span class="line-clamp-2">{{ $product->name }}</span>
            </h3>
        </a>
        @if($product->short_description)
            <p class="text-xs text-gray-600 mt-1 line-clamp-1">{{ $product->short_description }}</p>
        @endif
        {{-- Price row + Add-to-Cart icon --}}
        <div class="flex items-center justify-between gap-2 mt-2 pt-2 border-t border-gray-100 mt-auto">
            <div class="flex items-baseline gap-1.5 min-w-0">
                <span class="text-base font-bold text-gray-900">{{ $variant->displayPrice() }}</span>
                @if($variant->hasDiscount())
                    <span class="text-xs text-gray-600 line-through">{{ $variant->displayMrp() }}</span>
                @endif
            </div>
            <form method="POST" action="{{ route('shop.cart.add') }}" class="shrink-0" data-add-to-cart>
                @csrf
                <input type="hidden" name="product_variant_id" value="{{ $variant->id }}">
                <input type="hidden" name="qty" value="1">
                <button type="submit" aria-label="Add {{ $product->name }} to cart" title="Add to cart"
                    class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-brand-700 hover:bg-brand-800 text-white shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-400">
                    <x-lucide-shopping-cart class="w-5 h-5" />
                </button>
            </form>
        </div>
        {{-- BV shown only to logged-in distributors — a factual point value
             for the compensation plan, never an earnings figure
             (DSR Rule 5(1)(d) / hard rule #3). Mirrors the product page. --}}
        @auth
            @if(auth()->user()->distributor && $variant->bv_paise > 0)
            <div class="mt-2">
                <span class="inline-block text-xs font-semibold text-brand-700 bg-brand-50 border border-brand-200 px-2 py-0.5 rounded" title="Business Volume — points used in the compensation plan">{{ \App\Modules\Shared\Support\IndianNumber::format($variant->bv_paise / 100, 0) }} BV</span>
            </div>
            @endif
        @endauth
    </div>
</div>
@endif
