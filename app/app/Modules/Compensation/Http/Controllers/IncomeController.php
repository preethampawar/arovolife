<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers;

use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class IncomeController extends Controller
{
    private const int PER_PAGE = 50;

    public function dashboard(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return view('income.dashboard', ['distributor' => $distributor]);
    }

    public function genosBv(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        try {
            $rows = GsbCutoffResult::where('distributor_id', $distributor->id)
                ->when($request->filled('from'), fn ($q) => $q->where('cutoff_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->where('cutoff_date', '<=', $request->input('to')))
                ->orderByDesc('cutoff_date')
                ->paginate(self::PER_PAGE)
                ->withQueryString();
        } catch (QueryException) {
            $rows = collect();
        }

        return view('income.genos-bv', compact('distributor', 'rows'));
    }

    public function gsbHistory(Request $request): View
    {
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
                    number_format($row->left_bv_paise / 100, 0),
                    number_format($row->right_bv_paise / 100, 0),
                    $row->slab,
                    number_format($row->gross_gsb_paise / 100, 2),
                    number_format($row->admin_charge_paise / 100, 2),
                    number_format($row->tds_paise / 100, 2),
                    number_format($row->net_gsb_paise / 100, 2),
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
        } catch (QueryException) {
            $rows = collect();
        }

        return view('income.mentorship', compact('distributor', 'rows'));
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
        } catch (QueryException) {
            $rows = collect();
            $totalNet = 0;
        }

        return view('income.rank-bonus', compact('distributor', 'rows', 'totalNet'));
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

        return view('income.wallet', compact('distributor', 'ledgerRows', 'payoutRows'));
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
                    number_format($entry->amount_paise / 100, 2),
                    number_format($balance / 100, 2),
                ]);
            }
            fclose($out);
        }, 'wallet-ledger.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
