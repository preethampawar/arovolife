@extends('admin.layouts.admin')
@section('title', 'Compensation Plan Settings')
@section('heading', 'Compensation Plan Settings')

@section('content')

@include('partials._toast-container')

@php
    $bv = fn ($paise) => $paise === null ? '—' : \App\Modules\Shared\Support\IndianNumber::format($paise / 100, 0).' BV';
    $rupees = fn ($paise) => $paise === null ? '—' : '₹'.\App\Modules\Shared\Support\IndianNumber::format($paise / 100, 2);
    $activeTab = in_array(request('tab'), ['gsb', 'ranks', 'fortune'], true) ? request('tab') : 'gsb';
@endphp

<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    @if($canEdit)
    Edit the live compensation-plan ladders below. Each row is locked by default — press <strong>Edit</strong> to change it,
    then <strong>Save</strong> to review and confirm the change. All BV and money fields are stored in <strong>paise</strong>
    (BV × 100, ₹ × 100). Every change is audit-logged and takes effect on the next engine run.
    Rates, caps and periods (admin charge, TDS, repurchase %, grace days, etc.) are edited under
    <a href="{{ route('admin.settings') }}#compensation_plan" class="underline font-medium">Settings → Compensation plan</a>.
    @else
    The live compensation-plan ladders currently in force. All BV and money fields are shown in <strong>paise</strong>
    (BV × 100, ₹ × 100). Use the Compensation reports to see what each ladder actually paid.
    @endif
</div>

@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
@endif

{{-- How the two daily engines price themselves. Explanatory only — no control
     lives here, so it renders identically for every console role. --}}
<details class="mb-6 rounded-xl border border-gray-200 bg-white p-4" open>
    <summary class="cursor-pointer text-sm font-semibold text-gray-800">How GSB and MSB are calculated</summary>

    <div class="mt-4 grid gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
            <h3 class="text-xs font-semibold text-gray-700 mb-2">Genos Sales Bonus — 45% of the day's BV</h3>
            <ol class="list-decimal list-inside space-y-1 text-xs text-gray-600">
                <li>The day's <strong>pool</strong> is the GSB daily pool rate (45%) of the day's company-wide BV.</li>
                <li><strong>Slabs 1–2</strong> are paid first at their fixed score value (₹ per score point) and always pay in full.</li>
                <li>What remains is divided by the total scores of everyone who matched <strong>slabs 3–7</strong> that day, floored to whole rupees and <strong>capped</strong> at the fixed score value.</li>
                <li>Each slab 3–7 earner is paid <strong>their score × that one value</strong>.</li>
            </ol>
        </div>

        <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
            <h3 class="text-xs font-semibold text-gray-700 mb-2">Mentorship Bonus — 3% of the day's BV</h3>
            <ol class="list-decimal list-inside space-y-1 text-xs text-gray-600">
                <li>The day's <strong>pool</strong> is the MSB daily pool rate (3%) of the same company-wide BV.</li>
                <li>Each time a <strong>directly sponsored</strong> distributor's cut-off matches a slab, their sponsor accrues that slab's <strong>MSB score</strong> points.</li>
                <li><strong>Point value = pool ÷ the day's total MSB points</strong>, floored to whole rupees. There is no cap and no configured value.</li>
                <li>Each sponsor is paid <strong>their points × that one value</strong>, so the day's MSB spend is the 3% envelope.</li>
            </ol>
        </div>
    </div>

    <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50 p-3 text-xs text-blue-900">
        <p class="font-semibold mb-1">Worked example — a 1,00,000 BV day</p>
        <p class="mb-1 text-[11px] text-blue-700">Illustrative arithmetic only — not an earnings projection.</p>
        <p>
            MSB pool = 3% = <strong>₹3,000</strong>. Two sponsors accrue 21 points each (their sponsees matched slab 1)
            and one accrues 18 points (slab 2) — <strong>60 points</strong> in total.
            Point value = 3,000 ÷ 60 = <strong>₹50</strong>.
            The two slab-1 sponsors earn 21 × 50 = ₹1,050 each, the slab-2 sponsor earns 18 × 50 = ₹900 —
            <strong>₹3,000</strong> in total, exactly the pool.
        </p>
    </div>

    <p class="mt-3 text-[11px] text-gray-500">
        Both pool rates are set under
        <a href="{{ route('admin.settings') }}#compensation_plan" class="underline">Settings → Compensation plan</a>.
        Each day's arithmetic is frozen before anything is credited and is visible on the
        <a href="{{ route('admin.compensation.gsb-input-output.index') }}" class="underline">GSB</a> and
        <a href="{{ route('admin.compensation.msb-input-output.index') }}" class="underline">MSB</a>
        Input &amp; Output reports.
    </p>
