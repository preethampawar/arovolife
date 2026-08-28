<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Enums\BonusType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for every tunable compensation-plan parameter.
 *
 * All engine services read their rates, caps, thresholds and ladders through
 * this service instead of hardcoded constants, so the plan can be changed from
 * the admin UI (settings registry + the gsb_slabs / rank_tiers /
 * fortune_bonus_levels / fortune_bonus_tiers tables) without code edits.
 *
 * Bound as a singleton (see CompensationServiceProvider) so the scalar map and
 * each ladder are loaded at most once per request/engine run.
 *
 * Rates are stored as integer basis points (5% = 500) because the settings
 * registry has no float type. Divide by 10,000 to get the multiplier.
 */
final class CompensationPlanSettingsService
{
    /**
     * Wallet ledger entry types belonging to each payout admin-charge group.
     * Each group has its own ₹25,000/cycle ceiling (KP 2026-06-30 Round-5).
     * Group C (Awards) carries no admin charge by default (non-cash gifts).
     * ADC is Group D — separate ceiling from the five cash bonuses.
     */
    public const GROUP_A_TYPES = ['gsb_credit', 'mb_credit'];

    public const GROUP_B_TYPES = ['gbb_credit', 'rank_credit', 'fortune_credit'];

    public const GROUP_C_TYPES = ['awards_credit'];

    public const GROUP_D_TYPES = ['adc_credit'];

    /**
     * All five cash-bonus types that count toward the ₹50L monthly gross cap
     * (KP 2026-06-29 Round-4): GSB + MB + GBB + Rank + Fortune.
     * ADC and Awards are excluded from the cap.
     */
    public const MONTHLY_CAP_TYPES = ['gsb_credit', 'mb_credit', 'gbb_credit', 'rank_credit', 'fortune_credit'];

