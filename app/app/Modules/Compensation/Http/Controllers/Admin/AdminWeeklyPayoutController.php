<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\PayoutBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

final class AdminWeeklyPayoutController extends Controller
{
    public function index(): View
    {
        $batches = PayoutBatch::orderByDesc('batch_date')->paginate(20);

        return view('admin.compensation.weekly-payouts.index', compact('batches'));
    }

    public function show(PayoutBatch $batch): View
    {
        $lines = $batch->lineItems()->with('distributor.user')->paginate(50);

        return view('admin.compensation.weekly-payouts.show', compact('batch', 'lines'));
    }
}
