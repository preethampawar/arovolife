<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Models\FortuneMonthlyPool;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fortune Bonus engine — monthly 3×9 forced matrix, FCFS placement.
 *
 * POOL BASE — comp.fortune.pool_rate_bp (default 5%, KP 2026-08-07, which
 * supersedes the June envelope's 6% Fortune share) of the month's company-wide
 * BV, read through GsbDailyPoolService::companyBvPaiseBetween() so no two pools
 * can ever disagree on what a period's BV was.
 *
 * ENTITLEMENT — FB points earned from the enrolled downline in the month's
 * matrix: every participant sitting at relative depth d below you is worth
 * fortunePointsForDepth(d) points (9/9/9/8/7/6/5/4/3 for depths 1–9, nothing
 * deeper). The bottom of the matrix therefore earns nothing — a participant
 * with no one below them is recorded as skipped with 0 points. This replaced
 * the old fixed rupee payout per matrix level, which was not a pool at all.
 *
 * FROZEN ECONOMICS — the month's pool, total points and point value are
 * written once to fortune_monthly_pools BEFORE any credit and never
 * recomputed. A re-run after more BV or more enrolments have landed prices
 * against that snapshot, so the month's economics never move under a
 * distributor who was already paid.
 *
 * The point value floors to whole rupees (KP 2026-08-07 — the same rule as the
 * Rank, MSB and GBB pools); the flooring remainder stays in leftover_paise.
 *
 * REPURCHASE — a held/suspended distributor is filtered out at ENROLMENT
 * (IncomeEligibilityService), so unlike GBB there is no held/suspended split at
 * run time: every enrolled participant is payable.
 *
 * Deductions (admin charge, TDS) are applied at payout time, not at credit time.
 * Per-level points, eligibility tiers, and ineligible ranks are all
 * admin-editable via CompensationPlanSettingsService.
 *
 * Position → level: floor(log(2*position - 1, 3)).
 */
final class FortuneBonusService
{
    /** Month-1 tier: 3,000 BV personal purchases + the GSB 1st income (slab 1). */
    public const string TIER_NEW_JOINER = 'new_joiner';

    /** Month-2-onwards unranked tier: a personal-purchase title + 600 BV repurchase + 1 slab. */
    public const string TIER_NON_RANKED = 'non_ranked';

    /** Deepest relative level in the 3×9 matrix that earns FB points. */
    private const int MAX_POINT_DEPTH = 9;

    public function __construct(
        private readonly WalletService $wallet,
        private readonly CompensationPlanSettingsService $plan,
        private readonly IncomeEligibilityService $eligibility,
        private readonly GsbDailyPoolService $gsbPool,
        private readonly PersonalBvTitleService $titleService,
    ) {}

    /**
     * Scan all distributors with GSB activity in the month, check eligibility,
     * and assign FCFS positions in the matrix. Idempotent for already-enrolled
     * participants.
     *
     * REFUSED ONCE THE MONTH IS FROZEN. The pool row fixes total_points and
     * point_value_paise for the month; a participant enrolled afterwards would
     * be credited at that frozen value on points that were never in the frozen
     * denominator, so the month would pay out more than pool_paise. The month
     * is closed to new entrants the moment runForMonth() freezes it.
     *
     * @return array{enrolled: int, skipped_ineligible: int, refused_pool_frozen: bool}
     */
    public function enrollEligible(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        if (FortuneMonthlyPool::where('month_start', $monthStart)->exists()) {
            Log::warning('fortune.enroll.refused_pool_frozen', [
                'month_start' => $monthStart,
                'reason' => 'The month\'s pool economics are already frozen; enrolling now would credit points outside the frozen denominator and overspend the pool.',
            ]);

            return [
                'enrolled' => 0,
                'skipped_ineligible' => 0,
                'refused_pool_frozen' => true,
            ];
        }

        // First GSB credit date per distributor in the month.
        $firstGsbDates = $this->buildFirstGsbDates($monthStart, $monthEnd);

        // Count of credited GSB slab-achievements per distributor in the month
        // (repeats count — KP 2026-08-07), and the subset that are slab 1.
        $slabCounts = $this->buildSlabCounts($monthStart, $monthEnd);
        $slab1Counts = $this->buildSlabCounts($monthStart, $monthEnd, slab: 1);

        // Personal BV (accrual) per distributor in the month.
        $personalBvMap = $this->buildPersonalBvMap($monthStart, $monthEnd);

        // Lifetime personal BV (signed net) per distributor — the non_ranked
        // title gate, and never month-bounded.
        $lifetimeBvMap = $this->buildLifetimeBvMap();

        // Distributors whose registration falls in this very month: they are
        // enrolled on the month-1 `new_joiner` gates, everyone else on
        // `non_ranked` or their rank tier.
        $newJoinerIds = $this->buildNewJoinerIds($monthStart, $monthEnd);

        // Distributor IDs with an ineligible rank (6-9) for this month.
        $ineligibleRankIds = $this->buildIneligibleRankIds($monthStart);

        // Highest rank per distributor for this month.
        $rankMap = $this->buildRankMap($monthStart);

        // Determine next available position (existing participants claim positions already).
        $highestPosition = (int) DB::table('fortune_bonus_participants')
            ->where('month_start', $monthStart)
            ->max('position');
        $nextPosition = $highestPosition + 1;

        $eligibles = [];

        foreach ($firstGsbDates as $distributorId => $firstGsbDate) {
            if (in_array($distributorId, $ineligibleRankIds, true)) {
                continue;
            }

            // Repurchase engine: a held/suspended distributor is not enrolled in
            // the Fortune tree this month (KP — GSB/Fortune/GBB suspend together).
            if ($this->eligibility->statusFor((int) $distributorId, BonusType::Fortune) !== IncomeEligibilityService::ELIGIBLE) {
                continue;
            }

            // Already enrolled this month?
            $alreadyEnrolled = DB::table('fortune_bonus_participants')
                ->where('distributor_id', $distributorId)
                ->where('month_start', $monthStart)
                ->exists();

            if ($alreadyEnrolled) {
                continue;
            }

            $currentRank = $rankMap[$distributorId] ?? 0;
            $tier = $this->determineTier($currentRank, isset($newJoinerIds[$distributorId]));

            // A month-1 joiner qualifies on the GSB 1st income specifically —
            // the 15K/15K slab-1 match — not on any slab (KP 2026-08-07).
            $slabCount = $tier === self::TIER_NEW_JOINER
                ? ($slab1Counts[$distributorId] ?? 0)
                : ($slabCounts[$distributorId] ?? 0);

            $personalBv = $personalBvMap[$distributorId] ?? 0;
            $tierGates = $this->plan->fortuneTier($tier);
            $bvRequired = $tierGates['bv_required_paise'];
            $slabsRequired = $tierGates['slabs_required'];

            if ($personalBv < $bvRequired || $slabCount < $slabsRequired) {
                continue;
            }

            // From month 2 a non-ranked distributor must additionally hold one
            // of the 7 personal-purchase titles (KP 2026-08-07). The ranked
            // tiers already imply a title through their rank requirements, and
            // a new joiner's own 3,000 BV month-1 gate is the same threshold.
            if ($tier === self::TIER_NON_RANKED && ! $this->holdsATitle($lifetimeBvMap[$distributorId] ?? 0)) {
                continue;
            }

            $eligibles[] = [
                'distributor_id' => $distributorId,
                'first_gsb_date' => $firstGsbDate,
                'tier' => $tier,
            ];
        }

        // Sort FCFS: earliest first_gsb_date first, then by distributor_id as tiebreaker.
        usort($eligibles, fn (array $a, array $b): int => strcmp($a['first_gsb_date'], $b['first_gsb_date']) ?: $a['distributor_id'] <=> $b['distributor_id']
        );

        $enrolled = 0;

        DB::transaction(function () use ($eligibles, $monthStart, &$nextPosition, &$enrolled): void {
            foreach ($eligibles as $eligible) {
                $level = FortuneBonusParticipant::levelFromPosition($nextPosition);

                FortuneBonusParticipant::create([
                    'distributor_id' => $eligible['distributor_id'],
                    'month_start' => $monthStart,
                    'position' => $nextPosition,
                    'matrix_level' => $level,
                    'eligibility_tier' => $eligible['tier'],
                    'first_gsb_date' => $eligible['first_gsb_date'],
                    'enrolled_at' => now(),
                ]);

                $nextPosition++;
                $enrolled++;
            }
        });

        return [
            'enrolled' => $enrolled,
            'skipped_ineligible' => count($firstGsbDates) - $enrolled,
            'refused_pool_frozen' => false,
        ];
    }

    /**
     * Calculate and credit Fortune Bonus for all enrolled participants in the
     * month: every participant's FB points × the month's frozen point value.
     * Idempotent — a participant already credited (or already recorded as
     * earning nothing) is left alone, and the month's economics are reused
     * rather than recomputed.
     *
     * @return array{credited: int, skipped_zero_points: int, total_net_paise: int, pool_paise: int, total_points: int, point_value_paise: int}
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStartDate = $month->copy()->startOfMonth();
        $monthStart = $monthStartDate->toDateString();

        $participants = FortuneBonusParticipant::where('month_start', $monthStart)
            ->orderBy('position')
            ->get();

        $pointsByDistributor = $this->buildPointsByDistributor($participants);

        $pool = $this->freezePoolForMonth(
            $monthStartDate,
            $month->copy()->endOfMonth(),
            array_sum($pointsByDistributor),
        );
        $pointValuePaise = (int) $pool->point_value_paise;

        $credited = 0;
        $skippedZeroPoints = 0;
        $totalNet = 0;

        DB::transaction(function () use (
            $participants, $monthStart, $pointsByDistributor, $pointValuePaise,
            &$credited, &$skippedZeroPoints, &$totalNet,
        ): void {
            foreach ($participants as $participant) {
                $distributorId = (int) $participant->distributor_id;

                $alreadyProcessed = FortuneBonusResult::where('distributor_id', $distributorId)
                    ->where('month_start', $monthStart)
                    ->whereIn('status', [FortuneBonusResult::STATUS_CREDITED, FortuneBonusResult::STATUS_SKIPPED])
                    ->exists();

                if ($alreadyProcessed) {
                    continue;
                }

                $points = $pointsByDistributor[$distributorId] ?? 0;
                $gross = $points * $pointValuePaise;

                if ($gross === 0) {
                    // Either nobody is enrolled below them (the bottom of the
                    // matrix) or the month's point value floored to ₹0 — no
                    // wallet entry either way, but the row records why.
                    $this->writeResult($participant, $monthStart, $points, $pointValuePaise, 0, FortuneBonusResult::STATUS_SKIPPED);
                    $skippedZeroPoints++;

                    continue;
                }

                $result = $this->writeResult($participant, $monthStart, $points, $pointValuePaise, $gross, FortuneBonusResult::STATUS_PENDING);

                $this->wallet->credit(
                    distributorId: $distributorId,
                    amountPaise: $gross,
                    type: 'fortune_credit',
                    referenceId: $result->id,
                    referenceType: 'fortune_bonus_result',
                    memo: 'Fortune Bonus '.$points.' pts @ ₹'.IndianNumber::format($pointValuePaise / 100, 2).' '.$monthStart,
                );

                $result->update([
                    'status' => FortuneBonusResult::STATUS_CREDITED,
                    'credited_at' => now(),
                ]);

                $totalNet += $gross;
                $credited++;
            }
        });

        // Reported straight off the frozen snapshot, never off a live
        // recomputation — pool ÷ total_points must always reconcile to the
        // point value that was actually paid.
        return [
            'credited' => $credited,
            'skipped_zero_points' => $skippedZeroPoints,
            'total_net_paise' => $totalNet,
            'pool_paise' => (int) $pool->pool_paise,
            'total_points' => (int) $pool->total_points,
            'point_value_paise' => $pointValuePaise,
        ];
    }

    /**
     * FB points every participant earned from the enrolled distributors below
     * them. The matrix is filled sequentially with no gaps, so walking UP from
     * each participant at most 9 steps visits exactly the ancestors that earn
     * from them — O(N×9) with no tree table.
     *
     * @param  Collection<int, FortuneBonusParticipant>  $participants
     * @return array<int, int> distributor_id → points
     */
    private function buildPointsByDistributor($participants): array
    {
        /** @var array<int, int> $distributorByPosition */
        $distributorByPosition = [];

        foreach ($participants as $participant) {
            $distributorByPosition[(int) $participant->position] = (int) $participant->distributor_id;
        }

        $pointsByDistributor = array_fill_keys(array_values($distributorByPosition), 0);

        foreach (array_keys($distributorByPosition) as $position) {
            $ancestorPosition = $position;

            for ($depth = 1; $depth <= self::MAX_POINT_DEPTH; $depth++) {
                $ancestorPosition = FortuneBonusParticipant::parentPosition($ancestorPosition);

                if ($ancestorPosition === null) {
                    break;
                }

                // Defensive: a gap can only exist if positions were edited by
                // hand, but a missing ancestor must not silently shift points
                // onto whoever sits above the gap.
                if (! isset($distributorByPosition[$ancestorPosition])) {
                    continue;
                }

                $pointsByDistributor[$distributorByPosition[$ancestorPosition]] += $this->plan->fortunePointsForDepth($depth);
            }
        }

        return $pointsByDistributor;
    }

    /** Write (or refresh) one participant's result row for the month. */
    private function writeResult(
        FortuneBonusParticipant $participant,
        string $monthStart,
        int $points,
        int $pointValuePaise,
        int $grossPaise,
        string $status,
    ): FortuneBonusResult {
        return FortuneBonusResult::updateOrCreate(
            [
                'distributor_id' => $participant->distributor_id,
                'month_start' => $monthStart,
            ],
            [
                'position' => $participant->position,
                'matrix_level' => $participant->matrix_level,
                'points' => $points,
                'point_value_paise' => $pointValuePaise,
                'gross_paise' => $grossPaise,
                'admin_charge_paise' => 0,
                'tds_paise' => 0,
                'net_paise' => $grossPaise,
                'status' => $status,
            ],
        );
    }

    /**
     * Freeze the month's pool economics. Idempotent: an existing row is
     * returned unchanged — the month's economics never move once written, no
     * matter how much BV or how many enrolments land afterwards.
     *
     * @param  int  $totalPoints  Σ FB points over the month's participants
     */
    private function freezePoolForMonth(Carbon $monthStart, Carbon $monthEnd, int $totalPoints): FortuneMonthlyPool
    {
        $existing = FortuneMonthlyPool::where('month_start', $monthStart->toDateString())->first();
        if ($existing !== null) {
            return $existing;
        }

        $companyBvPaise = $this->gsbPool->companyBvPaiseBetween($monthStart, $monthEnd);
        $rateBp = $this->plan->fortunePoolRateBp();
        $poolPaise = max(0, intdiv($companyBvPaise * $rateBp, 10_000));

        // Floor the per-point value to whole rupees: truncate to a multiple of
        // 100 paise. max() guards a refund-heavy (negative-BV) month, where
        // intdiv() truncates toward zero.
        $valuePaise = $totalPoints === 0
            ? 0
            : max(0, intdiv(intdiv($poolPaise, $totalPoints), 100) * 100);

        $payoutPaise = $valuePaise * $totalPoints;

        $pool = FortuneMonthlyPool::create([
            'month_start' => $monthStart->toDateString(),
            'company_bv_paise' => $companyBvPaise,
            'pool_rate_bp' => $rateBp,
            'pool_paise' => $poolPaise,
            'total_points' => $totalPoints,
            'point_value_paise' => $valuePaise,
            'payout_paise' => $payoutPaise,
            'leftover_paise' => $poolPaise - $payoutPaise,
        ]);

        $details = [
            'month_start' => $monthStart->toDateString(),
            'company_bv_paise' => $companyBvPaise,
            'pool_rate_bp' => $rateBp,
            'pool_paise' => $poolPaise,
            'total_points' => $totalPoints,
            'point_value_paise' => $valuePaise,
            'payout_paise' => $payoutPaise,
            'leftover_paise' => $pool->leftover_paise,
        ];

        Log::info('fortune.pool.frozen', $details);

        // The freeze determines every Fortune payout for the month — a
        // retention-guaranteed audit_log row, not just a log line.
        AuditLog::create([
            'action' => 'fortune.pool.frozen',
            'subject_type' => 'fortune_monthly_pool',
            'subject_id' => $pool->id,
            'details' => $details,
        ]);

        return $pool;
    }

    /**
     * Map distributor_id → earliest GSB credit date in the month.
     *
     * @return array<int, string>
     */
    private function buildFirstGsbDates(string $monthStart, string $monthEnd): array
    {
        $rows = DB::table('gsb_cutoff_results')
            ->where('status', GsbCutoffResult::STATUS_CREDITED)
            ->whereBetween('cutoff_date', [$monthStart, $monthEnd])
            ->select('distributor_id', DB::raw('MIN(cutoff_date) as first_date'))
            ->groupBy('distributor_id')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->distributor_id] = $row->first_date;
        }

        return $map;
    }

    /**
     * Map distributor_id → count of credited GSB slab-achievements in the
     * month. Repeats count: "8 slabs" means hitting slabs 8 times, and GSB has
     * only 7 distinct slabs (KP 2026-08-07).
     *
     * @param  int|null  $slab  Restrict to one slab (the new_joiner tier counts
     *                          only the GSB 1st income, i.e. slab 1).
     * @return array<int, int>
     */
    private function buildSlabCounts(string $monthStart, string $monthEnd, ?int $slab = null): array
    {
        $rows = DB::table('gsb_cutoff_results')
            ->where('status', GsbCutoffResult::STATUS_CREDITED)
            ->whereBetween('cutoff_date', [$monthStart, $monthEnd])
            ->whereNotNull('slab')
            ->when($slab !== null, fn ($query) => $query->where('slab', $slab))
            ->select('distributor_id', DB::raw('COUNT(*) as slab_count'))
            ->groupBy('distributor_id')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->distributor_id] = (int) $row->slab_count;
        }

        return $map;
    }

    /**
     * Map distributor_id → total personal BV accrued in the month (paise).
     *
     * @return array<int, int>
     */
    private function buildPersonalBvMap(string $monthStart, string $monthEnd): array
    {
        $rows = DB::table('bv_ledger_entries')
            ->where('type', 'accrual')
            ->whereBetween('effective_at', [$monthStart, $monthEnd.' 23:59:59'])
            ->select('distributor_id', DB::raw('SUM(bv_paise) as total_bv'))
            ->groupBy('distributor_id')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->distributor_id] = (int) $row->total_bv;
        }

        return $map;
    }

    /**
     * Map distributor_id → LIFETIME personal BV (paise), never month-bounded —
     * the basis of the personal-purchase title.
     *
     * SIGNED NET over every entry type, matching the canonical definition in
     * BvLedgerService::totalPersonalBvPaise() that the GSB income gate uses: a
     * reversal (cancelled or refunded order) must pull the title back down, so
     * filtering to `accrual` would leave a distributor holding a title on BV
     * that no longer exists. This is the same figure, batched into one grouped
     * query rather than a per-distributor service call.
     *
     * @return array<int, int>
     */
    private function buildLifetimeBvMap(): array
    {
        $rows = DB::table('bv_ledger_entries')
            ->select('distributor_id', DB::raw('SUM(bv_paise) as total_bv'))
            ->groupBy('distributor_id')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->distributor_id] = (int) $row->total_bv;
        }

        return $map;
    }

    /**
     * Distributor IDs (as keys) whose registration falls inside the run month —
     * the `new_joiner` tier's month-1 population.
     *
     * @return array<int, bool>
     */
    private function buildNewJoinerIds(string $monthStart, string $monthEnd): array
    {
        return DB::table('distributors')
            ->whereBetween('effective_date', [$monthStart.' 00:00:00', $monthEnd.' 23:59:59'])
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * Does this lifetime personal BV hold one of the 7 personal-purchase
     * titles? The lowest is Retailer at 3,000 BV, so this is equivalently
     * "lifetime BV ≥ the Retailer threshold" — read from the admin-editable
     * title ladder rather than hardcoded.
     */
    private function holdsATitle(int $lifetimeBvPaise): bool
    {
        return $this->titleService->forBvPaise($lifetimeBvPaise)->title !== null;
    }

    /**
     * Return distributor IDs that hold a rank ≥ 6 this month (ineligible for Fortune Bonus).
     *
     * @return array<int, int>
     */
    private function buildIneligibleRankIds(string $monthStart): array
    {
        return RankQualification::where('month_start', $monthStart)
            ->where('status', RankQualification::STATUS_QUALIFIED)
            ->whereIn('rank_number', $this->plan->fortuneIneligibleRanks())
            ->distinct()
            ->pluck('distributor_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Map distributor_id → highest rank held this month (0 = no rank).
     *
     * @return array<int, int>
     */
    private function buildRankMap(string $monthStart): array
    {
        $rows = RankQualification::where('month_start', $monthStart)
            ->where('status', RankQualification::STATUS_QUALIFIED)
            ->select('distributor_id', DB::raw('MAX(rank_number) as max_rank'))
            ->groupBy('distributor_id')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->distributor_id] = (int) $row->max_rank;
        }

        return $map;
    }

    /**
     * A rank always wins: the rank tiers carry their own BV and slab gates.
     * Without one, a distributor who registered this very month is on the
     * month-1 `new_joiner` gates and everyone else on `non_ranked`.
     */
    private function determineTier(int $rank, bool $isNewJoiner): string
    {
        return match (true) {
            $rank >= 5 => 'rank_5',
            $rank === 4 => 'rank_4',
            $rank === 3 => 'rank_3',
            $rank === 2 => 'rank_2',
            $rank === 1 => 'rank_1',
            $isNewJoiner => self::TIER_NEW_JOINER,
            default => self::TIER_NON_RANKED,
        };
    }
}
