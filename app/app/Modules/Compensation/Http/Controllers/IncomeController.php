<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Services\AogoOfferService;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\GenosBvLedgerService;
use App\Modules\Compensation\Services\GsbSlabProgressService;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Compensation\Services\RankStatusService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class IncomeController extends Controller
{
    private const int PER_PAGE = 50;

    public function dashboard(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        $distributorId = $distributor->id;
        $gsbOn = Feature::for(null)->active(GenosSalesBonusFeature::class);

        try {
            $walletService = app(WalletService::class);
            $walletBalancePaise = $walletService->balancePaise($distributorId);

            $bvLedger = app(BvLedgerService::class);
            $personalBvPaise = $bvLedger->totalPersonalBvPaise($distributorId);
            $titleService = app(PersonalBvTitleService::class);
            $title = $titleService->forBvPaise($personalBvPaise);

            // Plan rule: below the minimum lifetime personal BV (default 600),
            // downline Genos BV is not credited to the distributor at all, so
            // the dashboard must show 0 — not the raw accumulator, which the
            // cut-off will discard with status BELOW_600BV.
            $gsbMinBvPaise = app(CompensationPlanSettingsService::class)->gsbMinBvPaise();
            $genosBvEligible = $personalBvPaise >= $gsbMinBvPaise;

            $today = Carbon::today('Asia/Kolkata')->toDateString();
            $dailyBv = $genosBvEligible
                ? GroupBvDaily::where('distributor_id', $distributorId)
                    ->whereDate('date', $today)
                    ->first()
                : null;

            $cf = $gsbOn
                ? GsbCarryforward::where('distributor_id', $distributorId)->first()
                : null;

            // Same CF-inclusive per-side figures the Genos BV page shows, so the
            // two pages never disagree: before the first slab matches, the whole
            // leg is carried forward and becomes the next day's opening balance.
            $slabProgress = ($gsbOn && $genosBvEligible)
                ? app(GsbSlabProgressService::class)->forDistributor($distributorId)
                : null;

            // Per-bonus "credited to wallet" summary. Sourced from the wallet
            // ledger — not the engine result tables — so every figure is money
            // that actually reached the wallet (strictly historical; DSR 2021
            // r.5(1)(d) forbids projections).
            $monthStart = Carbon::now('Asia/Kolkata')->startOfMonth();
            $walletCreditsMonth = $walletService->creditTotalsByType($distributorId, $monthStart);
            $walletCreditsLifetime = $walletService->creditTotalsByType($distributorId);
        } catch (QueryException) {
            $walletBalancePaise = null;
            $personalBvPaise = null;
            $title = null;
            $dailyBv = null;
            $cf = null;
            $gsbMinBvPaise = null;
            $genosBvEligible = true;
            $slabProgress = null;
            $walletCreditsMonth = [];
            $walletCreditsLifetime = [];
        }

        $bonusSummary = self::bonusSummary($walletCreditsMonth, $walletCreditsLifetime);
        $keyDates = self::keyDates();

        return view('income.dashboard', compact(
            'distributor', 'walletBalancePaise', 'personalBvPaise',
            'title', 'dailyBv', 'cf', 'genosBvEligible', 'gsbMinBvPaise', 'slabProgress',
            'bonusSummary', 'keyDates', 'gsbOn',
        ));
    }

    /**
     * The distributor's income calendar — schedule facts only, no amounts.
     *
     * `hasMonthlyBonuses` hides the monthly-payout card while every monthly
     * bonus is still flag-gated off, so the dashboard never names a payout
     * day that cannot yet pay anything.
     *
     * @return array{nextCutoff: Carbon, nextWeeklyPayout: Carbon, nextMonthlyPayout: Carbon, hasMonthlyBonuses: bool}
     */
    private static function keyDates(): array
    {
        $nowIst = Carbon::now('Asia/Kolkata');

        // The day's BV locks at 23:59 IST; the engine settles it just after
        // midnight. Distributor-facing copy uses the lock time throughout.
        $nextCutoff = $nowIst->copy()->setTime(23, 59, 0);

        $daysUntilTuesday = (2 - $nowIst->dayOfWeek + 7) % 7;
        $nextWeeklyPayout = $nowIst->copy()->addDays($daysUntilTuesday)->startOfDay();

        $nextMonthlyPayout = ($nowIst->day <= 9 ? $nowIst->copy()->day(9) : $nowIst->copy()->addMonthNoOverflow()->day(9))->startOfDay();

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

    /**
     * Flag-aware per-bonus rows for the dashboard summary strip. A bonus that
     * is switched off platform-wide is omitted entirely (same rule as
     * IncomeNavLinks) so the strip and the tab bar always agree.
     *
     * @param  array<string, int>  $monthTotals
     * @param  array<string, int>  $lifetimeTotals
     * @return list<array{label: string, route: string, tip: string, monthPaise: int, lifetimePaise: int}>
     */
    private static function bonusSummary(array $monthTotals, array $lifetimeTotals): array
    {
        $rows = [
            ['type' => 'gsb_credit', 'label' => 'Genos Sales Bonus', 'route' => 'income.gsb-history', 'active' => Feature::for(null)->active(GenosSalesBonusFeature::class),
                'tip' => 'Your daily Genos Sales Bonus credits — earned when both your Left and Right groups match a slab at the 23:59 cut-off.'],
            ['type' => 'mb_credit', 'label' => 'Mentorship Bonus', 'route' => 'income.mentorship', 'active' => Feature::for(null)->active(MentorshipBonusFeature::class),
                'tip' => 'Earned when a distributor you directly sponsored matches a Genos Sales Bonus slab.'],
            ['type' => 'gbb_credit', 'label' => 'Growth Booster Bonus', 'route' => 'income.growth-booster', 'active' => Feature::for(null)->active(GrowthBoosterBonusFeature::class),
                'tip' => 'Monthly bonus from arovolife Growth Points (AGP) recorded on Slab 1–3 matches, for distributors who held no rank in the previous month.'],
            ['type' => 'rank_credit', 'label' => 'Rank Bonus', 'route' => 'income.rank-bonus', 'active' => Feature::for(null)->active(RankBonusFeature::class),
                'tip' => 'Monthly bonus from your rank\'s pool, credited on the 8th of the following month.'],
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

    public function genosBv(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        $gsbOn = Feature::for(null)->active(GenosSalesBonusFeature::class);

        try {
            // The slab ladder is the GSB configuration table — it disappears
            // with the flag; the historical daily rows below it remain facts.
            $slabProgress = $gsbOn
                ? app(GsbSlabProgressService::class)->forDistributor($distributor->id)
                : null;

            $rows = GsbCutoffResult::where('distributor_id', $distributor->id)
                ->when($request->filled('from'), fn ($q) => $q->where('cutoff_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->where('cutoff_date', '<=', $request->input('to')))
                ->orderByDesc('cutoff_date')
                ->paginate(self::PER_PAGE)
                ->withQueryString();
        } catch (QueryException) {
            $slabProgress = null;
            $rows = collect();
        }

        return view('income.genos-bv', compact('distributor', 'rows', 'slabProgress', 'gsbOn'));
    }

    public function genosLedger(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        $from = $request->filled('from') ? Carbon::parse((string) $request->input('from')) : null;
        $to = $request->filled('to') ? Carbon::parse((string) $request->input('to')) : null;

        $gsbOn = Feature::for(null)->active(GenosSalesBonusFeature::class);

        try {
            $personalBvPaise = app(BvLedgerService::class)->totalPersonalBvPaise($distributor->id);
            $gsbMinBvPaise = app(CompensationPlanSettingsService::class)->gsbMinBvPaise();
            // The eligibility minimum is a GSB rule — with the flag off the
            // ledger is plain BV data and shows for everyone.
            $genosBvEligible = ! $gsbOn || $personalBvPaise >= $gsbMinBvPaise;

            // Same rule as the dashboard: below the minimum personal BV, group
            // BV is never credited, so the ledger stays hidden — not a raw
            // accumulator the cut-off will discard.
            $days = $genosBvEligible
                ? app(GenosBvLedgerService::class)
                    ->paginateDays($distributor->id, $from, $to)
                    ->withQueryString()
                : collect();
            $openDebts = $genosBvEligible
                ? app(GenosBvLedgerService::class)->openDebts($distributor->id)
                : ['L' => 0, 'R' => 0];
        } catch (QueryException) {
            $genosBvEligible = true;
            $gsbMinBvPaise = null;
            $days = collect();
            $openDebts = ['L' => 0, 'R' => 0];
        }

        return view('income.genos-ledger', compact('distributor', 'days', 'genosBvEligible', 'gsbMinBvPaise', 'openDebts', 'gsbOn'));
    }

    public function gsbHistory(Request $request): View
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        try {
            $rows = GsbCutoffResult::where('distributor_id', $distributor->id)
                ->where('status', GsbCutoffResult::STATUS_CREDITED)
                ->when($request->filled('from'), fn ($q) => $q->where('cutoff_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->where('cutoff_date', '<=', $request->input('to')))
                ->orderByDesc('cutoff_date')
                ->paginate(self::PER_PAGE)
                ->withQueryString();
        } catch (QueryException) {
            $rows = collect();
        }

        return view('income.gsb-history', compact('distributor', 'rows'));
    }

    public function exportGsb(Request $request): StreamedResponse
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        $rows = GsbCutoffResult::where('distributor_id', $distributor->id)
            ->where('status', GsbCutoffResult::STATUS_CREDITED)
            ->when($request->filled('from'), fn ($q) => $q->where('cutoff_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('cutoff_date', '<=', $request->input('to')))
            ->orderByDesc('cutoff_date')
            ->cursor();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Left BV matched', 'Right BV matched', 'Slab', 'Gross GSB (₹)', 'Admin Charge (₹)', 'TDS (₹)', 'Net GSB (₹)', 'Status']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->cutoff_date->toDateString(),
                    number_format($row->left_bv_paise / 100, 0, '.', ''),
                    number_format($row->right_bv_paise / 100, 0, '.', ''),
                    $row->slab,
                    number_format($row->gross_gsb_paise / 100, 2, '.', ''),
                    number_format($row->admin_charge_paise / 100, 2, '.', ''),
                    number_format($row->tds_paise / 100, 2, '.', ''),
                    number_format($row->net_gsb_paise / 100, 2, '.', ''),
                    $row->status,
                ]);
            }
            fclose($out);
        }, 'gsb-history.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function mentorship(Request $request): View
    {
        abort_unless(Feature::for(null)->active(MentorshipBonusFeature::class), 404);

        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        try {
            $rows = MentorshipBonusResult::where('sponsor_id', $distributor->id)
                ->with('sponsee')
                ->when($request->filled('from'), fn ($q) => $q->where('cutoff_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->where('cutoff_date', '<=', $request->input('to')))
                ->orderByDesc('cutoff_date')
                ->paginate(self::PER_PAGE)
                ->withQueryString();

            $rows->getCollection()->transform(function (MentorshipBonusResult $row): MentorshipBonusResult {
                $adn = $row->sponsee?->adn ?? '';
                $row->sponsee_adn = $adn !== ''
                    ? mb_substr($adn, 0, 2).'***'.mb_substr($adn, -2)
                    : '—';

                return $row;
            });
            $creditedBase = MentorshipBonusResult::where('sponsor_id', $distributor->id)
                ->where('status', MentorshipBonusResult::STATUS_CREDITED);
            $mbThisMonthPaise = (int) (clone $creditedBase)
                ->whereBetween('cutoff_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('mb_gross_paise');
            $mbLifetimePaise = (int) (clone $creditedBase)->sum('mb_gross_paise');
            $activeSponsees = (int) (clone $creditedBase)->distinct()->count('sponsee_id');
        } catch (QueryException) {
            $rows = collect();
            $mbThisMonthPaise = 0;
            $mbLifetimePaise = 0;
            $activeSponsees = 0;
        }

        return view('income.mentorship', compact('distributor', 'rows', 'mbThisMonthPaise', 'mbLifetimePaise', 'activeSponsees'));
    }

    public function growthBooster(Request $request): View
    {
        abort_unless(Feature::for(null)->active(GrowthBoosterBonusFeature::class), 404);

        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        try {
            $rows = GbbMonthlyResult::where('distributor_id', $distributor->id)
                ->where('status', GbbMonthlyResult::STATUS_CREDITED)
                ->when($request->filled('from'), fn ($q) => $q->where('year_month', '>=', $request->input('from').'-01'))
                ->when($request->filled('to'), fn ($q) => $q->where('year_month', '<=', $request->input('to').'-01'))
                ->orderByDesc('year_month')
                ->paginate(self::PER_PAGE)
                ->withQueryString();

            $totalAgp = $rows->getCollection()->sum('agp_earned');
            $totalNet = $rows->getCollection()->sum('gbb_net_paise');
        } catch (QueryException) {
            $rows = collect();
            $totalAgp = 0;
            $totalNet = 0;
        }

        return view('income.growth-booster', compact('distributor', 'rows', 'totalAgp', 'totalNet'));
    }

    public function rankBonus(Request $request): View
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        try {
            $rows = RankBonusResult::where('distributor_id', $distributor->id)
                ->where('status', RankBonusResult::STATUS_CREDITED)
                ->when($request->filled('from'), fn ($q) => $q->where('month_start', '>=', $request->input('from').'-01'))
                ->when($request->filled('to'), fn ($q) => $q->where('month_start', '<=', $request->input('to').'-01'))
                ->orderByDesc('month_start')
                ->orderBy('rank_number')
                ->paginate(self::PER_PAGE)
                ->withQueryString();

            $totalNet = $rows->getCollection()->sum('net_paise');

            // AO-GO: lifetime counter ("x of 3 used") plus this month's
            // published conditions measured against the distributor's own state.
            $aogoStatus = app(AogoOfferService::class)
                ->eligibilityFor((int) $distributor->id, Carbon::today('Asia/Kolkata'));
            $aogoUsed = $aogoStatus->usesUsed;
            $aogoMax = $aogoStatus->usesMax;
            $rankStatus = app(RankStatusService::class)->forDistributor($distributor);
        } catch (QueryException) {
            $rows = collect();
            $totalNet = 0;
            $aogoStatus = null;
            $aogoUsed = 0;
            $aogoMax = app(CompensationPlanSettingsService::class)->aogoLifetimeMax();
            $rankStatus = null;
        }

        return view('income.rank-bonus', compact('distributor', 'rows', 'totalNet', 'aogoUsed', 'aogoMax', 'aogoStatus', 'rankStatus'));
    }

    public function fortuneBonus(Request $request): View
    {
        abort_unless(Feature::for(null)->active(FortuneBonusFeature::class), 404);

        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        try {
            $rows = FortuneBonusResult::where('distributor_id', $distributor->id)
                ->whereIn('status', [FortuneBonusResult::STATUS_CREDITED, FortuneBonusResult::STATUS_SKIPPED])
                ->when($request->filled('from'), fn ($q) => $q->where('month_start', '>=', $request->input('from').'-01'))
                ->when($request->filled('to'), fn ($q) => $q->where('month_start', '<=', $request->input('to').'-01'))
                ->orderByDesc('month_start')
                ->paginate(self::PER_PAGE)
                ->withQueryString();

            $totalNet = $rows->getCollection()->sum('net_paise');
        } catch (QueryException) {
            $rows = collect();
            $totalNet = 0;
        }

        return view('income.fortune-bonus', compact('distributor', 'rows', 'totalNet'));
    }

    public function adcBonus(Request $request): View
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        try {
            $rows = AdcBonusResult::where('distributor_id', $distributor->id)
                ->where('status', AdcBonusResult::STATUS_CREDITED)
                ->with('center')
                ->when($request->filled('from'), fn ($q) => $q->where('month_start', '>=', $request->input('from').'-01'))
                ->when($request->filled('to'), fn ($q) => $q->where('month_start', '<=', $request->input('to').'-01'))
                ->orderByDesc('month_start')
                ->paginate(self::PER_PAGE)
                ->withQueryString();

            $totalNet = $rows->getCollection()->sum('net_paise');
        } catch (QueryException) {
            $rows = collect();
            $totalNet = 0;
        }

        return view('income.adc-bonus', compact('distributor', 'rows', 'totalNet'));
    }

    public function wallet(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        $walletService = app(WalletService::class);

        try {
            $ledgerRows = $walletService->ledgerWithRunningBalance($distributor->id);
        } catch (QueryException) {
            $ledgerRows = collect();
        }

        try {
            $payoutRows = PayoutLineItem::where('distributor_id', $distributor->id)
                ->orderByDesc('created_at')
                ->get();
        } catch (QueryException) {
            $payoutRows = collect();
        }

        $walletBalancePaise = $walletService->balancePaise($distributor->id);

        $totalPaidOutPaise = app(PayoutService::class)->totalTransferredPaise((int) $distributor->id);

        // Next Tuesday (or today if it is Tuesday).
        $today = now()->timezone('Asia/Kolkata');
        $daysUntilTuesday = (2 - $today->dayOfWeek + 7) % 7;
        $nextPayout = $daysUntilTuesday === 0 ? $today->copy() : $today->copy()->addDays($daysUntilTuesday);

        $minThresholdPaise = app(CompensationPlanSettingsService::class)->minPayoutPaise();

        return view('income.wallet', compact(
            'distributor', 'ledgerRows', 'payoutRows',
            'walletBalancePaise', 'totalPaidOutPaise', 'nextPayout', 'minThresholdPaise',
        ));
    }

    public function exportWallet(Request $request): StreamedResponse
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        $walletService = app(WalletService::class);

        try {
            $ledgerRows = $walletService->ledgerWithRunningBalance($distributor->id);
        } catch (QueryException) {
            $ledgerRows = collect();
        }

        return response()->streamDownload(function () use ($ledgerRows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Type', 'Amount (₹)', 'Running Balance (₹)']);
            foreach ($ledgerRows as $item) {
                $entry = $item['entry'];
                $balance = $item['running_balance_paise'];
                fputcsv($out, [
                    $entry->created_at?->toDateString(),
                    $entry->type,
                    number_format($entry->amount_paise / 100, 2, '.', ''),
                    number_format($balance / 100, 2, '.', ''),
                ]);
            }
            fclose($out);
        }, 'wallet-ledger.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
