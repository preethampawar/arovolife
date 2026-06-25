<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\PayoutService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function approve(Request $request, PayoutBatch $batch, PayoutService $payoutService): RedirectResponse
    {
        if ($batch->status !== PayoutBatch::STATUS_PENDING) {
            return back()->with('error', 'Batch cannot be approved in its current state.');
        }

        $payoutService->approve($batch, (int) $request->user()->id);

        return redirect()
            ->route('admin.compensation.weekly-payouts.show', $batch)
            ->with('success', 'Batch approved — all line items marked as transferred.');
    }

    public function exportNeft(PayoutBatch $batch): StreamedResponse
    {
        $lines = $batch->lineItems()
            ->with('distributor.user')
            ->whereIn('status', [
                \App\Modules\Compensation\Models\PayoutLineItem::STATUS_PENDING,
                \App\Modules\Compensation\Models\PayoutLineItem::STATUS_TRANSFERRED,
            ])
            ->orderBy('id')
            ->get();

        $filename = 'neft-batch-'.$batch->batch_date->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($lines): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Line#', 'ADN', 'Full Name', 'Bank Last 4', 'Net Amount (₹)', 'UTR', 'Status',
            ]);
            foreach ($lines as $i => $line) {
                fputcsv($out, [
                    $i + 1,
                    $line->distributor->adn ?? '',
                    $line->distributor->user?->full_name ?? '',
                    $line->bank_account_last4 ?? '',
                    number_format($line->net_transferred_paise / 100, 2),
                    $line->utr_number ?? '',
                    $line->status,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
