@props([
    'grossLabel' => 'Gross',
    'creditedLabel' => 'Credited to wallet',
    'repurchase' => true,
    'thClass' => 'px-4 py-2 text-right text-gray-600',
])
@php
    // Rate and cap are admin-editable plan settings — quote the live values so an
    // edit at Plan settings can never leave this tooltip stating a stale figure.
    $plan = app(\App\Modules\Compensation\Services\CompensationPlanSettingsService::class);
    $repurchaseRate = rtrim(rtrim(number_format($plan->repurchaseRateBp() / 100, 2, '.', ''), '0'), '.');
    $repurchaseCap = \App\Modules\Shared\Support\IndianNumber::format($plan->repurchaseCapPaise() / 100, 0);
@endphp
{{-- Header cells for a bonus result row: Gross · Repurchase deduction · Credited to wallet.
     Pairs with <x-bonus-credit-cells>. The labels and their explanations live here only,
     so every bonus page (admin and distributor) says the same thing. Admin charge and TDS
     are payout-time deductions and are shown on the payout pages, never on a bonus row. --}}
<th {{ $attributes->merge(['class' => $thClass]) }}>
    <span class="flex items-center justify-end gap-1">{{ $grossLabel }} <x-help-tip text="What the plan awarded for this row, before any deduction." /></span>
</th>
@if($repurchase)
<th class="{{ $thClass }}">
    <span class="flex items-center justify-end gap-1">Repurchase deduction <x-help-tip text="Moved to the repurchase wallet the moment this bonus was credited — {{ $repurchaseRate }}% of the gross, up to ₹{{ $repurchaseCap }} per calendar month across all bonuses." /></span>
</th>
@endif
<th class="{{ $thClass }}">
    <span class="flex items-center justify-end gap-1">{{ $creditedLabel }} <x-help-tip text="{{ $repurchase ? 'Gross minus the repurchase deduction — what landed in the main wallet.' : 'What landed in the main wallet.' }} The 3% admin charge and 5% TDS are taken later, at payout, and appear on the payout statement." /></span>
</th>
