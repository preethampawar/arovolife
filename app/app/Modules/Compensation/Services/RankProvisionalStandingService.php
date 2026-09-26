<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\RankProvisionalStanding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The rank progress snapshot: who meets each rank's conditions so far this
 * month, measured by the monthly check's own rules
 * ({@see RankQualificationService::evaluateMonth()}) up to the last settled day.
 *
 * Progress only — never a rank. Ranks are recorded by `rank:check-qualifications`
 * on the 1st and nowhere else; this table feeds the progress views and nothing
 * that pays, pools, grants, announces or terminates.
 */
final class RankProvisionalStandingService
{
    private const int INSERT_CHUNK = 1000;

    public function __construct(
        private readonly RankQualificationService $rankQualification,
    ) {}

    /**
     * Replace the snapshot with the month containing `$asOf`, measured through
     * the end of `$asOf`. Returns the number of rows written.
     */
    public function snapshot(Carbon $asOf): int
    {
        $asOf = $asOf->copy()->startOfDay();
        $monthStart = $asOf->copy()->startOfMonth();

        $evaluation = $this->rankQualification->evaluateMonth($monthStart, 1, $asOf);

        $now = Carbon::now();
        $rows = [];

        foreach ($evaluation->qualifierIds as $rank => $ids) {
            foreach ($ids as $distributorId) {
                $rows[] = [
                    'distributor_id' => $distributorId,
                    'month_start' => $monthStart->toDateString(),
                    'rank_number' => $rank,
                    'as_of_date' => $asOf->toDateString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // DELETE, not TRUNCATE: TRUNCATE commits implicitly on MySQL, and a
        // reader must never see the table empty between the two statements.
        DB::transaction(function () use ($rows): void {
            RankProvisionalStanding::query()->delete();

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                RankProvisionalStanding::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    /** The settled day of whatever snapshot is stored, any month, or null when the table is empty. */
    public function newestAsOf(): ?Carbon
    {
        $date = RankProvisionalStanding::query()->max('as_of_date');

        return $date === null ? null : Carbon::parse((string) $date, 'Asia/Kolkata')->startOfDay();
    }

    /** The settled day the month's snapshot measures up to, or null when there is none yet. */
    public function asOf(Carbon $month): ?Carbon
    {
        $date = RankProvisionalStanding::query()
            ->where('month_start', $month->copy()->startOfMonth()->toDateString())
            ->max('as_of_date');

        return $date === null ? null : Carbon::parse((string) $date);
    }

    /**
     * The highest rank whose conditions this distributor meets in the month's
     * snapshot. ADMIN VIEW ONLY — a distributor is never shown a provisional
     * rank (hard rule 3: it would imply a rank, and a Rank Bonus, not earned).
     */
    public function highestFor(int $distributorId, Carbon $month): ?int
    {
        $rank = RankProvisionalStanding::query()
            ->where('distributor_id', $distributorId)
            ->where('month_start', $month->copy()->startOfMonth()->toDateString())
            ->max('rank_number');

        return $rank === null ? null : (int) $rank;
    }
}
