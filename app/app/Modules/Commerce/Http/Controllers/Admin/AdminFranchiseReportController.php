<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Modules\Commerce\Models\Franchise;
use App\Modules\Compensation\Models\FranchiseCommissionResult;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\FranchiseCommissionService;
use App\Modules\Shared\Features\FranchiseFeature;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

/**
 * How each franchise's commission for a month was arrived at.
 *
 * Shows the run's own recorded figures where a run has happened, and the live
 * fulfilment numbers where it has not — so an operator can see what a month is
 * shaping up to pay before the engine has touched it, without the page ever
 * implying the money is committed.
 */
final class AdminFranchiseReportController extends Controller
{
    public function __construct(
        private readonly FranchiseCommissionService $commissions,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    public function index(Request $request): View
    {
        $this->guardFeature();

        $month = $this->resolveMonth($request);
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $results = FranchiseCommissionResult::with('franchise')
            // Date-part comparison: `month_start` carries a `date` cast, so an
            // equality match against a Y-m-d string misses on SQLite.
            ->whereDate('month_start', $monthStart->toDateString())
            ->get()
            ->keyBy('franchise_id');

        $rows = [];

        foreach (Franchise::with('operator.user')->orderBy('code')->get() as $franchise) {
            $result = $results->get($franchise->id);

            if ($result !== null) {
                $rows[] = [
                    'franchise' => $franchise,
                    'order_count' => $result->order_count,
                    'base_paise' => $result->base_paise,
                    'rate_bp' => $result->rate_bp,
                    'gross_paise' => $result->gross_paise,
                    'state' => $result->status,
                ];

                continue;
            }

            // No run yet — show what the month currently holds, clearly marked
            // as a projection rather than a credit.
            $fulfilment = $this->commissions->fulfilmentForMonth($franchise->id, $monthStart, $monthEnd);
            $rateBp = $franchise->commission_rate_bp ?? $this->plan->franchiseRateBp();

            $rows[] = [
                'franchise' => $franchise,
                'order_count' => $fulfilment['order_count'],
                'base_paise' => $fulfilment['base_paise'],
                'rate_bp' => $rateBp,
                'gross_paise' => (int) floor($fulfilment['base_paise'] * $rateBp / 10_000),
                'state' => 'not_run',
            ];
        }

        return view('admin.commerce.franchises.report', [
            'month' => $monthStart,
            'rows' => $rows,
            'planRateBp' => $this->plan->franchiseRateBp(),
            'totalGrossPaise' => array_sum(array_map(
                static fn (array $row): int => $row['state'] === 'credited' ? $row['gross_paise'] : 0,
                $rows
            )),
        ]);
    }

    /** Zero-trace gating — see AdminFranchiseController::guardFeature(). */
    private function guardFeature(): void
    {
        abort_unless(Feature::for(null)->active(FranchiseFeature::class), 404);
    }

    private function resolveMonth(Request $request): Carbon
    {
        $raw = (string) $request->query('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            $parsed = Carbon::createFromFormat('Y-m-d', $raw.'-01');

            if ($parsed !== null) {
                return $parsed->startOfMonth();
            }
        }

        return Carbon::now('Asia/Kolkata')->subMonth()->startOfMonth();
    }
}
