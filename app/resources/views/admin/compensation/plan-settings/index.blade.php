@extends('admin.layouts.admin')
@section('title', 'Compensation Plan Settings')
@section('heading', 'Compensation Plan Settings')

@section('content')

@php
    $bv = fn ($paise) => $paise === null ? '—' : number_format($paise / 100, 0).' BV';
    $rupees = fn ($paise) => $paise === null ? '—' : '₹'.number_format($paise / 100, 2);
@endphp

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Edit the live compensation-plan ladders below. All BV and money fields are stored in <strong>paise</strong>
    (BV × 100, ₹ × 100). Every change is audit-logged and takes effect on the next engine run.
    Rates, caps and periods (admin charge, TDS, repurchase %, grace days, etc.) are edited under
    <a href="{{ route('admin.settings') }}#compensation_plan" class="underline font-medium">Settings → Compensation plan</a>.
</div>

@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
@endif

{{-- ── GSB slabs ─────────────────────────────────────────────────────────── --}}
<section class="mb-10">
    <h2 class="text-base font-semibold text-gray-800 mb-1">GSB slabs</h2>
    <p class="text-xs text-gray-500 mb-3">
        Bonus follows the score × rate model — currently <strong>₹{{ number_format($scoreRatePaise / 100, 2) }}</strong>
        per score point, so the bonus is computed from the score on save. A blank score leaves the bonus unset
        (e.g. slab 7 until its figures are confirmed).
    </p>
    <div class="space-y-3">
        @foreach($slabs as $row)
        <form method="POST" action="{{ route('admin.compensation.plan-settings.gsb-slab.update', $row->slab) }}"
              data-confirm="Update GSB slab {{ $row->slab }} ({{ $row->title ?? 'untitled' }})?"
              data-confirm-title="Confirm: GSB slab {{ $row->slab }}"
              data-confirm-impact="Changes the live compensation plan for all distributors. Audit-logged; takes effect on the next daily GSB cut-off."
              class="rounded-xl border border-gray-200 bg-white p-4">
            @csrf
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-semibold text-gray-700">Slab {{ $row->slab }}</span>
                <span class="text-xs text-gray-400">current bonus: {{ $rupees($row->bonus_paise) }}</span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Title</label>
                    <input type="text" name="title" value="{{ $row->title }}"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Title min BV (paise)</label>
                    <input type="number" name="title_min_bv_paise" value="{{ $row->title_min_bv_paise }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->title_min_bv_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Matched BV (paise)</label>
                    <input type="number" name="matched_bv_paise" value="{{ $row->matched_bv_paise }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->matched_bv_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Score</label>
                    <input type="number" name="score" value="{{ $row->score }}" min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                    <span class="text-[11px] text-gray-400">→ {{ $row->score !== null ? '₹'.number_format(($row->score * $scoreRatePaise) / 100, 0) : '—' }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">AGP / occurrence</label>
                    <input type="number" name="agp_per_occurrence" value="{{ $row->agp_per_occurrence }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                </div>
                <label class="flex items-center gap-2 text-xs text-gray-600 mt-5">
                    <input type="checkbox" name="carry_forward_lifetime" value="1" @checked($row->carry_forward_lifetime)>
                    Lifetime carry-forward
                </label>
                <label class="flex items-center gap-2 text-xs text-gray-600 mt-5">
                    <input type="checkbox" name="is_active" value="1" @checked($row->is_active)>
                    Active
                </label>
                <div class="flex items-end">
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Save</button>
                </div>
            </div>
        </form>
        @endforeach
    </div>
</section>

{{-- ── Rank tiers ────────────────────────────────────────────────────────── --}}
<section class="mb-10">
    <h2 class="text-base font-semibold text-gray-800 mb-3">Rank tiers</h2>
    <div class="space-y-3">
        @foreach($rankTiers as $row)
        <form method="POST" action="{{ route('admin.compensation.plan-settings.rank-tier.update', $row->rank_number) }}"
              data-confirm="Update rank {{ $row->rank_number }} ({{ $row->rank_name }})?"
              data-confirm-title="Confirm: Rank {{ $row->rank_number }}"
              data-confirm-impact="Changes rank qualification and pool % for all distributors. Audit-logged; takes effect on the next monthly run."
              class="rounded-xl border border-gray-200 bg-white p-4">
            @csrf
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Rank {{ $row->rank_number }} name</label>
                    <input type="text" name="rank_name" value="{{ $row->rank_name }}" required
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Pool %</label>
                    <input type="number" step="0.01" name="pool_pct" value="{{ rtrim(rtrim(number_format($row->pool_pct, 2, '.', ''), '0'), '.') }}" required min="0" max="100"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">PYP required</label>
                    <input type="number" name="pyp_required" value="{{ $row->pyp_required }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Personal BV (paise)</label>
                    <input type="number" name="personal_bv_required_paise" value="{{ $row->personal_bv_required_paise }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->personal_bv_required_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Group BV (paise, ranks 1–2)</label>
                    <input type="number" name="group_bv_required_paise" value="{{ $row->group_bv_required_paise }}" min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->group_bv_required_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Qualifiers / side (ranks 3+)</label>
                    <input type="number" name="structural_qualifiers_per_side" value="{{ $row->structural_qualifiers_per_side }}" min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                </div>
                <label class="flex items-center gap-2 text-xs text-gray-600 mt-5">
                    <input type="checkbox" name="is_active" value="1" @checked($row->is_active)>
                    Active
                </label>
                <div class="flex items-end">
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Save</button>
                </div>
            </div>
        </form>
        @endforeach
    </div>
</section>

{{-- ── Fortune Bonus levels ──────────────────────────────────────────────── --}}
<section class="mb-10">
    <h2 class="text-base font-semibold text-gray-800 mb-3">Fortune Bonus — matrix levels</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        @foreach($fortuneLevels as $row)
        <form method="POST" action="{{ route('admin.compensation.plan-settings.fortune-level.update', $row->level) }}"
              data-confirm="Update Fortune level {{ $row->level }}?"
              data-confirm-title="Confirm: Fortune level {{ $row->level }}"
              data-confirm-impact="Changes the Fortune Bonus payout for this matrix level. Audit-logged; takes effect on the next monthly run."
              class="rounded-xl border border-gray-200 bg-white p-4 flex items-end gap-3">
            @csrf
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-600 mb-1">Level {{ $row->level }} bonus (paise)</label>
                <input type="number" name="bonus_paise" value="{{ $row->bonus_paise }}" required min="0"
                       class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                <span class="text-[11px] text-gray-400">{{ $rupees($row->bonus_paise) }}</span>
            </div>
            <label class="flex items-center gap-2 text-xs text-gray-600 pb-2">
                <input type="checkbox" name="is_active" value="1" @checked($row->is_active)>
                Active
            </label>
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Save</button>
        </form>
        @endforeach
    </div>
</section>

{{-- ── Fortune Bonus eligibility tiers ───────────────────────────────────── --}}
<section class="mb-10">
    <h2 class="text-base font-semibold text-gray-800 mb-3">Fortune Bonus — eligibility tiers</h2>
    <div class="space-y-3">
        @foreach($fortuneTiers as $row)
        <form method="POST" action="{{ route('admin.compensation.plan-settings.fortune-tier.update', $row->tier) }}"
              data-confirm="Update Fortune tier {{ $row->tier }}?"
              data-confirm-title="Confirm: Fortune tier {{ $row->tier }}"
              data-confirm-impact="Changes Fortune Bonus enrolment gates for this tier. Audit-logged; takes effect on the next monthly run."
              class="rounded-xl border border-gray-200 bg-white p-4 flex items-end gap-3">
            @csrf
            <span class="text-sm font-semibold text-gray-700 pb-2 w-28">{{ $row->tier }}</span>
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-600 mb-1">BV required (paise)</label>
                <input type="number" name="bv_required_paise" value="{{ $row->bv_required_paise }}" required min="0"
                       class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                <span class="text-[11px] text-gray-400">{{ $bv($row->bv_required_paise) }}</span>
            </div>
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-600 mb-1">GSB slabs required</label>
                <input type="number" name="slabs_required" value="{{ $row->slabs_required }}" required min="0"
                       class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
            </div>
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Save</button>
        </form>
        @endforeach
    </div>
</section>

@endsection