</details>

{{-- Tabs --}}
<div class="flex border-b border-gray-200 mb-6">
    @foreach(['gsb' => 'GSB Slabs', 'ranks' => 'Rank Tiers', 'fortune' => 'Fortune Bonus'] as $key => $label)
    <a href="{{ route('admin.compensation.plan-settings.index', ['tab' => $key]) }}"
       class="px-4 py-2 text-sm font-medium border-b-2 -mb-px
              {{ $activeTab === $key ? 'border-brand-500 text-brand-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
        {{ $label }}
    </a>
    @endforeach
</div>

{{-- ── GSB slabs ─────────────────────────────────────────────────────────── --}}
@if($activeTab === 'gsb')
<section class="mb-10">
    <h2 class="text-base font-semibold text-gray-800 mb-1">GSB slabs</h2>
    <p class="text-xs text-gray-500 mb-3">
        Bonus follows the <strong>score × score value</strong> model. Slabs 1–2 carry a fixed, editable score
        value (₹ per score point) and always pay in full. Slabs 3–7 are <strong>pro-rated daily</strong> from the
        GSB pool (<a href="{{ route('admin.settings') }}#compensation_plan" class="underline">GSB daily pool rate</a>,
        default 45% of the day's company BV): their score and score value are shown read-only here, and the day's
        variable score value — never above the fixed value — is computed at each cut-off. Each slab also carries its
        <strong>MSB score</strong>: the Mentorship Bonus points credited to the direct sponsor when a sponsee's
        cut-off matches this slab. Those points have no configured rupee value — they are priced daily from the
        MSB pool (see the panel above).
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
            {{-- Plan values are shown to every console role for monitoring;
                 editing is reserved for platform configuration. A disabled
                 fieldset makes the whole block non-editable and unsubmittable
                 in one place, without touching each input. --}}
            <fieldset class="contents" @disabled(! $canEdit)>
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-semibold text-gray-700">Slab {{ $row->slab }}</span>
                <span class="text-xs text-gray-400">{{ $row->slab < 3 ? 'current bonus: '.$rupees($row->bonus_paise) : 'max bonus: '.$rupees($row->bonus_paise).' (pro-rated daily)' }}</span>
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
                @if($row->slab < 3)
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Score <x-help-tip text="Points for this slab. The bonus is score × this slab's score value, so it recomputes on save. Leave blank to leave the bonus unset." /></label>
                    <input type="number" name="score" data-field-label="Score" data-score-input value="{{ $row->score }}" min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400" data-score-preview>→ {{ $row->score !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format(($row->score * $row->score_value_paise) / 100, 0) : '—' }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Score value (₹) <x-help-tip text="Rupee value of one score point for this slab (KP 2026-07-21, default ₹250). The bonus is score × this value. Slabs 1–2 are fixed — always paid in full." /></label>
                    <input type="number" name="score_value_paise" data-field-label="Score value (paise)" data-score-value-input value="{{ $row->score_value_paise }}" required min="1"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->score_value_paise / 100, 2) }} / score</span>
                </div>
                @else
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Score <x-help-tip text="Points for this slab (fixed by the plan). Not editable: slabs 3–7 are priced daily from the GSB pool." /></label>
                    <div class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2 py-1.5 text-sm text-gray-600">{{ $row->score ?? '—' }}</div>
                    <span class="text-[11px] text-gray-400">→ up to {{ $row->score !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format(($row->score * $row->score_value_paise) / 100, 0) : '—' }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Score value (₹) <x-help-tip text="Variable — computed at each daily cut-off from the GSB pool (45% of the day's company BV), capped at this fixed value. See the GSB Input & Output report for each day's value." /></label>
                    <div class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2 py-1.5 text-sm text-gray-600">Variable (pool)</div>
                    <span class="text-[11px] text-gray-400">up to ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->score_value_paise / 100, 2) }} / score</span>
                </div>
                @endif
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">MSB score <x-help-tip text="Mentorship Bonus points credited to the direct sponsor each time a sponsee's cut-off matches this slab. The sponsor's MB income is MSB score × the day's point value (the MSB pool ÷ the day's total points) — there is no configured per-slab value." /></label>
                    <input type="number" name="msb_score" data-field-label="MSB score" data-msb-score-input value="{{ $row->msb_score }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400" data-msb-preview>→ {{ $row->msb_score }} points × the day's value</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">MSB point value (₹) <x-help-tip text="Variable — computed at each daily cut-off as the MSB pool (3% of the day's company BV) ÷ the day's total MSB score points, floored to whole rupees. See the MSB Input & Output report for each day's value." /></label>
                    <div class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2 py-1.5 text-sm text-gray-600">Variable (pool)</div>
                    <span class="text-[11px] text-gray-400">3% pool ÷ the day's points</span>
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
                    @if($canEdit)
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Save</button>
                    @endif
                </div>
            </div>
            </fieldset>
        </form>
        @endforeach
    </div>
