@extends('admin.layouts.admin')
@section('title', 'Compensation Plan Settings')
@section('heading', 'Compensation Plan Settings')

@section('content')

@include('partials._toast-container')

@php
    $bv = fn ($paise) => $paise === null ? '—' : number_format($paise / 100, 0).' BV';
    $rupees = fn ($paise) => $paise === null ? '—' : '₹'.number_format($paise / 100, 2);
@endphp

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Edit the live compensation-plan ladders below. Each row is locked by default — press <strong>Edit</strong> to change it,
    then <strong>Save</strong> to review and confirm the change. All BV and money fields are stored in <strong>paise</strong>
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
              data-editable
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
                    <label class="block text-xs font-medium text-gray-600 mb-1">Title <x-help-tip text="The personal-purchase title a distributor reaches at this slab (display only)." /></label>
                    <input type="text" name="title" data-field-label="Title" value="{{ $row->title }}"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Title min BV (paise) <x-help-tip text="Minimum lifetime personal BV (in paise; BV × 100) needed to hold this title. Gates whether a distributor can earn at this slab." /></label>
                    <input type="number" name="title_min_bv_paise" data-field-label="Title min BV (paise)" value="{{ $row->title_min_bv_paise }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->title_min_bv_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Matched BV (paise) <x-help-tip text="The weaker-side matched BV (in paise) that triggers this slab's bonus at the daily GSB cut-off." /></label>
                    <input type="number" name="matched_bv_paise" data-field-label="Matched BV (paise)" value="{{ $row->matched_bv_paise }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->matched_bv_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Score <x-help-tip text="Points for this slab. The bonus is score × the per-point rate, so it recomputes on save. Leave blank to leave the bonus unset." /></label>
                    <input type="number" name="score" data-field-label="Score" data-score-input value="{{ $row->score }}" min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400" data-score-preview>→ {{ $row->score !== null ? '₹'.number_format(($row->score * $scoreRatePaise) / 100, 0) : '—' }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">AGP / occurrence <x-help-tip text="Arovolife Growth Points awarded each time this slab is earned (feeds the monthly Growth Booster pool)." /></label>
                    <input type="number" name="agp_per_occurrence" data-field-label="AGP / occurrence" value="{{ $row->agp_per_occurrence }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                </div>
                <label class="flex items-center gap-2 text-xs text-gray-600 mt-5">
                    <input type="checkbox" name="carry_forward_lifetime" data-field-label="Lifetime carry-forward" value="1" @checked($row->carry_forward_lifetime)>
                    Lifetime carry-forward <x-help-tip text="If on, the weaker side accumulates across days until the match completes (the slab-1 rule), instead of resetting at each daily cut-off." />
                </label>
                <label class="flex items-center gap-2 text-xs text-gray-600 mt-5">
                    <input type="checkbox" name="is_active" data-field-label="Active" value="1" @checked($row->is_active)>
                    Active <x-help-tip text="When off, this slab is skipped by the GSB engine." />
                </label>
                <div class="flex items-end gap-2">
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
              data-editable
              data-confirm="Update rank {{ $row->rank_number }} ({{ $row->rank_name }})?"
              data-confirm-title="Confirm: Rank {{ $row->rank_number }}"
              data-confirm-impact="Changes rank qualification and pool % for all distributors. Audit-logged; takes effect on the next monthly run."
              class="rounded-xl border border-gray-200 bg-white p-4">
            @csrf
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Rank {{ $row->rank_number }} name <x-help-tip text="Display name for this rank." /></label>
                    <input type="text" name="rank_name" data-field-label="Rank {{ $row->rank_number }} name" value="{{ $row->rank_name }}" required
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Pool % <x-help-tip text="This rank's share of the company Rank Bonus pool, as a percent of monthly turnover." /></label>
                    <input type="number" step="0.01" name="pool_pct" data-field-label="Pool %" value="{{ rtrim(rtrim(number_format($row->pool_pct, 2, '.', ''), '0'), '.') }}" required min="0" max="100"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">PYP required <x-help-tip text="Times the rank must be re-proven (Prove Your Position) within a month before it is confirmed." /></label>
                    <input type="number" name="pyp_required" data-field-label="PYP required" value="{{ $row->pyp_required }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Personal BV (paise) <x-help-tip text="Lifetime personal BV (paise) the distributor must hold to qualify for this rank." /></label>
                    <input type="number" name="personal_bv_required_paise" data-field-label="Personal BV (paise)" value="{{ $row->personal_bv_required_paise }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->personal_bv_required_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Group BV (paise, ranks 1–2) <x-help-tip text="Calendar-month Genos BV required on each side for ranks 1–2. Leave blank for ranks 3+, which use structural qualifiers instead." /></label>
                    <input type="number" name="group_bv_required_paise" data-field-label="Group BV (paise)" value="{{ $row->group_bv_required_paise }}" min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->group_bv_required_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Qualifiers / side (ranks 3+) <x-help-tip text="Number of lower-rank qualifiers required on each Genos side for ranks 3 and above." /></label>
                    <input type="number" name="structural_qualifiers_per_side" data-field-label="Qualifiers / side" value="{{ $row->structural_qualifiers_per_side }}" min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                </div>
                <label class="flex items-center gap-2 text-xs text-gray-600 mt-5">
                    <input type="checkbox" name="is_active" data-field-label="Active" value="1" @checked($row->is_active)>
                    Active <x-help-tip text="When off, this rank is skipped by the Rank Bonus engine." />
                </label>
                <div class="flex items-end gap-2">
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
              data-editable
              data-confirm="Update Fortune level {{ $row->level }}?"
              data-confirm-title="Confirm: Fortune level {{ $row->level }}"
              data-confirm-impact="Changes the Fortune Bonus payout for this matrix level. Audit-logged; takes effect on the next monthly run."
              class="rounded-xl border border-gray-200 bg-white p-4 flex items-end gap-3">
            @csrf
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-600 mb-1">Level {{ $row->level }} bonus (paise) <x-help-tip text="Per-member Fortune Bonus (paise) paid at this matrix level." /></label>
                <input type="number" name="bonus_paise" data-field-label="Level {{ $row->level }} bonus (paise)" value="{{ $row->bonus_paise }}" required min="0"
                       class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                <span class="text-[11px] text-gray-400">{{ $rupees($row->bonus_paise) }}</span>
            </div>
            <label class="flex items-center gap-2 text-xs text-gray-600 pb-2">
                <input type="checkbox" name="is_active" data-field-label="Active" value="1" @checked($row->is_active)>
                Active <x-help-tip text="When off, this matrix level pays nothing." />
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
              data-editable
              data-confirm="Update Fortune tier {{ $row->tier }}?"
              data-confirm-title="Confirm: Fortune tier {{ $row->tier }}"
              data-confirm-impact="Changes Fortune Bonus enrolment gates for this tier. Audit-logged; takes effect on the next monthly run."
              class="rounded-xl border border-gray-200 bg-white p-4 flex items-end gap-3">
            @csrf
            <span class="text-sm font-semibold text-gray-700 pb-2 w-28">{{ $row->tier }}</span>
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-600 mb-1">BV required (paise) <x-help-tip text="Monthly repurchase BV (paise) the distributor must complete to enter the Fortune Bonus at this tier." /></label>
                <input type="number" name="bv_required_paise" data-field-label="BV required (paise)" value="{{ $row->bv_required_paise }}" required min="0"
                       class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                <span class="text-[11px] text-gray-400">{{ $bv($row->bv_required_paise) }}</span>
            </div>
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-600 mb-1">GSB slabs required <x-help-tip text="Number of GSB slabs the distributor must earn in the month to be eligible at this tier." /></label>
                <input type="number" name="slabs_required" data-field-label="GSB slabs required" value="{{ $row->slabs_required }}" required min="0"
                       class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
            </div>
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Save</button>
        </form>
        @endforeach
    </div>
</section>

@push('scripts')
<script>
    // Live-recompute the GSB slab bonus preview (score × per-point rate) as the
    // admin edits the score, mirroring the server-side computation on save.
    (function () {
        var ratePaise = {{ $scoreRatePaise }};
        document.querySelectorAll('[data-score-input]').forEach(function (input) {
            var preview = input.parentNode.querySelector('[data-score-preview]');
            if (!preview) { return; }
            input.addEventListener('input', function () {
                var v = input.value.trim();
                if (v === '' || isNaN(Number(v))) { preview.textContent = '→ —'; return; }
                var rupees = Number(v) * ratePaise / 100;
                preview.textContent = '→ ₹' + rupees.toLocaleString('en-IN', { maximumFractionDigits: 0 });
            });
        });
    })();
</script>
@endpush

@endsection
