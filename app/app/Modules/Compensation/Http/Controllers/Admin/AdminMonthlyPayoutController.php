<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

final class AdminMonthlyPayoutController extends Controller
{
    public function index(CompensationPlanSettingsService $plan): View
    {
        // distributor_count is the paying lines only; the held count sits
        // beside it so a batch full of KYC-pending income never reads as empty.
        $batches = PayoutBatch::where('batch_type', PayoutBatch::TYPE_MONTHLY)
            ->withCount(['lineItems as held_count' => fn ($q) => $q->whereIn('status', PayoutLineItem::HELD_STATUSES)])
            ->orderByDesc('batch_date')
            ->paginate(20);
        $minPayout = Number::format($plan->minPayoutPaise() / 100, 0);

        return view('admin.compensation.monthly-payouts.index', compact('batches', 'minPayout'));
    }

    public function show(PayoutBatch $batch, PayoutGatewaySettings $settings): View
    {
        $lines = $batch->lineItems()->with('distributor.user')->paginate(50);

        $statusCounts = $batch->lineItems()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.compensation.monthly-payouts.show', [
            'batch' => $batch,
            'lines' => $lines,
            'statusCounts' => $statusCounts,
            'isRazorpay' => $settings->isRazorpay(),
            'gatewayReady' => $settings->razorpayReady(),
            'maxRetries' => $settings->maxRetries(),
        ]);
    }
}
