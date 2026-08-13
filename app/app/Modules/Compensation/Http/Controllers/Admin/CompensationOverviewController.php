<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class CompensationOverviewController extends Controller
{
    public function __invoke(): View
    {
        $today = Carbon::today()->toDateString();

        // With the GSB flag off the cut-off engine does not exist for this
        // page — every GSB section is hidden, only the payout queue remains.
        $gsbOn = Feature::for(null)->active(GenosSalesBonusFeature::class);

        $todayCutoffs = $gsbOn ? GsbCutoffResult::where('cutoff_date', $today)->get() : collect();
        $todayFailed = $todayCutoffs->where('status', GsbCutoffResult::STATUS_FAILED)->count();
        $todayCredited = $todayCutoffs->where('status', GsbCutoffResult::STATUS_CREDITED)->count();

        $cutoffStatus = match (true) {
            $todayCredited > 0 && $todayFailed === 0 => 'done',
            $todayFailed > 0 => 'failed',
            default => 'pending',
        };

        $pendingPayoutPaise = (int) WalletLedgerEntry::selectRaw('SUM(amount_paise) as total')->value('total');

        $weekStart = Carbon::now()->startOfWeek(Carbon::TUESDAY);
        $gsbThisWeekPaise = (int) WalletLedgerEntry::where('type', 'gsb_credit')
            ->where('created_at', '>=', $weekStart)
            ->sum('amount_paise');

        // Reversals are stored as negative amounts; abs() gives the display value.
        $gsbReversalsThisWeekPaise = abs((int) WalletLedgerEntry::where('type', 'reversal')
            ->where('created_at', '>=', $weekStart)
            ->sum('amount_paise'));

        $failedCutoffs = $gsbOn
            ? GsbCutoffResult::with('distributor')
                ->where('cutoff_date', $today)
                ->where('status', GsbCutoffResult::STATUS_FAILED)
                ->limit(20)
                ->get()
            : collect();

        $cutoffTable = $gsbOn
            ? GsbCutoffResult::with('distributor.user')
                ->where('cutoff_date', $today)
                ->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'credited' THEN 1 WHEN 'no_match' THEN 2 WHEN 'below_600bv' THEN 3 WHEN 'frozen' THEN 4 ELSE 5 END")
                ->paginate(50)
            : null;

        return view('admin.compensation.overview', compact(
            'gsbOn', 'cutoffStatus', 'todayFailed', 'pendingPayoutPaise',
            'gsbThisWeekPaise', 'gsbReversalsThisWeekPaise',
            'failedCutoffs', 'cutoffTable', 'today',
        ));
    }
}