</section>
@endif

{{-- ── Rank tiers ────────────────────────────────────────────────────────── --}}
@if($activeTab === 'ranks')
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
            {{-- Plan values are shown to every console role for monitoring;
                 editing is reserved for platform configuration. A disabled
                 fieldset makes the whole block non-editable and unsubmittable
                 in one place, without touching each input. --}}
            <fieldset class="contents" @disabled(! $canEdit)>
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
                    <label class="block text-xs font-medium text-gray-600 mb-1">Q-Period (PYP) <x-help-tip text="Times this rank must be achieved (distinct months) before the NEXT rank opens for the distributor. KP 2026-08-05: R1/R2 = 1, R3–R5 = 2, R6–R9 = 3." /></label>
                    <input type="number" name="pyp_required" data-field-label="Q-Period (PYP)" value="{{ $row->pyp_required }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">RAP points <x-help-tip text="Rank Achievement Points per achiever. When set, this rank's pool is divided by total points (achievers × RAP + AO-GO points) and each participant is paid points × point value. Leave blank for an equal split among achievers (ranks 2–9). KP 2026-08-05: Rank 1 = 10." /></label>
                    <input type="number" name="rap_points" data-field-label="RAP points" value="{{ $row->rap_points }}" min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Personal BV (paise) <x-help-tip text="Lifetime personal BV (paise) the distributor must hold to qualify for this rank." /></label>
                    <input type="number" name="personal_bv_required_paise" data-field-label="Personal BV (paise)" value="{{ $row->personal_bv_required_paise }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->personal_bv_required_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Genos BV (paise, ranks 1–2) <x-help-tip text="Calendar-month Genos BV required on each side for ranks 1–2. Leave blank for ranks 3+, which use structural qualifiers instead." /></label>
                    <input type="number" name="group_bv_required_paise" data-field-label="Genos BV (paise)" value="{{ $row->group_bv_required_paise }}" min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->group_bv_required_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Weaker-leg top-up (paise, ranks 1–2) <x-help-tip text="Cap on this month's personal BV that may supplement the weaker Genos leg toward the ranks 1–2 match (KP 2026-06-28: Rank 1 = 15,000 BV, Rank 2 = 30,000 BV). 0 for ranks 3+." /></label>
                    <input type="number" name="weaker_leg_topup_bv_paise" data-field-label="Weaker-leg top-up (paise)" value="{{ $row->weaker_leg_topup_bv_paise }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->weaker_leg_topup_bv_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Qualifiers / side (ranks 3+) <x-help-tip text="Number of lower-rank qualifiers required on each Genos side for ranks 3 and above." /></label>
                    <input type="number" name="structural_qualifiers_per_side" data-field-label="Qualifiers / side" value="{{ $row->structural_qualifiers_per_side }}" min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Carry-forward months <x-help-tip text="RETIRED (KP 2026-08-05, replaced by the AO-GO offer): extra months this rank kept paying a qualifier after the qualifying month. Now 0 for every rank; kept configurable for audit of historical rows." /></label>
                    <input type="number" name="carry_forward_months" data-field-label="Carry-forward months" value="{{ $row->carry_forward_months }}" required min="0" max="24"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Repurchase BV (paise) <x-help-tip text="Monthly repurchase BV this rank must complete each cycle to stay income-eligible. Stored in paise (BV × 100). KP: R1 1,000 … R9 2,300 BV." /></label>
                    <input type="number" name="repurchase_bv_paise" data-field-label="Repurchase BV (paise)" value="{{ $row->repurchase_bv_paise }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->repurchase_bv_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Lifetime award budget (paise) <x-help-tip text="Per-rank Lifetime Awards budget (non-cash). Stored in paise (₹ × 100). The itemised reward worths (Lifetime Awards → Reward catalog) reconcile to this. KP: R1 ₹15,000 … R9 ₹2.25Cr." /></label>
                    <input type="number" name="lifetime_award_budget_paise" data-field-label="Lifetime award budget (paise)" value="{{ $row->lifetime_award_budget_paise }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400">{{ $rupees($row->lifetime_award_budget_paise) }}</span>
                </div>
                <label class="flex items-center gap-2 text-xs text-gray-600 mt-5">
                    <input type="checkbox" name="is_active" data-field-label="Active" value="1" @checked($row->is_active)>
                    Active <x-help-tip text="When off, this rank is skipped by the Rank Bonus engine." />
                </label>
                <div class="flex items-end gap-2">
                    @if($canEdit)
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Save</button>
                    @endif
                </div>
            </div>
            </fieldset>
        </form>
        @endforeach
    </div>
