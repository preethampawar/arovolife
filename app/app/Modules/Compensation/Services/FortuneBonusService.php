<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Models\FortuneMonthlyPool;
use App\Modules\Compensation\Models\FortuneMonthlyPoolLevel;
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
 * fortunePointsForDepth(d) points (KP 2026-08-09: 9/8/7/6/5/4/3/2/1 for
 * depths 1–9, nothing deeper).
 *
 * DISTRIBUTION (KP 2026-08-09 level cascade, updated 2026-09-03,
 * FortuneDistributionCalculator) — every qualifier is guaranteed the ₹30
 * minimum commission, reserved off the pool first. Then absolute matrix
 * levels settle ascending: every level (0–9) is capped with its own
 * per-member ceiling (₹30k/₹30k/₹30k/₹30k/₹20k/₹10k/₹5k/₹2,500/₹1,500/₹30,
 * caps INCLUDE the ₹30 minimum). The residual (7–8) and flat-minimum (9)
 * payout modes were retired 2026-09-03; those mode names survive only in
 * incomeFromFrozenEconomics to honour pool snapshots frozen before that date.
 * When the pool cannot cover the guarantees, everyone gets the same
 * whole-rupee share and nothing else. Sparse months keep the ABSOLUTE-level
 * treatment (user decision 2026-08-09) — the unspent remainder stays as
 * leftover.
 *
 * FROZEN ECONOMICS — the month's pool row and its per-level economics
 * (fortune_monthly_pool_levels) are written once BEFORE any credit and never
 * recomputed. A re-run after more BV or more enrolments have landed
 * reconstructs incomes from that snapshot, so the month's economics never
 * move under a distributor who was already paid. Legacy pre-cascade months
 * keep their single point_value_paise and re-run on the old formula.
 *
 * Point values floor to whole rupees (the same rule as the Rank, MSB and GBB
 * pools); flooring and cap remainders stay in leftover_paise.
 *
 * REPURCHASE — a held/suspended distributor is filtered out at ENROLMENT
 * (IncomeEligibilityService), so unlike GBB there is no held/suspended split at
 * run time: every enrolled participant is payable.
 *
 * Deductions (admin charge, TDS) are applied at payout time, not at credit time.
 * Per-level points, eligibility tiers, and ineligible ranks are all
 * admin-editable via CompensationPlanSettingsService.
 *
 * Position → level: floor(log(2*position - 1, 3)). The matrix is levels 0–9
 * only — 29,524 positions; once a month's matrix is full, no further qualifier
 * is entered for that month (the client, 2026-09-03).
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
        private readonly FortuneDistributionCalculator $calculator,
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
     * @return array{enrolled: int, skipped_ineligible: int, skipped_wallet_nonzero: int, skipped_matrix_full: int, refused_pool_frozen: bool}
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
                'skipped_wallet_nonzero' => 0,
                'skipped_matrix_full' => 0,
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

        // "Repurchase Wallet zero" (mandatory per spec, rules 2–7): the repurchase
        // wallet as it stood at the end of the month being enrolled. Enrolment
        // runs on the 9th, so entries after month end must not count either way.
        // This gate is unconditional — the spec makes it non-configurable.
        $walletBalances = $this->wallet->repurchaseWalletBalancesAsOfPaise(
            array_map(intval(...), array_keys($firstGsbDates)),
            $month->copy()->endOfMonth(),
        );

        // Determine next available position (existing participants claim positions already).
        $highestPosition = (int) DB::table('fortune_bonus_participants')
            ->where('month_start', $monthStart)
            ->max('position');
        $nextPosition = $highestPosition + 1;

        $eligibles = [];
        $skippedWalletNonzero = 0;

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

            // From the second month onward the repurchase wallet must have been
            // spent down to ₹0 by the last day of the month (rule 1, the month of
            // registration, carries no wallet condition — whatever tier the
            // joiner lands in). A distributor who never received a deduction has
            // a ₹0 balance and passes. The exclusion is irreversible once the
            // month freezes, so it is logged with the balance that caused it.
            $walletBalance = $walletBalances[(int) $distributorId] ?? 0;
            if (! isset($newJoinerIds[$distributorId]) && $walletBalance > 0) {
                Log::info('fortune.enroll.skipped_wallet_nonzero', [
                    'distributor_id' => (int) $distributorId,
                    'month_start' => $monthStart,
                    'tier' => $tier,
                    'repurchase_wallet_balance_paise' => $walletBalance,
                ]);
                $skippedWalletNonzero++;

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
        $skippedMatrixFull = 0;

        DB::transaction(function () use ($eligibles, $monthStart, &$nextPosition, &$enrolled, &$skippedMatrixFull): void {
            foreach ($eligibles as $eligible) {
                // The matrix is levels 0–9 only. Once its 29,524 positions are
                // taken, later qualifiers (by FCFS order) are not entered this
                // month at all — logged, since the month cannot be reopened.
                if ($nextPosition > FortuneBonusParticipant::MAX_POSITIONS) {
                    // A qualifier shut out by capacity has met every gate, so
                    // the exclusion gets a retention-guaranteed audit row a
                    // grievance officer can query, not just a log line.
                    $details = [
                        'distributor_id' => $eligible['distributor_id'],
                        'month_start' => $monthStart,
                        'first_gsb_date' => $eligible['first_gsb_date'],
                        'tier' => $eligible['tier'],
                        'next_position' => $nextPosition,
                    ];
                    Log::info('fortune.enroll.matrix_full', $details);
                    AuditLog::create([
                        'action' => 'fortune.enroll.matrix_full',
                        'subject_type' => 'distributor',
                        'subject_id' => $eligible['distributor_id'],
                        'details' => $details,
                    ]);
                    $skippedMatrixFull++;

                    continue;
                }

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
            'skipped_wallet_nonzero' => $skippedWalletNonzero,
            'skipped_matrix_full' => $skippedMatrixFull,
            'refused_pool_frozen' => false,
        ];
    }

    /**
     * Calculate and credit Fortune Bonus for all enrolled participants in the
     * month through the KP 2026-08-09 level cascade. Idempotent — a
     * participant already credited (or already recorded as earning nothing)
     * is left alone, and the month's frozen economics are reused rather than
     * recomputed: incomes are reconstructed from the per-level snapshot.
     *
     * @return array{credited: int, skipped_zero_income: int, total_net_paise: int, pool_paise: int, total_points: int, guaranteed_total_paise: int, leftover_paise: int, is_shortfall: bool}
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStartDate = $month->copy()->startOfMonth();
        $monthStart = $monthStartDate->toDateString();

        $participants = FortuneBonusParticipant::where('month_start', $monthStart)
            ->orderBy('position')
            ->get();

        $pointsByDistributor = $this->buildPointsByDistributor($participants);

        $participantRows = [];
        foreach ($participants as $participant) {
            $participantRows[] = [
                'position' => (int) $participant->position,
                'matrix_level' => (int) $participant->matrix_level,
                'points' => $pointsByDistributor[(int) $participant->distributor_id] ?? 0,
            ];
        }

        $pool = $this->freezePoolForMonth(
            $monthStartDate,
            $month->copy()->endOfMonth(),
            $participantRows,
        );

        /** @var array<int, FortuneMonthlyPoolLevel> $frozenLevels */
        $frozenLevels = $pool->levels()->get()->keyBy('matrix_level')->all();

        $credited = 0;
        $skippedZeroIncome = 0;
        $totalNet = 0;

        DB::transaction(function () use (
            $participants, $monthStart, $pointsByDistributor, $pool, $frozenLevels,
            &$credited, &$skippedZeroIncome, &$totalNet,
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
                $level = $frozenLevels[(int) $participant->matrix_level] ?? null;
                $gross = $this->incomeFromFrozenEconomics($pool, $level, $points);
                $valuePaise = $pool->point_value_paise ?? $level->point_value_paise ?? 0;
                $minCommission = $pool->min_commission_paise;
                $capPaise = $level?->cap_paise;

                if ($gross === 0) {
                    // A ₹0-pool month (or a legacy zero-value month) — no
                    // wallet entry, but the row records why.
                    $this->writeResult($participant, $monthStart, $points, $valuePaise, $minCommission, $capPaise, 0, FortuneBonusResult::STATUS_SKIPPED);
                    $skippedZeroIncome++;

                    continue;
                }

                $result = $this->writeResult($participant, $monthStart, $points, $valuePaise, $minCommission, $capPaise, $gross, FortuneBonusResult::STATUS_PENDING);

                // The memo is a distributor-facing statement line — it must
                // reconcile arithmetically with the credited amount: minimum +
                // points × value, with an explicit marker when the cap clipped it.
                if ($pool->is_shortfall) {
                    $memo = 'Fortune Bonus pro-rated minimum '.$monthStart;
                } elseif ($pool->point_value_paise !== null) {
                    $memo = 'Fortune Bonus '.$points.' pts @ ₹'.IndianNumber::format($valuePaise / 100, 2).' '.$monthStart;
                } else {
                    $memo = 'Fortune Bonus L'.$participant->matrix_level
                        .' ₹'.IndianNumber::format(((int) $minCommission) / 100, 2).' min + '
                        .$points.' pts @ ₹'.IndianNumber::format($valuePaise / 100, 2)
                        .($capPaise !== null && $gross === $capPaise ? ' (capped at ₹'.IndianNumber::format($capPaise / 100, 2).')' : '')
                        .' '.$monthStart;
                }

                $this->wallet->credit(
                    distributorId: $distributorId,
                    amountPaise: $gross,
                    type: 'fortune_credit',
                    referenceId: $result->id,
                    referenceType: 'fortune_bonus_result',
                    memo: $memo,
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
        // recomputation — the pool row must always reconcile to what was
        // actually paid.
        return [
            'credited' => $credited,
            'skipped_zero_income' => $skippedZeroIncome,
            'total_net_paise' => $totalNet,
            'pool_paise' => (int) $pool->pool_paise,
            'total_points' => (int) $pool->total_points,
            'guaranteed_total_paise' => (int) ($pool->guaranteed_total_paise ?? 0),
            'leftover_paise' => (int) $pool->leftover_paise,
            'is_shortfall' => (bool) $pool->is_shortfall,
        ];
    }

    /**
     * One participant's gross income, reconstructed purely from the month's
     * frozen economics — the ONLY income formula re-runs are allowed to use.
     *
     * Legacy pre-cascade months carry a month-wide point_value_paise on the
     * pool row and pay points × value with no minimum and no cap.
     */
    private function incomeFromFrozenEconomics(FortuneMonthlyPool $pool, ?FortuneMonthlyPoolLevel $level, int $points): int
    {
        if ($pool->point_value_paise !== null) {
            return $points * (int) $pool->point_value_paise;
        }

        if ($pool->is_shortfall) {
            return (int) $pool->shortfall_per_head_paise;
        }

        if ($level === null) {
            // Defensive: a cascade month freezes a level row for every level
            // that had participants, and enrolment is refused post-freeze.
            return 0;
        }

        $minCommission = (int) $pool->min_commission_paise;

        return match ((string) $level->payout_mode) {
            'flat_min' => $minCommission,
            'residual' => $minCommission + $points * (int) $level->point_value_paise,
            default => min(
                $minCommission + $points * (int) $level->point_value_paise,
                $level->cap_paise === null ? PHP_INT_MAX : (int) $level->cap_paise,
            ),
        };
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
        ?int $minCommissionPaise,
        ?int $capPaise,
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
                'min_commission_paise' => $minCommissionPaise,
                'cap_paise' => $capPaise,
                'gross_paise' => $grossPaise,
                'admin_charge_paise' => 0,
                'tds_paise' => 0,
                'net_paise' => $grossPaise,
                'status' => $status,
            ],
        );
    }

    /**
     * Freeze the month's pool economics — the cascade allocation over every
     * enrolled participant, snapshotted as the pool row plus one
     * fortune_monthly_pool_levels row per occupied matrix level. Idempotent:
     * an existing row is returned unchanged — the month's economics never
     * move once written, no matter how much BV or how many enrolments land
     * afterwards.
     *
     * @param  array<int, array{position: int, matrix_level: int, points: int}>  $participantRows
     */
    private function freezePoolForMonth(Carbon $monthStart, Carbon $monthEnd, array $participantRows): FortuneMonthlyPool
    {
        $existing = FortuneMonthlyPool::where('month_start', $monthStart->toDateString())->first();
        if ($existing !== null) {
            return $existing;
        }

        $companyBvPaise = $this->gsbPool->companyBvPaiseBetween($monthStart, $monthEnd);
        $rateBp = $this->plan->fortunePoolRateBp();
        $poolPaise = max(0, intdiv($companyBvPaise * $rateBp, 10_000));
        $minCommissionPaise = $this->plan->fortuneMinCommissionPaise();

        $totalPoints = 0;
        foreach ($participantRows as $row) {
            $totalPoints += $row['points'];
        }

        $allocation = $this->calculator->allocate(
            $participantRows,
            $poolPaise,
            $minCommissionPaise,
            $this->plan->fortuneLevelConfigs(),
        );

        $payoutPaise = array_sum($allocation['incomes']);

        $pool = DB::transaction(function () use ($monthStart, $companyBvPaise, $rateBp, $poolPaise, $totalPoints, $minCommissionPaise, $payoutPaise, $allocation): FortuneMonthlyPool {
            $pool = FortuneMonthlyPool::create([
                'month_start' => $monthStart->toDateString(),
                'company_bv_paise' => $companyBvPaise,
                'pool_rate_bp' => $rateBp,
                'pool_paise' => $poolPaise,
                'total_points' => $totalPoints,
                'point_value_paise' => null,
                'payout_paise' => $payoutPaise,
                'leftover_paise' => $allocation['leftover_paise'],
                'min_commission_paise' => $minCommissionPaise,
                'guaranteed_total_paise' => $allocation['guaranteed_total_paise'],
                'is_shortfall' => $allocation['is_shortfall'],
                'shortfall_per_head_paise' => $allocation['shortfall_per_head_paise'],
            ]);

            foreach ($allocation['levels'] as $matrixLevel => $level) {
                FortuneMonthlyPoolLevel::create([
                    'fortune_monthly_pool_id' => $pool->id,
                    'matrix_level' => $matrixLevel,
                    'payout_mode' => $level['payout_mode'],
                    'cap_paise' => $level['cap_paise'],
                    'participants' => $level['participants'],
                    'points' => $level['points'],
                    'point_value_paise' => $level['point_value_paise'],
                    'paid_paise' => $level['paid_paise'],
                ]);
            }

            return $pool;
        });

        $details = [
            'month_start' => $monthStart->toDateString(),
            'company_bv_paise' => $companyBvPaise,
            'pool_rate_bp' => $rateBp,
            'pool_paise' => $poolPaise,
            'total_points' => $totalPoints,
            'min_commission_paise' => $minCommissionPaise,
            'guaranteed_total_paise' => $allocation['guaranteed_total_paise'],
            'is_shortfall' => $allocation['is_shortfall'],
            'shortfall_per_head_paise' => $allocation['shortfall_per_head_paise'],
            'payout_paise' => $payoutPaise,
            'leftover_paise' => $allocation['leftover_paise'],
            'levels' => $allocation['levels'],
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
        return RankQualification::query()->rankedInMonth($monthStart)
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
        $rows = RankQualification::query()->rankedInMonth($monthStart)
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
