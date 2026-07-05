<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\GenosBvLedgerService;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class AdminDistributorCompController extends Controller
{
    public function __construct(
        private readonly BvLedgerService $bvLedger,
        private readonly PersonalBvTitleService $titleService,
        private readonly WalletService $wallet,
        private readonly CompensationPlanSettingsService $plan,
        private readonly GenosBvLedgerService $genosLedger,
    ) {}

    public function show(Distributor $distributor, Request $request): View
    {
        $request->validate([
            'tab' => ['nullable', 'in:gsb,genos-ledger,mb,bv-log,wallet,payouts,audit,repurchase'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'in:no_match,calculated,credited,failed,frozen,below_600bv'],
        ]);

        $tab = $request->query('tab', 'gsb');
        $from = $request->query('from') ? Carbon::parse((string) $request->query('from')) : null;
        $to = $request->query('to') ? Carbon::parse((string) $request->query('to')) : null;

        $distributor->loadMissing('user');
        $personalBvPaise = $this->bvLedger->totalPersonalBvPaise($distributor->id);
        $title = $this->titleService->forBvPaise($personalBvPaise);
        $todayBv = GroupBvDaily::where('distributor_id', $distributor->id)
            ->where('date', today()->toDateString())->first();

        // Below the minimum personal BV the cut-off discards group BV with
        // status BELOW_600BV; the raw accumulator stays visible for debugging
        // but the view flags it as not credited.
        $gsbMinBvPaise = $this->plan->gsbMinBvPaise();
        $genosBvEligible = $personalBvPaise >= $gsbMinBvPaise;
        $cf = GsbCarryforward::where('distributor_id', $distributor->id)->first();
        $walletBalance = $this->wallet->balancePaise($distributor->id);

        $failedToday = GsbCutoffResult::where('distributor_id', $distributor->id)
            ->where('status', GsbCutoffResult::STATUS_FAILED)
            ->whereDate('cutoff_date', today())
            ->first();

        $tabData = match ($tab) {
            'gsb' => [
                'rows' => GsbCutoffResult::where('distributor_id', $distributor->id)
                    ->when($from, fn ($b) => $b->where('cutoff_date', '>=', $from->toDateString()))
                    ->when($to, fn ($b) => $b->where('cutoff_date', '<=', $to->toDateString()))
                    ->when($request->status, fn ($b) => $b->where('status', $request->status))
                    ->orderByDesc('cutoff_date')->paginate(30)->withQueryString(),
            ],
            'genos-ledger' => [
                'days' => $this->genosLedger->paginateDays($distributor->id, $from, $to)->withQueryString(),
            ],
            'mb' => [
                'rows' => MentorshipBonusResult::where('sponsor_id', $distributor->id)
                    ->with('sponsee')
                    ->when($from, fn ($b) => $b->where('cutoff_date', '>=', $from->toDateString()))
                    ->when($to, fn ($b) => $b->where('cutoff_date', '<=', $to->toDateString()))
                    ->orderByDesc('cutoff_date')->paginate(30)->withQueryString(),
            ],
            'bv-log' => [
                'rows' => BvLedgerEntry::query()
                    ->forDistributor($distributor->id)
                    ->dateRange($from, $to)
                    ->with('order')
                    ->orderByDesc('effective_at')->paginate(30)->withQueryString(),
            ],
            'wallet' => [
                'ledger' => $this->wallet->ledgerWithRunningBalance($distributor->id),
            ],
            'payouts' => [
                'rows' => PayoutLineItem::where('distributor_id', $distributor->id)
                    ->with('payoutBatch')
                    ->orderByDesc('created_at')->paginate(20)->withQueryString(),
            ],
            'audit' => [
                'auditRows' => $this->fetchAuditRows($distributor->id),
            ],
            'repurchase' => [
                // Read-only: the daily repurchase:evaluate command maintains these
                // rows; the admin view never mutates state on a GET.
                'rows' => RepurchaseCycle::where('distributor_id', $distributor->id)
                    ->orderByDesc('cycle_start_date')->paginate(20)->withQueryString(),
            ],
            default => [],
        };

        return view('admin.compensation.distributors.show', array_merge([
            'distributor' => $distributor,
            'personalBvPaise' => $personalBvPaise,
            'title' => $title,
            'todayBv' => $todayBv,
            'genosBvEligible' => $genosBvEligible,
            'gsbMinBvPaise' => $gsbMinBvPaise,
            'cf' => $cf,
            'walletBalance' => $walletBalance,
            'failedToday' => $failedToday,
            'tab' => $tab,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'status' => $request->query('status'),
        ], $tabData));
    }

    private function fetchAuditRows(int $distributorId): LengthAwarePaginator|Collection
    {
        // AuditLog may not exist in this phase — graceful degradation.
        if (! class_exists(AuditLog::class)) {
            return collect();
        }

        return AuditLog::where('subject_type', 'distributor')
            ->where('subject_id', $distributorId)
            ->where('action', 'like', 'compensation.%')
            ->orderByDesc('created_at')->paginate(20)->withQueryString();
    }
}