</section>
@endif

{{-- ── Fortune Bonus levels ──────────────────────────────────────────────── --}}
@if($activeTab === 'fortune')
<section class="mb-10">
    <h2 class="text-base font-semibold text-gray-800 mb-3">Fortune Bonus — matrix levels</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        @foreach($fortuneLevels as $row)
        <form method="POST" action="{{ route('admin.compensation.plan-settings.fortune-level.update', $row->level) }}"
              data-editable
              data-confirm="Update Fortune level {{ $row->level }}?"
              data-confirm-title="Confirm: Fortune level {{ $row->level }}"
              data-confirm-impact="Changes the FB points earned from each downline member at this matrix depth, which changes every participant's share of the monthly pool. Audit-logged; takes effect on the next monthly run."
              class="rounded-xl border border-gray-200 bg-white p-4 flex items-end gap-3">
            @csrf
            {{-- Plan values are shown to every console role for monitoring;
                 editing is reserved for platform configuration. A disabled
                 fieldset makes the whole block non-editable and unsubmittable
                 in one place, without touching each input. --}}
            <fieldset class="contents" @disabled(! $canEdit)>
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-600 mb-1">Points per member at level {{ $row->level }} <x-help-tip text="FB points a participant earns for each enrolled distributor sitting this many levels below them in the month's matrix. The rupee value of a point is not set here — it is the month's pool divided by everyone's total points." /></label>
                <input type="number" name="points_per_member" data-field-label="Points per member at level {{ $row->level }}" value="{{ $row->points_per_member }}" required min="0"
                       class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                <span class="text-[11px] text-gray-400">{{ \App\Modules\Shared\Support\IndianNumber::format($row->points_per_member) }} pts per member</span>
            </div>
            <label class="flex items-center gap-2 text-xs text-gray-600 pb-2">
                <input type="checkbox" name="is_active" data-field-label="Active" value="1" @checked($row->is_active)>
                Active <x-help-tip text="Marks the level as in force in the ladder. To stop a level earning, set its points per member to 0." />
            </label>
            @if($canEdit)
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Save</button>
            @endif
            </fieldset>
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
              class="rounded-xl border border-gray-200 bg-white p-4">
            @csrf
            {{-- Plan values are shown to every console role for monitoring;
                 editing is reserved for platform configuration. A disabled
                 fieldset makes the whole block non-editable and unsubmittable
                 in one place, without touching each input. --}}
            <fieldset class="contents" @disabled(! $canEdit)>
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-semibold text-gray-700">{{ ucwords(str_replace('_', ' ', $row->tier)) }}</span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">BV required (paise) <x-help-tip text="Monthly repurchase BV (paise) the distributor must complete to enter the Fortune Bonus at this tier." /></label>
                    <input type="number" name="bv_required_paise" data-field-label="BV required (paise)" value="{{ $row->bv_required_paise }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                    <span class="text-[11px] text-gray-400">{{ $bv($row->bv_required_paise) }}</span>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">GSB slabs required <x-help-tip text="Number of GSB slabs the distributor must earn in the month to be eligible at this tier." /></label>
                    <input type="number" name="slabs_required" data-field-label="GSB slabs required" value="{{ $row->slabs_required }}" required min="0"
                           class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500">
                </div>
                <div class="flex items-end gap-2">
                    @if($canEdit)
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Save</button>
                    @endif
                </div>
            </div>
            </fieldset>
        </form>
        @endforeach
    </div>