    /** Registry defaults — used when a key is absent from the settings table. */
    private const SCALAR_DEFAULTS = [
        'comp.admin_charge.rate_bp' => 300,
        // Per-group admin-charge ceilings (KP 2026-06-30 Round-5).
        // Group A = GSB+MB (weekly), Groups B/C/D = monthly streams.
        // Each group has an independent ₹25,000/cycle ceiling.
        'comp.admin_charge.weekly_cap_paise' => 2_500_000,
        'comp.admin_charge.monthly_cap_paise' => 2_500_000,
        // Combined monthly gross cap across the five cash bonuses (KP Round-4).
        // ₹50,00,000 = 500,000,000 paise. Combined gross of the five cash
        // bonuses (MONTHLY_CAP_TYPES) per calendar month; excess is forfeited
        // at payout with an income_cap_forfeit ledger debit.
        // ADC and Awards are excluded from this cap.
        'comp.monthly_income_cap_paise' => 500_000_000,
        // Admin charge scope flags. ON for the six cash bonuses; OFF for
        // Lifetime Awards — KP confirmed (2026-06-27 Round-2 Q6) that non-cash
        // awards carry no admin charge or TDS (only cash/cheque releases do).
        'comp.admin_charge.applies_to_gsb' => true,
        'comp.admin_charge.applies_to_mb' => true,
        'comp.admin_charge.applies_to_rank' => true,
        'comp.admin_charge.applies_to_gbb' => true,
        'comp.admin_charge.applies_to_fortune' => true,
        'comp.admin_charge.applies_to_adc' => true,
        'comp.admin_charge.applies_to_awards' => false,
        'comp.tds.rate_bp' => 500,
        'comp.gsb.power_cf_cap_paise' => 45_000_000,
        'comp.gsb.min_bv_paise' => 60_000,
        // Daily GSB pool for the pro-rated slabs 3–7 (KP 2026-07-29): share of
        // the day's company-wide BV (bv_ledger_entries signed sum) that funds
        // GSB. Slabs 1–2 are paid fixed out of it first; the remainder is
        // divided by the day's total slab 3–7 scores (capped at the per-slab
        // score value). 4500 bp = 45%.
        'comp.gsb.pool_rate_bp' => 4500,
        // Personal-BV top-up go-live (KP 2026-07-21). Accruals dated before this
        // are excluded from the conditional top-up pending pool — the old engine
        // already credited them daily. Permissive default so tests/fresh envs put
        // no lower bound; production pins it to the deploy date via GsbSlabsSeeder.
        'comp.gsb.topup_golive_date' => '1970-01-01',
        // Daily MSB pool (KP 2026-07-30): share of the day's company-wide BV
        // (same signed bv_ledger_entries sum as the GSB pool) that funds the
        // Mentorship Bonus. Divided by the day's total MSB score points to give
        // one point value for every earner. 300 bp = 3%.
        'comp.msb.pool_rate_bp' => 300,
        'comp.gbb.pool_rate_bp' => 500,
        'comp.gbb.agp_cap' => 120,
        'comp.adc.rate_bp' => 300,
        'comp.adc.cap_paise' => 10_000_000,
        // Rank Bonus envelope (KP 2026-08-05): share of company BV set aside for
        // ALL nine rank pools together. 2000 bp = 20%.
        'comp.rank.envelope_bp' => 2_000,
        // AO-GO offer (KP 2026-08-05): points a degraded ex-rank-holder earns
        // in the Rank-1 pool, and the lifetime cap on how many times the offer
        // can be used.
        'comp.rank.aogo_points' => 5,
        'comp.rank.aogo_lifetime_max' => 3,
        // Exclusive (true) vs cumulative (false) rank pools. Exclusive pays a
        // multi-rank qualifier only their highest rank — the plan-text reading
        // ("reaching Rank 2 cancels the Rank-1 benefit"). Cumulative pays every
        // rank they cleared. Awaiting the product owner's ruling (2026-08-06);
        // default follows the written plan.
        'comp.rank.pay_highest_rank_only' => true,
        'comp.repurchase.rate_bp' => 1000,
        'comp.repurchase.cap_paise' => 1_000_000,
        'comp.repurchase.grace_days' => 0,
        'comp.repurchase.non_ranked_bv_paise' => 60_000,
        'payout.min_threshold_paise' => 10_000,
        'payout.neft_min_bv_paise' => 300_000,
        // Monthly Fortune Bonus pool (KP 2026-08-07): share of the month's
        // company-wide BV (the same signed bv_ledger_entries sum as every other
        // pool) divided by the month's total FB points. 500 bp = 5%, which
        // supersedes the June envelope's 6% Fortune share.
        'comp.fortune.pool_rate_bp' => 500,
        // Minimum commission every Fortune qualifier receives (KP 2026-08-09:
        // ₹30), reserved off the pool before the level cascade distributes.
        'comp.fortune.min_commission_paise' => 3000,
        // Fortune Bonus excludes ranks 6–9 by default (KP-confirmed).
        'comp.fortune.exclude_rank_6' => true,
        'comp.fortune.exclude_rank_7' => true,
        'comp.fortune.exclude_rank_8' => true,
        'comp.fortune.exclude_rank_9' => true,
    ];

    /** @var array<string, string>|null Lazily-loaded settings key→value map. */
    private ?array $scalarCache = null;

    /** @var array<int, array{slab: int, title: string, title_min_bv_paise: int, matched_bv_paise: int, score: int|null, score_value_paise: int, msb_score: int, bonus_paise: int|null, agp_per_occurrence: int, carry_forward_lifetime: bool, is_active: bool}>|null gsb_slabs keyed by slab. */
    private ?array $gsbSlabCache = null;

    /** @var array<int, array<string, mixed>>|null rank_tiers keyed by rank_number. */
    private ?array $rankTierCache = null;

    /** @var array<int, list<array{item: string, worth_paise: int}>>|null lifetime award items keyed by rank. */
    private ?array $lifetimeAwardCache = null;

    /** @var array<int, int>|null fortune matrix depth → points_per_member. */
    private ?array $fortunePointsCache = null;

    /** @var array<int, array{payout_mode: string, cap_paise: ?int, points_per_member: int}>|null fortune cascade config keyed by absolute matrix level. */
    private ?array $fortuneLevelConfigCache = null;

    /** @var array<string, array{bv_required_paise: int, slabs_required: int}>|null */
    private ?array $fortuneTierCache = null;

    // ── Scalar accessors ───────────────────────────────────────────────────

    public function adminChargeRateBp(): int
    {
        return $this->scalarInt('comp.admin_charge.rate_bp');
    }

