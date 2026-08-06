@extends('admin.layouts.admin')
@section('title', 'Lifetime Awards Catalog')
@section('heading', 'Lifetime Awards — Reward Catalog')

@section('content')

@include('partials._toast-container')

@php
    $rupees = fn ($paise) => '₹'.\App\Modules\Shared\Support\IndianNumber::format(($paise ?? 0) / 100, 0);
    $byRank = $rewards->groupBy('rank_number');
@endphp

<div class="mb-6 flex items-start justify-between gap-4">
    <div class="flex-1 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
        The per-rank Lifetime Awards catalogue (KP-confirmed). Each row is locked — press <strong>Edit</strong> to change an item's
        text or worth, then <strong>Save</strong> to confirm. Worths are stored in paise (₹ × 100); each rank's items should
        reconcile to its budget. Per-rank budgets are edited on
        <a href="{{ route('admin.compensation.plan-settings.index') }}" class="underline font-medium">Compensation → Plan settings</a>.
    </div>
    <a href="{{ route('admin.lifetime-awards.index') }}" class="shrink-0 px-4 py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">← Milestones</a>
</div>

@foreach($byRank as $rank => $items)
@php
    $budget = $budgets[$rank] ?? 0;
    $sum = $items->sum('worth_paise');
    $reconciles = $sum === $budget;
@endphp
<section class="mb-8">
    <div class="flex items-center justify-between mb-2">
        <h2 class="text-base font-semibold text-gray-800">{{ $rankNames[$rank] ?? 'Rank '.$rank }}</h2>
        <span class="text-xs {{ $reconciles ? 'text-gray-500' : 'text-red-600 font-medium' }}">
            items {{ $rupees($sum) }} / budget {{ $rupees($budget) }}{{ $reconciles ? '' : ' — does not reconcile' }}
        </span>
    </div>
    <div class="space-y-2">
        @foreach($items as $reward)
        <form method="POST" action="{{ route('admin.lifetime-awards.catalog.update', $reward->id) }}"
              data-editable
              data-confirm="Update this reward item for {{ $rankNames[$rank] ?? 'Rank '.$rank }}?"
              data-confirm-title="Confirm reward change"
              data-confirm-impact="Changes the Lifetime Awards catalogue for this rank. Audit-logged."
              class="rounded-xl border border-gray-200 bg-white p-3 flex items-end gap-3">
            @csrf
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-600 mb-1">Item</label>
                <input type="text" name="item" data-field-label="Item" value="{{ $reward->item }}" required maxlength="255"
                       class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
            </div>
            <div class="w-48">
                <label class="block text-xs font-medium text-gray-600 mb-1">Worth (paise)</label>
                <input type="number" name="worth_paise" data-field-label="Worth (paise)" value="{{ $reward->worth_paise }}" required min="0"
                       class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                <span class="text-[11px] text-gray-400">{{ $rupees($reward->worth_paise) }}</span>
            </div>
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Save</button>
        </form>
        @endforeach
    </div>
</section>
@endforeach

@endsection