</section>
@endif

@push('scripts')
<script>
    // Live-recompute the GSB slab bonus preview (score × this slab's score value)
    // as the admin edits either field, mirroring the server-side computation on save.
    (function () {
        document.querySelectorAll('[data-score-input]').forEach(function (scoreInput) {
            var form = scoreInput.closest('form');
            if (!form) { return; }
            var preview = form.querySelector('[data-score-preview]');
            var valueInput = form.querySelector('[data-score-value-input]');
            if (!preview || !valueInput) { return; }
            var recompute = function () {
                var s = scoreInput.value.trim();
                var vp = valueInput.value.trim();
                if (s === '' || isNaN(Number(s)) || vp === '' || isNaN(Number(vp))) { preview.textContent = '→ —'; return; }
                var rupees = Number(s) * Number(vp) / 100;
                preview.textContent = '→ ₹' + rupees.toLocaleString('en-IN', { maximumFractionDigits: 0 });
            };
            scoreInput.addEventListener('input', recompute);
            valueInput.addEventListener('input', recompute);
        });

        // Mentorship Bonus points carry no configured rupee value any more —
        // they are priced daily from the pool — so the preview only echoes the
        // points being entered.
        document.querySelectorAll('[data-msb-score-input]').forEach(function (scoreInput) {
            var form = scoreInput.closest('form');
            if (!form) { return; }
            var preview = form.querySelector('[data-msb-preview]');
            if (!preview) { return; }
            scoreInput.addEventListener('input', function () {
                var s = scoreInput.value.trim();
                preview.textContent = (s === '' || isNaN(Number(s)))
                    ? '→ —'
                    : '→ ' + s + " points × the day's value";
            });
        });
    })();
</script>
@endpush

@endsection