    /** Per-cycle admin-charge ceiling for Group A (GSB + MB, weekly). */
    public function adminChargeWeeklyCapPaise(): int
    {
        return $this->scalarInt('comp.admin_charge.weekly_cap_paise');
    }

    /** Per-cycle admin-charge ceiling for Group B/C/D (GBB/Rank/Fortune/Awards/ADC, monthly). */
    public function adminChargeMonthlyCapPaise(): int
    {
        return $this->scalarInt('comp.admin_charge.monthly_cap_paise');
    }

    /** Combined monthly gross cap for the five cash bonuses (₹50L = 500_000_000 paise). */
    public function monthlyIncomeCapPaise(): int
    {
        return $this->scalarInt('comp.monthly_income_cap_paise');
    }

    /**
     * Whether the admin charge applies to the given bonus stream. Driven by the
     * per-bonus `comp.admin_charge.applies_to_{value}` toggle (default true for
     * all seven, per KP 2026-06-27), so admins can exempt a stream from the UI.
     */
    public function adminChargeAppliesTo(BonusType $bonus): bool
    {
        return $this->scalarBool('comp.admin_charge.applies_to_'.$bonus->value);
    }

    public function tdsRateBp(): int
    {
        return $this->scalarInt('comp.tds.rate_bp');
    }

    public function gsbPowerCfCapPaise(): int
    {
        return $this->scalarInt('comp.gsb.power_cf_cap_paise');
    }

    /**
     * The personal-BV top-up go-live date. Personal-BV accruals dated before it
     * never enter the conditional weaker-leg top-up pending pool (the old engine
     * credited them daily before cut-over).
     */
    public function gsbTopupGoliveDate(): Carbon
    {
        return Carbon::parse($this->scalar('comp.gsb.topup_golive_date')
            ?? self::SCALAR_DEFAULTS['comp.gsb.topup_golive_date'])->startOfDay();
    }

    /**
     * Smallest matched-BV threshold across active, payable slabs — the point at
     * which a Genos leg "touches a slab" and unlocks the conditional personal-BV
     * top-up (KP 2026-07-21). Returns 0 if no slab qualifies (top-up unreachable).
     */
    public function gsbMinSlabMatchedBvPaise(): int
    {
        $min = null;
        foreach ($this->gsbSlabs() as $slab) {
            if (! $slab['is_active'] || $slab['bonus_paise'] === null) {
                continue;
            }
            $min = $min === null ? $slab['matched_bv_paise'] : min($min, $slab['matched_bv_paise']);
        }

        return $min ?? 0;
    }

    /**
     * Minimum lifetime personal BV (paise) before bonuses are credited.
     *
     * The pre-service `payout.gsb_min_bv_paise` key was migrated into
     * `comp.gsb.min_bv_paise` on 2026-08-07 and is no longer read.
     */
    public function gsbMinBvPaise(): int
    {
        return $this->scalarInt('comp.gsb.min_bv_paise');
    }

    /**
     * Share of the day's company-wide BV that funds the daily Mentorship Bonus
     * pool (KP 2026-07-30). Basis points: 300 = 3%.
     */
    public function msbPoolRateBp(): int
    {
        return $this->scalarInt('comp.msb.pool_rate_bp');
    }

    /**
     * Share of the day's company-wide BV that funds the daily GSB pool for the
     * pro-rated slabs 3–7 (KP 2026-07-29). Basis points: 4500 = 45%.
     */
    public function gsbPoolRateBp(): int
    {
        return $this->scalarInt('comp.gsb.pool_rate_bp');
    }

    public function gbbPoolRateBp(): int
    {
        return $this->scalarInt('comp.gbb.pool_rate_bp');
    }

    public function gbbAgpCap(): int
    {
        return $this->scalarInt('comp.gbb.agp_cap');
    }

    public function adcRateBp(): int
    {
        return $this->scalarInt('comp.adc.rate_bp');
    }

    public function adcCapPaise(): int
    {
        return $this->scalarInt('comp.adc.cap_paise');
    }

    public function repurchaseRateBp(): int
    {
        return $this->scalarInt('comp.repurchase.rate_bp');
    }

    public function repurchaseCapPaise(): int
    {
        return $this->scalarInt('comp.repurchase.cap_paise');
    }

