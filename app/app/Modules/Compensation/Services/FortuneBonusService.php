<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RankQualification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fortune Bonus engine — monthly 3×9 forced matrix, FCFS placement.
 *
 * Admin charge: NOT applicable (Fortune Bonus is exempt per plan).
 * TDS: 5% on gross.
 * Ranks 6-9 are ineligible.
 *
 * Level bonus (paise): see FortuneBonusParticipant::LEVEL_BONUS_PAISE.
 * Position → level: floor(log(2*position - 1, 3)).
 */
final class FortuneBonusService
{
    private const float TDS_RATE = 0.05;

    /** Rank numbers ineligible for Fortune Bonus. */
    private const array INELIGIBLE_RANKS = [6, 7, 8, 9];

    public function __construct(private readonly WalletService $wallet) {}

    /**
     * Scan all distributors with GSB activity in the month, check eligibility,
     * and assign FCFS positions in the matrix. Idempotent for already-enrolled
     * participants.
     *
     * @return array{enrolled: int, skipped_ineligible: int}
     */
    public function enrollEligible(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        // First GSB credit date per distributor in the month.
        $firstGsbDates = $this->buildFirstGsbDates($monthStart, $monthEnd);

        // Count of credited GSB slabs per distributor in the month.
        $slabCounts = $this->buildSlabCounts($monthStart, $monthEnd);

        // Personal BV (accrual) per distributor in the month.
        $personalBvMap = $this->buildPersonalBvMap($monthStart, $monthEnd);

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

            // Already enrolled this month?
            $alreadyEnrolled = DB::table('fortune_bonus_participants')
                ->where('distributor_id', $distributorId)
                ->where('month_start', $monthStart)
                ->exists();

            if ($alreadyEnrolled) {
                continue;
            }

            $currentRank = $rankMap[$distributorId] ?? 0;
            $tier = $this->determineTier($currentRank);
            $slabCount = $slabCounts[$distributorId] ?? 0;
            $personalBv = $personalBvMap[$distributorId] ?? 0;
            $bvRequired = FortuneBonusParticipant::BV_REQUIRED_PAISE[$tier];
            $slabsRequired = FortuneBonusParticipant::SLABS_REQUIRED[$tier];

            if ($personalBv < $bvRequired || $slabCount < $slabsRequired) {
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
        ];
    }

    /**
     * Calculate and credit Fortune Bonus for all enrolled participants in the month.
     * Idempotent: skips participants already credited.
     *
     * @return array{credited: int, skipped_no_bonus: int, total_net_paise: int}
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();

        $participants = FortuneBonusParticipant::where('month_start', $monthStart)
            ->orderBy('position')
            ->get();

        $credited = 0;
        $skippedNoBonusCount = 0;
        $totalNet = 0;

        DB::transaction(function () use ($participants, $monthStart, &$credited, &$skippedNoBonusCount, &$totalNet): void {
            foreach ($participants as $participant) {
                $alreadyCredited = FortuneBonusResult::where('distributor_id', $participant->distributor_id)
                    ->where('month_start', $monthStart)
                    ->whereIn('status', [FortuneBonusResult::STATUS_CREDITED, FortuneBonusResult::STATUS_SKIPPED])
                    ->exists();

                if ($alreadyCredited) {
                    continue;
                }

                $gross = FortuneBonusParticipant::LEVEL_BONUS_PAISE[$participant->matrix_level] ?? 0;

                if ($gross === 0) {
                    FortuneBonusResult::updateOrCreate(
                        [
                            'distributor_id' => $participant->distributor_id,
                            'month_start' => $monthStart,
                        ],
                        [
                            'position' => $participant->position,
                            'matrix_level' => $participant->matrix_level,
                            'gross_paise' => 0,
                            'tds_paise' => 0,
                            'net_paise' => 0,
                            'status' => FortuneBonusResult::STATUS_SKIPPED,
                        ],
                    );
                    $skippedNoBonusCount++;

                    continue;
                }

                $tds = (int) round($gross * self::TDS_RATE);
                $net = $gross - $tds;

                $result = FortuneBonusResult::updateOrCreate(
                    [
                        'distributor_id' => $participant->distributor_id,
                        'month_start' => $monthStart,
                    ],
                    [
                        'position' => $participant->position,
                        'matrix_level' => $participant->matrix_level,
                        'gross_paise' => $gross,
                        'tds_paise' => $tds,
                        'net_paise' => $net,
                        'status' => FortuneBonusResult::STATUS_PENDING,
                    ],
                );

                $this->wallet->credit(
                    distributorId: $participant->distributor_id,
                    amountPaise: $net,
                    type: 'fortune_credit',
                    referenceId: $result->id,
                    referenceType: 'fortune_bonus_result',
                    memo: 'Fortune Bonus Level '.$participant->matrix_level.' '.$monthStart,
                );

                $result->update([
                    'status' => FortuneBonusResult::STATUS_CREDITED,
                    'credited_at' => now(),
                ]);

                $totalNet += $net;
                $credited++;
            }
        });

        return [
            'credited' => $credited,
            'skipped_no_bonus' => $skippedNoBonusCount,
            'total_net_paise' => $totalNet,
        ];
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
     * Map distributor_id → count of credited GSB slabs in the month.
     *
     * @return array<int, int>
     */
    private function buildSlabCounts(string $monthStart, string $monthEnd): array
    {
        $rows = DB::table('gsb_cutoff_results')
            ->where('status', GsbCutoffResult::STATUS_CREDITED)
            ->whereBetween('cutoff_date', [$monthStart, $monthEnd])
            ->whereNotNull('slab')
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
     * Return distributor IDs that hold a rank ≥ 6 this month (ineligible for Fortune Bonus).
     *
     * @return array<int, int>
     */
    private function buildIneligibleRankIds(string $monthStart): array
    {
        return RankQualification::where('month_start', $monthStart)
            ->where('status', RankQualification::STATUS_QUALIFIED)
            ->whereIn('rank_number', self::INELIGIBLE_RANKS)
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

    private function determineTier(int $rank): string
    {
        return match (true) {
            $rank >= 5 => 'rank_5',
            $rank === 4 => 'rank_4',
            $rank === 3 => 'rank_3',
            $rank === 2 => 'rank_2',
            $rank === 1 => 'rank_1',
            default => 'non_ranked',
        };
    }
}
