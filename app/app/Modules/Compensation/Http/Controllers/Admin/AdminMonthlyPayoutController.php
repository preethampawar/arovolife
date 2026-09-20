<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Http\Controllers\Admin\Concerns\HandlesPayoutBatchActions;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\IndianNumber as Number;
use App\Modules\Shared\Support\ListFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminMonthlyPayoutController extends Controller
{
    use HandlesPayoutBatchActions;

    protected function payoutRouteName(string $action): string
    {
        return 'admin.compensation.monthly-payouts.'.$action;
    }

    public function index(Request $request, CompensationPlanSettingsService $plan): View
    {
        $filters = $this->payoutBatchFilters($request);

        $batches = $filters->apply($this->payoutBatchQuery())
            ->orderByDesc('batch_date')
            ->paginate(20)
            ->withQueryString();
        $minPayout = Number::format($plan->minPayoutPaise() / 100, 0);

        return view('admin.compensation.monthly-payouts.index', compact('batches', 'minPayout', 'filters'));
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->exportBatchList($request, 'monthly-payout-batches');
    }

    protected function payoutBatchFilters(Request $request): ListFilters
    {
        return ListFilters::make($request, [
            FilterField::dateRange('batch_date', 'Batch date', dateColumn: 'batch_date'),
            FilterField::select('status', 'Status', [
                PayoutBatch::STATUS_PENDING => 'Pending',
                PayoutBatch::STATUS_PROCESSING => 'Processing',
                PayoutBatch::STATUS_APPROVED => 'Approved',
                PayoutBatch::STATUS_DISPATCHED => 'Dispatched',
                PayoutBatch::STATUS_COMPLETED => 'Completed',
                PayoutBatch::STATUS_PARTIALLY_FAILED => 'Partially failed',
                PayoutBatch::STATUS_FAILED => 'Failed',
            ], column: 'status', placeholder: 'All statuses'),
        ]);
    }

    /**
     * distributor_count is the paying lines only; the held count sits beside it
     * so a batch full of KYC-pending income never reads as empty.
     *
     * @return Builder<PayoutBatch>
     */
    protected function payoutBatchQuery(): Builder
    {
        return PayoutBatch::where('batch_type', PayoutBatch::TYPE_MONTHLY)
            ->withCount(['lineItems as held_count' => fn ($q) => $q->whereIn('status', PayoutLineItem::HELD_STATUSES)]);
    }

    public function show(Request $request, PayoutBatch $batch, PayoutGatewaySettings $settings, PayoutService $payoutService): View
    {
        $lines = $batch->lineItems()->with('distributor.user')->paginate(50)->withQueryString();

        $statusCounts = $batch->lineItems()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.compensation.monthly-payouts.show', [
            'batch' => $batch,
            'lines' => $lines,
            'statusCounts' => $statusCounts,
            'held' => $this->heldTotals($batch),
            'deductions' => $this->deductionTotals($batch),
            'bank' => $this->bankAccountColumn($request, $batch, $lines, $payoutService),
            'isRazorpay' => $settings->isRazorpay(),
            'gatewayReady' => $settings->razorpayReady(),
            'maxRetries' => $settings->maxRetries(),
        ]);
    }
}
