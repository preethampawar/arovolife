<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * The distributor's income overview — shared by /income and /dashboard so the
 * two surfaces can never disagree on what was credited or when the next
 * payout falls.
 *
 * Every rupee here comes from the wallet ledger (money that actually reached
 * the wallet), never from the engine result tables, and every date is a
 * schedule fact — nothing is a projection (DSR 2021 r.5(1)(d)).
 */
final class IncomeOverviewService
{
    public function __construct(private readonly WalletService $wallet) {}

    /**
     * Flag-aware per-bonus rows: this month's and lifetime wallet credits. A
     * bonus that is switched off platform-wide is omitted entirely (same rule
     * as IncomeNavLinks) so the strip and the tab bar always agree.
     *
     * @return list<array{label: string, route: string, tip: string, monthPaise: int, lifetimePaise: int}>
     */
    public function bonusSummary(int $distributorId): array
    {
        $monthStart = Carbon::now('Asia/Kolkata')->startOfMonth();

        return self::bonusSummaryFromTotals(
            $this->wallet->creditTotalsByType($distributorId, $monthStart),
            $this->wallet->creditTotalsByType($distributorId),
        );
    }

    /**
     * @param  array<string, int>  $monthTotals
     * @param  array<string, int>  $lifetimeTotals
     * @return list<array{label: string, route: string, tip: string, monthPaise: int, lifetimePaise: int}>
     */
    public static function bonusSummaryFromTotals(array $monthTotals, array $lifetimeTotals): array
    {
        $rows = [
            ['type' => 'gsb_credit', 'label' => 'Genos Sales Bonus', 'route' => 'income.gsb-history', 'active' => Feature::for(null)->active(GenosSalesBonusFeature::class),
                'tip' => 'Your daily Genos Sales Bonus credits — earned when both your Left and Right groups match a slab at the 23:59 cut-off.'],
            ['type' => 'mb_credit', 'label' => 'Mentorship Bonus', 'route' => 'income.mentorship', 'active' => Feature::for(null)->active(MentorshipBonusFeature::class),
                'tip' => 'Earned when a distributor you directly sponsored matches a Genos Sales Bonus slab.'],
            ['type' => 'gbb_credit', 'label' => 'Growth Booster Bonus', 'route' => 'income.growth-booster', 'active' => Feature::for(null)->active(GrowthBoosterBonusFeature::class),
                'tip' => 'Monthly bonus from arovolife Growth Points (AGP) recorded on Slab 1–3 matches, for distributors who held no rank in the previous month.'],
            ['type' => 'rank_credit', 'label' => 'Rank Bonus', 'route' => 'income.rank-bonus', 'active' => Feature::for(null)->active(RankBonusFeature::class),
                'tip' => 'Monthly bonus from your rank\'s pool, credited on the 1st of the following month.'],
            ['type' => 'fortune_credit', 'label' => 'Fortune Bonus', 'route' => 'income.fortune-bonus', 'active' => Feature::for(null)->active(FortuneBonusFeature::class),
                'tip' => 'Monthly matrix bonus based on your Genos Sales Bonus activity.'],
            ['type' => 'adc_credit', 'label' => 'ADC Bonus', 'route' => 'income.adc-bonus', 'active' => Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class),
                'tip' => 'Arete Development Center bonus on BV served by your approved center.'],
        ];

        $summary = [];
        foreach ($rows as $row) {
            if (! $row['active']) {
                continue;
            }
            $summary[] = [
                'label' => $row['label'],
                'route' => $row['route'],
                'tip' => $row['tip'],
                'monthPaise' => $monthTotals[$row['type']] ?? 0,
                'lifetimePaise' => $lifetimeTotals[$row['type']] ?? 0,
            ];
        }

        return $summary;
    }

    /**
     * The distributor's income calendar — schedule facts only, no amounts.
     *
     * `hasMonthlyBonuses` hides the monthly-payout card while every monthly
     * bonus is still flag-gated off, so no surface names a payout day that
     * cannot yet pay anything.
     *
     * @return array{nextCutoff: Carbon, nextWeeklyPayout: Carbon, nextMonthlyPayout: Carbon, hasMonthlyBonuses: bool}
     */
    public static function keyDates(): array
    {
        $nowIst = Carbon::now('Asia/Kolkata');

        // The day's BV locks at 23:59 IST; the engine settles it just after
        // midnight. Distributor-facing copy uses the lock time throughout.
        $nextCutoff = $nowIst->copy()->setTime(23, 59, 0);

        // The weekly batch runs at 03:00 IST on Tuesday. Same shape as the
        // monthly one below: comparing by calendar day alone kept saying
        // "today" for the rest of Tuesday, after the batch had already run.
        $daysUntilTuesday = (2 - $nowIst->dayOfWeek + 7) % 7;
        $weeklyRun = $nowIst->copy()->addDays($daysUntilTuesday)->setTime(3, 0, 0);
        if ($nowIst->gte($weeklyRun)) {
            $weeklyRun->addWeek();
        }
        $nextWeeklyPayout = $weeklyRun->startOfDay();

        // The monthly batch runs at 04:00 IST on the 8th — the crediting engines
        // close the month on the 1st and payment waits a week. Same shape as the
        // weekly one above: comparing by calendar day alone kept saying "today"
        // for the remaining ~20 hours of the day, after the transfer had already
        // gone out.
        $monthlyRunThisMonth = $nowIst->copy()->startOfMonth()->addDays(7)->setTime(4, 0, 0);
        $nextMonthlyPayout = $nowIst->lt($monthlyRunThisMonth)
            ? $monthlyRunThisMonth->copy()->startOfDay()
            : $nowIst->copy()->addMonthNoOverflow()->startOfMonth()->addDays(7)->startOfDay();

        $hasMonthlyBonuses = Feature::for(null)->active(GrowthBoosterBonusFeature::class)
            || Feature::for(null)->active(RankBonusFeature::class)
            || Feature::for(null)->active(FortuneBonusFeature::class)
            || Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class);

        return [
            'nextCutoff' => $nextCutoff,
            'nextWeeklyPayout' => $nextWeeklyPayout,
            'nextMonthlyPayout' => $nextMonthlyPayout,
            'hasMonthlyBonuses' => $hasMonthlyBonuses,
        ];
    }
}