    public function repurchaseGraceDays(): int
    {
        return $this->scalarInt('comp.repurchase.grace_days');
    }

    /** Monthly repurchase BV (paise) for a distributor with no rank yet. */
    public function nonRankedRepurchaseBvPaise(): int
    {
        return $this->scalarInt('comp.repurchase.non_ranked_bv_paise');
    }

    /**
     * Monthly repurchase BV obligation (paise) for the given rank, from the
     * admin-editable rank_tiers.repurchase_bv_paise column. 0 if unset.
     */
    public function rankRepurchaseBvPaise(int $rank): int
    {
        return (int) ($this->rankTiers()[$rank]['repurchase_bv_paise'] ?? 0);
    }

    /** Per-rank Lifetime Awards budget (paise). Admin-editable on rank_tiers. */
    public function lifetimeAwardBudgetPaise(int $rank): int
    {
        return (int) ($this->rankTiers()[$rank]['lifetime_award_budget_paise'] ?? 0);
    }

    /**
     * The itemised Lifetime Awards reward catalogue for a rank, ordered, from
     * the admin-editable lifetime_award_rewards table.
     *
     * @return list<array{item: string, worth_paise: int}>
     */
    public function lifetimeAwardRewards(int $rank): array
    {
        if ($this->lifetimeAwardCache === null) {
            $this->lifetimeAwardCache = [];
            foreach (DB::table('lifetime_award_rewards')->orderBy('rank_number')->orderBy('sort_order')->get() as $row) {
                $this->lifetimeAwardCache[(int) $row->rank_number][] = [
                    'item' => (string) $row->item,
                    'worth_paise' => (int) $row->worth_paise,
                ];
            }
        }

        return $this->lifetimeAwardCache[$rank] ?? [];
    }

    public function minPayoutPaise(): int
    {
        return $this->scalarInt('payout.min_threshold_paise');
    }

    public function neftMinBvPaise(): int
    {
        return $this->scalarInt('payout.neft_min_bv_paise');
    }

    /**
     * Rank numbers excluded from the Fortune Bonus (KP: ranks 6–9). Built from
     * the per-rank `comp.fortune.exclude_rank_N` boolean toggles.
     *
     * @return array<int, int>
     */
    public function fortuneIneligibleRanks(): array
    {
        $ranks = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $rank) {
            if ($this->scalarBool('comp.fortune.exclude_rank_'.$rank)) {
                $ranks[] = $rank;
            }
        }

