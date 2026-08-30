<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\GsbSlabProgressService;
use App\Modules\Compensation\Services\IncomeOverviewService;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Compensation\Services\RankStatusService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Services\DistributorIdCardStats;
use App\Modules\Identity\Services\TeamStatsService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Shared\Features\AreteCenterApplicationsFeature;
use App\Modules\Shared\Features\DistributorRequestsFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

/**
 * Distributor "My Dashboard" page.
 *
 * Aggregates everything a logged-in distributor would want to see at a
 * glance: their identity card (ADN, placement leg, cooling-off status),
 * a slot-aware referral-link widget, headline KPIs (personal BV, wallet,
 * today's Left/Right Genos BV), a team summary covering both the
 * sponsorship tree and the binary genealogy, and an income snapshot.
 *
 * All stat assembly is delegated to the same services that power
 * My Business, /income and the tree-view cards — {@see TeamStatsService},
 * {@see DistributorIdCardStats}, {@see WalletService},
 * {@see IncomeOverviewService} — so every surface reads from a single
 * source of truth. Every figure is the distributor's own historical data;
 * nothing here projects future income (DSR 2021 r.5(1)(d)).
 */
final class DashboardController extends Controller
{
    public function index(
        PlacementEngine $engine,
        TeamStatsService $teamStatsService,
        DistributorIdCardStats $idCardService,
        IncomeOverviewService $incomeOverview,
    ): View|RedirectResponse {
        $user = Auth::user();

        // Admin accounts have no distributor row, so the dashboard would
        // otherwise render the "Registration not yet complete" prompt at
        // them. They have their own console — send them there instead.
        if ($user !== null && $user->isSuperStaff()) {
            return redirect()->route('admin.dashboard');
        }

        $distributor = $user?->distributor;

        $leftOpen = $rightOpen = false;
        $maxObservedDepth = 0;
        $teamStats = null;
        $idCardStats = null;
        $idPhotoUrl = null;

        // Feature flags hoisted once so the Blade branches on plain bools.
        $gsbOn = Feature::for(null)->active(GenosSalesBonusFeature::class);
        $rankOn = Feature::for(null)->active(RankBonusFeature::class);
        $offersOn = Feature::for(null)->active(PurchaseOffersFeature::class);
        $adcDirectoryOn = Feature::for(null)->active(AreteCenterApplicationsFeature::class);
        $requestsOn = Feature::for(null)->active(DistributorRequestsFeature::class);

        $personalBvPaise = null;
        $title = null;
        $genosBvEligible = true;
        $gsbMinBvPaise = null;
        $walletBalancePaise = null;
        $dailyBv = null;
        $slabProgress = null;
        $rankStatus = null;
        $bonusSummary = [];
        $keyDates = IncomeOverviewService::keyDates();
        $teamGrowth = [];
        $creditsByMonth = [];

        if ($distributor !== null) {
            $leftOpen = $engine->hasOpenSlot($distributor->id, 'L');
            $rightOpen = $engine->hasOpenSlot($distributor->id, 'R');

            $maxObservedDepth = (int) DB::table('genealogy_closure')
                ->where('ancestor_id', $distributor->id)
                ->where('depth', '>', 0)
                ->max('depth');

            $teamStats = $teamStatsService->full($distributor);
            $idCardStats = $idCardService->full($distributor);
            $idPhotoUrl = $idCardService->photoUrl($distributor);

            // Business KPIs — mirrors MyBusinessController / IncomeController
            // so the dashboard tiles can never disagree with those pages. The
            // QueryException guard keeps the page rendering on a half-migrated
            // database, same as every other income surface.
            try {
                $distributorId = (int) $distributor->id;

                $personalBvPaise = app(BvLedgerService::class)->totalPersonalBvPaise($distributorId);
                $title = app(PersonalBvTitleService::class)->forBvPaise($personalBvPaise);

                // Below the minimum lifetime personal BV the cut-off discards
                // group BV entirely, so show 0 rather than a raw accumulator.
                $gsbMinBvPaise = app(CompensationPlanSettingsService::class)->gsbMinBvPaise();
                $genosBvEligible = $personalBvPaise >= $gsbMinBvPaise;

                $walletBalancePaise = app(WalletService::class)->balancePaise($distributorId);

                $today = Carbon::today('Asia/Kolkata')->toDateString();
                $dailyBv = ($gsbOn && $genosBvEligible)
                    ? GroupBvDaily::where('distributor_id', $distributorId)->whereDate('date', $today)->first()
                    : null;

                $slabProgress = ($gsbOn && $genosBvEligible)
                    ? app(GsbSlabProgressService::class)->forDistributor($distributorId)
                    : null;

                $rankStatus = $rankOn
                    ? app(RankStatusService::class)->forDistributor($distributor)
                    : null;

                $bonusSummary = $incomeOverview->bonusSummary($distributorId);
                $teamGrowth = $teamStatsService->joinedPerDay($distributor, 30);
                $creditsByMonth = app(WalletService::class)->creditTotalsByMonth($distributorId, 6);
            } catch (QueryException) {
                $personalBvPaise = null;
                $title = null;
                $genosBvEligible = true;
                $gsbMinBvPaise = null;
                $walletBalancePaise = null;
                $dailyBv = null;
                $slabProgress = null;
                $rankStatus = null;
                $bonusSummary = [];
                $teamGrowth = [];
                $creditsByMonth = [];
            }
        }

        // Messages card — unread count + latest received message preview.
        // The eager-load on fromUser is cheap (one extra SELECT for one row)
        // and keeps the Blade simple: $latestMessage->fromUser->full_name.
        $unreadMessagesCount = $user !== null
            ? (int) Message::unreadFor((int) $user->id)->count()
            : 0;

        $latestMessage = $user !== null
            ? Message::query()
                ->where('to_user_id', $user->id)
                ->with(['fromUser:id,full_name,email'])
                ->latest('created_at')
                ->first()
            : null;

        return view('dashboard.index', [
            'user' => $user,
            'distributor' => $distributor,
            'leftOpen' => $leftOpen,
            'rightOpen' => $rightOpen,
            'maxObservedDepth' => $maxObservedDepth,
            'teamStats' => $teamStats,
            'idCardStats' => $idCardStats,
            'idPhotoUrl' => $idPhotoUrl,
            'unreadMessagesCount' => $unreadMessagesCount,
            'latestMessage' => $latestMessage,
            'gsbOn' => $gsbOn,
            'rankOn' => $rankOn,
            'offersOn' => $offersOn,
            'adcDirectoryOn' => $adcDirectoryOn,
            'requestsOn' => $requestsOn,
            'personalBvPaise' => $personalBvPaise,
            'title' => $title,
            'genosBvEligible' => $genosBvEligible,
            'gsbMinBvPaise' => $gsbMinBvPaise,
            'walletBalancePaise' => $walletBalancePaise,
            'dailyBv' => $dailyBv,
            'slabProgress' => $slabProgress,
            'rankStatus' => $rankStatus,
            'bonusSummary' => $bonusSummary,
            'keyDates' => $keyDates,
            'teamGrowth' => $teamGrowth,
            'creditsByMonth' => $creditsByMonth,
        ]);
    }
}
