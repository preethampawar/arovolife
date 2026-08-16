@extends('layouts.app')
@section('title', 'My offers')

@section('content')
@php
    $qualifyingBv = $settings->qualifyingBvPaise() / 100;
    $activationBv = $settings->activationBvPaise() / 100;
    $cycle = $settings->cycleMonths();
    $thisMonthBv = $thisMonthBvPaise / 100;
    $lifetimeBv = $lifetimeBvPaise / 100;
@endphp

<div class="max-w-3xl mx-auto">

    <h1 class="text-2xl font-bold text-gray-900 mb-2">My offers</h1>
    <p class="text-sm text-gray-600 mb-8">
        Everything here is a record of your own purchases and what they have already earned.
    </p>

    @if ($holdsARank)
        {{-- Honest and unambiguous: these offers are for unranked distributors,
             and someone who has earned a rank should not be left wondering why
             their streak never pays. --}}
        <div class="mb-8 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            <p class="font-semibold mb-1">These offers are for distributors who hold no rank.</p>
            <p>
                You have achieved a rank, so the half-price product and the redeem-point streak no longer
                apply to you. Any points you already earned remain yours and can still be used.
            </p>
        </div>
    @endif

    {{-- Points balance --}}
    <div class="mb-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">Redeem points</p>
        <p class="text-3xl font-bold text-gray-900">{{ number_format($balance) }}</p>
        <p class="mt-1 text-sm text-gray-600">
            One point is ₹1 off a future purchase. Points are not cash: they cannot be withdrawn and are
            not paid out.
        </p>
    </div>

    {{-- Half-price entitlement --}}
    @if ($activeHalfPrice && $activeHalfPrice->variant)
        <div class="mb-8 rounded-2xl border border-emerald-200 bg-emerald-50 p-6">
            <p class="text-xs uppercase tracking-wider text-emerald-700 mb-1">Half-price product available</p>
            <p class="text-lg font-semibold text-emerald-900">
                {{ $activeHalfPrice->variant->product?->name ?? $activeHalfPrice->variant->variant_sku }}
            </p>
            <p class="mt-1 text-sm text-emerald-800">
                Earned for {{ $activeHalfPrice->month_start->format('F Y') }}.
                @if ($activeHalfPrice->expires_on)
                    Use it by {{ $activeHalfPrice->expires_on->format('d M Y') }}.
                @endif
            </p>
        </div>
    @endif

    {{-- Where the streak stands --}}
    <div class="mb-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-900 mb-3">Your purchase streak</h2>

        <dl class="grid gap-4 sm:grid-cols-3 text-sm">
            <div>
                <dt class="text-gray-500">Consecutive qualifying months</dt>
                <dd class="text-xl font-bold text-gray-900">{{ $streakMonths }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">This month so far</dt>
                <dd class="text-xl font-bold text-gray-900">@bv($thisMonthBv) BV</dd>
            </div>
            <div>
                <dt class="text-gray-500">Lifetime purchases</dt>
                <dd class="text-xl font-bold text-gray-900">@bv($lifetimeBv) BV</dd>
            </div>
        </dl>

        <p class="mt-4 text-sm text-gray-600">
            A month counts towards the streak when your own purchases in it reach
            <strong>@bv($qualifyingBv) BV</strong>. {{ $cycle }} consecutive qualifying months complete a
            cycle. The half-price product also needs lifetime purchases of at least
            <strong>@bv($activationBv) BV</strong>.
        </p>
        <p class="mt-2 text-xs text-gray-500">
            A refund reduces the month it belongs to, so a returned purchase does not keep a month
            qualifying.
        </p>
    </div>

    {{-- What has been granted --}}
    <div class="mb-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-900 mb-4">What you have earned</h2>

        @if ($grants->isEmpty())
            <p class="text-sm text-gray-600">Nothing yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="text-left text-xs uppercase tracking-wider text-gray-500">
                        <tr>
                            <th class="py-2 pr-4">Month</th>
                            <th class="py-2 pr-4">Offer</th>
                            <th class="py-2 pr-4">Earned on</th>
                            <th class="py-2">Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($grants as $grant)
                            <tr>
                                <td class="py-2 pr-4 text-gray-900">{{ $grant->month_start->format('M Y') }}</td>
                                <td class="py-2 pr-4 text-gray-700">{{ $grant->offer_type->label() }}</td>
                                <td class="py-2 pr-4 text-gray-600">@bv($grant->qualifying_bv_paise / 100) BV</td>
                                <td class="py-2 text-gray-900">
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
        @endif
    </div>

    {{-- Points ledger --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-900 mb-4">Points history</h2>

        @if ($ledger->isEmpty())
            <p class="text-sm text-gray-600">No point movements yet.</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach ($ledger as $entry)
                    <li class="flex items-start justify-between gap-4 py-2.5">
                        <div>
                            <p class="text-gray-900">{{ $entry->memo }}</p>
                            <p class="text-xs text-gray-500">{{ $entry->created_at->format('d M Y') }}</p>
                        </div>
                        <span class="shrink-0 font-semibold {{ $entry->points < 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                            {{ $entry->points > 0 ? '+' : '' }}{{ number_format($entry->points) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
