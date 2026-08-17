@extends('admin.layouts.admin')
@section('title', 'Purchase offers')
@section('heading', 'Purchase offers')

@section('content')
@php
    $qualifyingBv = $settings->qualifyingBvPaise() / 100;
    $activationBv = $settings->activationBvPaise() / 100;
@endphp

<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <p class="max-w-2xl text-sm leading-relaxed text-slate-600">
        Two offers for distributors who hold <strong>no rank</strong>:
        {{ number_format($settings->cycleRateBp() / 100, 0) }}% of the streak's BV as redeem points for
        {{ $settings->cycleMonths() }} consecutive months of <strong>@bv($qualifyingBv) BV</strong>, and a
        half-price company-announced product for a qualifying month (after activating at
        <strong>@bv($activationBv) BV</strong> lifetime) — <strong class="text-amber-300">which is
        recorded but not yet redeemable</strong>.
    </p>
    <form method="GET" action="{{ route('admin.commerce.offers.index') }}" class="flex items-end gap-2">
        <div>
            <label for="month" class="mb-1 block text-xs font-medium text-slate-600">Month</label>
            <input id="month" name="month" type="month" value="{{ $month->format('Y-m') }}"
                   class="rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100">
        </div>
        <button type="submit" class="rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-700 hover:bg-slate-800">
            View
        </button>
    </form>
</div>

@if (session('status'))
    <div class="mb-6 rounded-lg border border-emerald-600/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="mb-6 rounded-lg border border-rose-600/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
        {{ $errors->first() }}
    </div>
@endif

{{-- R-49. Staff announce a product from this screen, so the limitation has to
     be here and not only in the risk register: an announcement creates grants
     the platform cannot honour. --}}
<div class="mb-6 rounded-lg border border-amber-600/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
    <strong>The half-price offer cannot be redeemed yet (R-49).</strong>
    Nothing applies the offer price at cart or checkout, so a grant is a record of who qualified and
    nothing more. It has been removed from the published plan and from the distributor's My Offers page
    rather than promising a mechanism that does not exist. Announcing a product below is safe — it does
    not create anything a distributor can act on — but do not tell anyone the offer is live.
</div>

{{-- The announcement --}}
<div class="mb-8 rounded-xl border border-slate-700 bg-slate-900/40 p-6">
    <h2 class="mb-1 text-sm font-semibold text-slate-100">
        Half-price product for {{ $month->format('F Y') }}
    </h2>
    <p class="mb-4 text-sm text-slate-600">
        With no product named, the engine grants no half-price offer for this month at all — an entitlement
        to an unnamed product is not an entitlement. Once the month has been granted the product can no
        longer be changed.
    </p>

    @if ($announced)
        <div class="mb-4 rounded-lg bg-slate-800/60 px-4 py-3 text-sm">
            <p class="font-semibold text-slate-100">
                {{ $announced->variant->product?->name ?? $announced->variant->variant_sku }}
            </p>
            <p class="text-slate-600">
                Distributor price ₹@bv($announced->variant->distributor_price_paise / 100)
                → offer price
                <strong class="text-emerald-300">₹@bv($settings->offerPricePaise($announced->variant->distributor_price_paise) / 100)</strong>
            </p>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.commerce.offers.announce') }}"
          class="flex flex-wrap items-end gap-3"
          data-confirm="Announce this product for {{ $month->format('F Y') }}?"
          data-confirm-title="Announce the half-price product"
          data-confirm-impact="Every unranked distributor who repurchases the qualifying volume this month gets a grant RECORDED against this product. The half-price offer is not redeemable yet (R-49) — nothing applies the offer price at checkout, and the distributor is not shown the grant.">
        @csrf
        <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
        <div class="min-w-72 flex-1">
            <label for="product_variant_id" class="mb-1.5 block text-sm font-medium text-slate-300">Product</label>
            <select id="product_variant_id" name="product_variant_id" required
                    class="w-full rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100">
                <option value="">Choose a product</option>
                @foreach ($variants as $variant)
                    <option value="{{ $variant->id }}" @selected($announced?->product_variant_id === $variant->id)>
                        {{ $variant->product?->name ?? $variant->variant_sku }}
                        — DP ₹{{ number_format($variant->distributor_price_paise / 100, 2) }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="rounded-lg bg-sunrise-800 px-5 py-2.5 text-sm font-semibold text-white hover:bg-sunrise-900">
            {{ $announced ? 'Change product' : 'Announce' }}
        </button>
    </form>
</div>

{{-- What was granted --}}
<div class="mb-6 grid gap-3 sm:grid-cols-3">
    <div class="rounded-xl border border-slate-700 bg-slate-900/40 px-4 py-3">
        <p class="text-xs uppercase tracking-wider text-slate-500">Half-price grants</p>
        <p class="mt-1 text-2xl font-bold text-slate-100">{{ $totals['half_price'] }}</p>
    </div>
    <div class="rounded-xl border border-slate-700 bg-slate-900/40 px-4 py-3">
        <p class="text-xs uppercase tracking-wider text-slate-500">Streaks completed</p>
        <p class="mt-1 text-2xl font-bold text-slate-100">{{ $totals['points_grants'] }}</p>
    </div>
    <div class="rounded-xl border border-slate-700 bg-slate-900/40 px-4 py-3">
        <p class="text-xs uppercase tracking-wider text-slate-500">Points awarded</p>
        <p class="mt-1 text-2xl font-bold text-slate-100">{{ number_format($totals['points_awarded']) }}</p>
    </div>
</div>

@if ($grants->isEmpty())
    <div class="rounded-xl border border-dashed border-slate-700 px-6 py-12 text-center text-slate-600">
        Nothing granted for {{ $month->format('F Y') }}. Run <code>offers:monthly-run --month={{ $month->format('Y-m') }}</code>.
    </div>
@else
    <div class="overflow-x-auto rounded-xl border border-slate-700">
        <table class="min-w-full divide-y divide-slate-700 text-sm">
            <thead class="bg-slate-800 text-left text-xs uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">ADN</th>
                    <th class="px-4 py-3">Distributor</th>
                    <th class="px-4 py-3">Offer</th>
                    <th class="px-4 py-3">Qualifying BV</th>
                    <th class="px-4 py-3">Streak</th>
                    <th class="px-4 py-3">Result</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
                @foreach ($grants as $grant)
                    <tr class="hover:bg-slate-800/60">
                        <td class="px-4 py-3 font-mono text-xs">
                            <a href="{{ route('admin.distributors.show', $grant->distributor_id) }}"
                               class="text-sunrise-400 underline">{{ $grant->distributor?->adn }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-300">{{ $grant->distributor?->user?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $grant->offer_type->label() }}</td>
                        <td class="px-4 py-3 text-slate-600">@bv($grant->qualifying_bv_paise / 100)</td>
                        <td class="px-4 py-3 text-slate-600">{{ $grant->streak_months }}</td>
                        <td class="px-4 py-3 text-slate-200">
                            @if ($grant->points_awarded > 0)
                                {{ number_format($grant->points_awarded) }} points
                            @elseif ($grant->variant)
                                {{ $grant->variant->product?->name ?? $grant->variant->variant_sku }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $grants->links() }}</div>
@endif

<x-confirm-modal />
@endsection
