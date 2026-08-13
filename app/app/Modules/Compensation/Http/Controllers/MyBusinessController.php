<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\GsbSlabProgressService;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Services\TeamStatsService;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * "My Business" — the distributor's one-page snapshot of their own account:
 * income navigation, personal BV and title, the next payout, the per-side
 * carry over / carry forward split and today's Genos BV alongside team size.
 *
 * Every figure is a read-only mirror of the same services /income uses, so the
 * two surfaces can never disagree, and every figure is the distributor's own
 * historical data — nothing here projects future income (DSR 2021 r.5(1)(d)).
 */
final class MyBusinessController extends Controller
{
    public function __invoke(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        $distributorId = $distributor->id;
        $gsbOn = Feature::for(null)->active(GenosSalesBonusFeature::class);

        try {
            $personalBvPaise = app(BvLedgerService::class)->totalPersonalBvPaise($distributorId);
            $title = app(PersonalBvTitleService::class)->forBvPaise($personalBvPaise);

            // Same gate as /income: below the minimum lifetime personal BV the
            // cut-off discards group BV entirely, so the page must show 0 rather
            // than a raw accumulator the distributor will never be credited for.
            $gsbMinBvPaise = app(CompensationPlanSettingsService::class)->gsbMinBvPaise();
            $genosBvEligible = $personalBvPaise >= $gsbMinBvPaise;

            $walletBalancePaise = app(WalletService::class)->balancePaise($distributorId);

            $today = Carbon::today('Asia/Kolkata')->toDateString();
            $dailyBv = $genosBvEligible
                ? GroupBvDaily::where('distributor_id', $distributorId)
                    ->whereDate('date', $today)
                    ->first()
                : null;

            $slabProgress = ($gsbOn && $genosBvEligible)
                ? app(GsbSlabProgressService::class)->forDistributor($distributorId)
                : null;

            // Carry forward in the plan's strict sense: the BV remaining after
            // the last slab match (the weaker side resets to 0, the power
            // side's remainder survives). Zero until the first slab matches —
            // BV building up before a match is carry over, not carry forward.
            $lastMatch = ($gsbOn && $genosBvEligible)
                ? GsbCutoffResult::query()
                    ->where('distributor_id', $distributorId)
                    ->whereNotNull('slab')
                    ->whereIn('status', [
                        GsbCutoffResult::STATUS_CREDITED,
                        GsbCutoffResult::STATUS_FROZEN,
                        GsbCutoffResult::STATUS_REPURCHASE_HELD,
                        GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED,
                        GsbCutoffResult::STATUS_REVERSED,
                    ])
                    ->orderByDesc('cutoff_date')
                    ->orderByDesc('id')
                    ->first()
                : null;

            $teamCounts = app(TeamStatsService::class)->counts($distributor);
        } catch (QueryException) {
            $personalBvPaise = null;
            $title = null;
            $gsbMinBvPaise = null;
            $genosBvEligible = true;
            $walletBalancePaise = null;
            $dailyBv = null;
            $slabProgress = null;
            $lastMatch = null;
            $teamCounts = ['left_team' => 0, 'right_team' => 0, 'total_team' => 0];
        }

        // Next Tuesday (or today if it is Tuesday) — same rule as the wallet page.
        $todayIst = now()->timezone('Asia/Kolkata');
        $daysUntilTuesday = (2 - $todayIst->dayOfWeek + 7) % 7;
        $nextPayout = $daysUntilTuesday === 0 ? $todayIst->copy() : $todayIst->copy()->addDays($daysUntilTuesday);

        return view('my-business', compact(
            'distributor', 'personalBvPaise', 'title', 'gsbMinBvPaise', 'genosBvEligible',
            'walletBalancePaise', 'nextPayout', 'dailyBv', 'slabProgress', 'lastMatch', 'teamCounts',
            'gsbOn',
        ));
    }
}