        return $ranks;
    }

    // ── Deduction helpers (shared so every engine computes identically) ──────

    /** TDS = round(base × tds_rate). Caller decides the base (gross vs net). */
    public function tds(int $basePaise): int
    {
        return (int) round($basePaise * $this->tdsRateBp() / 10_000);
    }

    // ── GSB slabs ────────────────────────────────────────────────────────────

    /**
     * All GSB slabs keyed by slab number (inactive rows included so callers can
     * decide to skip them).
     *
     * @return array<int, array{slab: int, title: string, title_min_bv_paise: int, matched_bv_paise: int, score: int|null, score_value_paise: int, msb_score: int, bonus_paise: int|null, agp_per_occurrence: int, carry_forward_lifetime: bool, is_active: bool}>
     */
    public function gsbSlabs(): array
    {
        if ($this->gsbSlabCache === null) {
            $this->gsbSlabCache = [];
            foreach (DB::table('gsb_slabs')->orderBy('slab')->get() as $row) {
                $this->gsbSlabCache[(int) $row->slab] = [
                    'slab' => (int) $row->slab,
                    'title' => $row->title,
                    'title_min_bv_paise' => (int) $row->title_min_bv_paise,
                    'matched_bv_paise' => (int) $row->matched_bv_paise,
                    'score' => $row->score !== null ? (int) $row->score : null,
                    'score_value_paise' => (int) $row->score_value_paise,
                    'msb_score' => (int) $row->msb_score,
                    'bonus_paise' => $row->bonus_paise !== null ? (int) $row->bonus_paise : null,
                    'agp_per_occurrence' => (int) $row->agp_per_occurrence,
                    'carry_forward_lifetime' => (bool) $row->carry_forward_lifetime,
                    'is_active' => (bool) $row->is_active,
                ];
            }
        }

        return $this->gsbSlabCache;
    }

    /** @return array{slab: int, title: string, title_min_bv_paise: int, matched_bv_paise: int, score: int|null, score_value_paise: int, msb_score: int, bonus_paise: int|null, agp_per_occurrence: int, carry_forward_lifetime: bool, is_active: bool}|null */
    public function gsbSlab(int $slab): ?array
    {
        return $this->gsbSlabs()[$slab] ?? null;
    }

    /**
     * One-line summary of the active matched-BV ladder for admin help tooltips,
     * e.g. "Slab 1=15K, 2=36K, 3=1L, … BV matched on the weaker side."
     *
     * Derived from gsb_slabs rather than written out, so an admin plan edit can
     * never leave a tooltip quoting a retired threshold — which is exactly what
     * the 2026-07-21 "New Engine" revision did to the daily cut-off screens.
     */
    public function gsbSlabThresholdSummary(): string
    {
        $parts = [];
        foreach ($this->gsbSlabs() as $slab) {
            if (! $slab['is_active']) {
                continue;
            }
            $parts[] = $slab['slab'].'='.self::compactBv(intdiv($slab['matched_bv_paise'], 100));
        }

        if ($parts === []) {
            return 'No GSB slab is currently active.';
        }

        return 'Slab '.implode(', ', $parts).' BV matched on the weaker side.';
    }

    /** Indian short form for a BV figure: 15000 → "15K", 100000 → "1L", 8100000 → "81L". */
    private static function compactBv(int $bv): string
    {
        [$divisor, $suffix] = match (true) {
            $bv >= 100_000 => [100_000, 'L'],
            $bv >= 1_000 => [1_000, 'K'],
            default => [1, ''],
        };

        return rtrim(rtrim(number_format($bv / $divisor, 2, '.', ''), '0'), '.').$suffix;
    }

    /**
     * The personal-BV → title ladder, ascending by threshold, shaped for
     * PersonalBvTitleService. Only active slabs participate in title resolution.
     *
     * @return array<int, array{threshold: int, title: string, slab: int}>
     */
    public function titleLadder(): array
    {
        $ladder = [];
        foreach ($this->gsbSlabs() as $slab) {
            if (! $slab['is_active'] || $slab['title'] === null) {
                continue;
            }
            $ladder[] = [
                'threshold' => $slab['title_min_bv_paise'],
                'title' => (string) $slab['title'],
                'slab' => $slab['slab'],
            ];
        }

        usort($ladder, fn (array $a, array $b): int => $a['threshold'] <=> $b['threshold']);

        return $ladder;
    }

    /**
     * AGP awarded per occurrence of each GSB slab (slabs with 0 AGP omitted),
     * replacing GbbMonthlyResult::AGP_BY_SLAB.
     *
     * @return array<int, int>
     */
    public function agpBySlab(): array
    {
        $map = [];
        foreach ($this->gsbSlabs() as $slab) {
            if ($slab['agp_per_occurrence'] > 0) {
                $map[$slab['slab']] = $slab['agp_per_occurrence'];
            }
        }

        return $map;
    }

    // ── Rank tiers ───────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> keyed by rank_number */
    public function rankTiers(): array
    {
        if ($this->rankTierCache === null) {
            $this->rankTierCache = [];
            foreach (DB::table('rank_tiers')->orderBy('rank_number')->get() as $row) {
                $this->rankTierCache[(int) $row->rank_number] = [
                    'rank_number' => (int) $row->rank_number,
                    'rank_name' => (string) $row->rank_name,
                    'pool_pct' => (float) $row->pool_pct,
                    'pyp_required' => (int) $row->pyp_required,
                    'rap_points' => ($row->rap_points ?? null) !== null ? (int) $row->rap_points : null,
                    'personal_bv_required_paise' => (int) $row->personal_bv_required_paise,
                    'group_bv_required_paise' => $row->group_bv_required_paise !== null ? (int) $row->group_bv_required_paise : null,
                    'weaker_leg_topup_bv_paise' => (int) ($row->weaker_leg_topup_bv_paise ?? 0),
                    'structural_qualifiers_per_side' => $row->structural_qualifiers_per_side !== null ? (int) $row->structural_qualifiers_per_side : null,
                    'repurchase_bv_paise' => (int) ($row->repurchase_bv_paise ?? 0),
                    'lifetime_award_budget_paise' => (int) ($row->lifetime_award_budget_paise ?? 0),
                    'is_active' => (bool) $row->is_active,
                ];
            }
        }

        return $this->rankTierCache;
    }

    public function rankPoolPct(int $rank): float
    {
        return (float) ($this->rankTiers()[$rank]['pool_pct'] ?? 0.0);
    }

    /**
     * Share of company BV (the signed bv_ledger_entries sum for the month) that
     * funds the Rank Bonus envelope. Basis points: 2000 = 20%.
     *
     * Each rank's `rank_tiers.pool_pct` is a share OF THIS ENVELOPE, not of
     * turnover directly — which is why the seeded per-rank percentages
     * (7.00, 3.40, 2.70, 2.20, 1.70, 1.20, 0.90, 0.60, 0.30) sum to exactly 20
     * and are stored verbatim as the product owner writes them.
     *
     * Worked example (product owner 2026-08-05): 10,00,000 BV in the month →
     * 20% envelope = 2,00,000 → Rank 1's 7% share = ₹14,000.
     */
    public function rankEnvelopeBp(): int
    {
        return $this->scalarInt('comp.rank.envelope_bp');
    }

    /**
     * Exclusive (true) vs cumulative (false) rank pools. Exclusive pays a
     * multi-rank qualifier only their highest rank for the month; cumulative
     * pays every rank whose bar they cleared. See the SCALAR_DEFAULTS note.
     */
    public function rankPayHighestOnly(): bool
    {
        return $this->scalarBool('comp.rank.pay_highest_rank_only');
    }

    /**
     * The Q-Period (a.k.a. PYP / qualified count): how many times this rank
     * must be achieved before the next rank opens (KP 2026-08-05).
     */
    public function rankPypRequired(int $rank): int
    {
        return (int) ($this->rankTiers()[$rank]['pyp_required'] ?? 1);
    }

    /**
     * Rank Achievement Points per achiever (KP 2026-08-05). Non-null switches
     * the rank's pool to points division (pool ÷ (Σ RAP + Σ AO-GO points));
     * null keeps the equal split among achievers. Seeded: Rank 1 = 10, 2–9 null.
     */
    public function rankRapPoints(int $rank): ?int
    {
        $value = $this->rankTiers()[$rank]['rap_points'] ?? null;

        return $value !== null ? (int) $value : null;
    }

    /** AO-GO points a degraded ex-rank-holder earns in the Rank-1 pool. */
    public function aogoPointsPerGrant(): int
    {
        return $this->scalarInt('comp.rank.aogo_points');
    }

    /** Lifetime cap on AO-GO grants per distributor. */
    public function aogoLifetimeMax(): int
    {
        return $this->scalarInt('comp.rank.aogo_lifetime_max');
    }

    public function rankPersonalBvRequired(int $rank): int
    {
        return (int) ($this->rankTiers()[$rank]['personal_bv_required_paise'] ?? 0);
    }

    public function rankGroupBvRequired(int $rank): ?int
    {
        return $this->rankTiers()[$rank]['group_bv_required_paise'] ?? null;
    }

    /**
     * Cap (paise) on the personal BV that may supplement the weaker Genos leg
     * toward this rank's group-BV match (KP 2026-06-28: R1 15,000 BV, R2 30,000
     * BV; Ranks 3-9 = 0). Admin-editable on rank_tiers.
     */
    public function rankWeakerLegTopupBvPaise(int $rank): int
    {
        return (int) ($this->rankTiers()[$rank]['weaker_leg_topup_bv_paise'] ?? 0);
    }

    public function rankStructuralQualifiersPerSide(int $rank): int
    {
        return (int) ($this->rankTiers()[$rank]['structural_qualifiers_per_side'] ?? 2);
    }

    public function rankName(int $rank): string
    {
        return (string) ($this->rankTiers()[$rank]['rank_name'] ?? ('Rank '.$rank));
    }

    /**
     * All rank names keyed by rank number (display convenience for admin/views).
     *
     * @return array<int, string>
     */
    public function rankNames(): array
    {
        $names = [];
        foreach ($this->rankTiers() as $rank => $tier) {
            $names[$rank] = (string) $tier['rank_name'];
        }

        return $names;
    }

    // ── Fortune Bonus ────────────────────────────────────────────────────────

    /**
     * Share of the month's company-wide BV funding the Fortune Bonus pool
     * (KP 2026-08-07: 5%, superseding the June envelope's 6% Fortune share).
     */
    public function fortunePoolRateBp(): int
    {
        return $this->scalarInt('comp.fortune.pool_rate_bp');
    }

    /**
     * The ₹30 minimum commission every Fortune qualifier receives
     * (KP 2026-08-09), reserved off the pool before the cascade distributes.
     */
    public function fortuneMinCommissionPaise(): int
    {
        return $this->scalarInt('comp.fortune.min_commission_paise');
    }

    /**
     * FB points a participant earns for one enrolled distributor sitting
     * $depth levels below them in the month's Fortune matrix (KP 2026-08-09:
     * 9/8/7/6/5/4/3/2/1 for depths 1–9 — 1L-9P … 9L-1P). Depth 0 — yourself —
     * and anything deeper than the 9-level matrix are worth nothing.
     */
    public function fortunePointsForDepth(int $depth): int
    {
        if ($depth <= 0 || $depth > 9) {
            return 0;
        }

        return $this->fortuneLevelPoints()[$depth] ?? 0;
    }

    /**
     * All Fortune matrix depth → points_per_member (display convenience for
     * admin; the engine reads it through fortunePointsForDepth()).
     *
     * @return array<int, int>
     */
    public function fortuneLevelPoints(): array
    {
        if ($this->fortunePointsCache === null) {
            $this->fortunePointsCache = [];
            foreach (DB::table('fortune_bonus_levels')->orderBy('level')->get() as $row) {
                $this->fortunePointsCache[(int) $row->level] = (int) $row->points_per_member;
            }
        }

        return $this->fortunePointsCache;
    }

    /**
     * Full Fortune cascade config keyed by ABSOLUTE matrix level 0–9
     * (KP 2026-08-09): the payout mode ('capped' / 'residual' / 'flat_min')
     * and, for capped levels, the per-member ceiling in paise. Note the
     * fortune_bonus_levels table's double duty — points_per_member is read by
     * relative depth via fortunePointsForDepth(), mode and cap by absolute
     * level here.
     *
     * @return array<int, array{payout_mode: string, cap_paise: ?int, points_per_member: int}>
     */
    public function fortuneLevelConfigs(): array
    {
        if ($this->fortuneLevelConfigCache === null) {
            $this->fortuneLevelConfigCache = [];
            foreach (DB::table('fortune_bonus_levels')->orderBy('level')->get() as $row) {
                $this->fortuneLevelConfigCache[(int) $row->level] = [
                    'payout_mode' => (string) $row->payout_mode,
                    'cap_paise' => $row->cap_paise === null ? null : (int) $row->cap_paise,
                    'points_per_member' => (int) $row->points_per_member,
                ];
            }
        }

        return $this->fortuneLevelConfigCache;
    }

    /** @return array{bv_required_paise: int, slabs_required: int} */
    public function fortuneTier(string $tier): array
    {
        if ($this->fortuneTierCache === null) {
            $this->fortuneTierCache = [];
            foreach (DB::table('fortune_bonus_tiers')->get() as $row) {
                $this->fortuneTierCache[(string) $row->tier] = [
                    'bv_required_paise' => (int) $row->bv_required_paise,
                    'slabs_required' => (int) $row->slabs_required,
                ];
            }
        }

        return $this->fortuneTierCache[$tier] ?? ['bv_required_paise' => 0, 'slabs_required' => 0];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function scalarInt(string $key): int
    {
        $value = $this->scalar($key);

        return $value !== null ? (int) $value : (int) (self::SCALAR_DEFAULTS[$key] ?? 0);
    }

    private function scalarBool(string $key): bool
    {
        $value = $this->scalar($key);
        if ($value === null) {
            return (bool) (self::SCALAR_DEFAULTS[$key] ?? false);
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    private function scalar(string $key): ?string
    {
        if ($this->scalarCache === null) {
            $this->scalarCache = DB::table('settings')->pluck('value', 'key')->all();
        }

        return $this->scalarCache[$key] ?? null;
    }
}
