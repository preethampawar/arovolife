<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AdminManualControlsController extends Controller
{
    public function __construct(
        private readonly GsbCutoffService $cutoff,
        private readonly WalletService $wallet,
    ) {}

    public function index(Request $request): View
    {
        $adn = $request->query('adn');
        $action = $request->query('action');
        $date = $request->query('date', Carbon::today()->toDateString());

        $distributor = $adn ? Distributor::where('adn', $adn)->first() : null;

        $recentActions = AuditLog::where('action', 'like', 'compensation.%')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('admin.compensation.manual-controls.index', compact(
            'distributor', 'adn', 'action', 'date', 'recentActions',
        ));
    }

    public function retryCutoff(Request $request): RedirectResponse
    {
        $request->validate([
            'adn' => ['required', 'string'],
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $date = Carbon::parse((string) $request->input('date'));
        $reason = $request->input('reason');
        $ip = $request->ip();

        $result = DB::transaction(function () use ($distributor, $date, $reason, $ip) {
            $before = GsbCutoffResult::where('distributor_id', $distributor->id)
                ->where('cutoff_date', $date->toDateString())
                ->where('status', GsbCutoffResult::STATUS_FAILED)
                ->first();

            $before?->delete();

            $result = $this->cutoff->runForDistributor($distributor->id, $date);

            AuditLog::create([
                'actor_id' => auth()->id(),
                'action' => 'compensation.cutoff.manual_retry',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'details' => [
                    'adn' => $distributor->adn,
                    'date' => $date->toDateString(),
                    'result_status' => $result->status,
                    'net_gsb_paise' => $result->net_gsb_paise,
                    'reason' => $reason,
                ],
                'ip' => $ip,
            ]);

            return $result;
        });

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', "Cut-off retry for {$distributor->adn} on {$date->format('d M')} completed — status: {$result->status}.");
    }

    public function freezeGsb(Request $request): RedirectResponse
    {
        $request->validate([
            'adn' => ['required', 'string'],
            'freeze' => ['required', 'in:freeze,unfreeze'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $freeze = $request->input('freeze') === 'freeze';

        $distributor->update(['gsb_frozen_at' => $freeze ? now() : null]);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => $freeze ? 'compensation.gsb.frozen' : 'compensation.gsb.unfrozen',
            'subject_type' => 'distributor',
            'subject_id' => $distributor->id,
            'details' => ['adn' => $distributor->adn, 'reason' => $request->input('reason')],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', ($freeze ? 'GSB frozen' : 'GSB unfrozen')." for {$distributor->adn}.");
    }

    public function reverseCredit(Request $request): RedirectResponse
    {
        $request->validate([
            'adn' => ['required', 'string'],
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $reason = $request->input('reason');
        $ip = $request->ip();

        $amountReversed = DB::transaction(function () use ($distributor, $request, $reason, $ip) {
            $result = GsbCutoffResult::where('distributor_id', $distributor->id)
                ->where('cutoff_date', Carbon::parse((string) $request->input('date'))->toDateString())
                ->where('status', GsbCutoffResult::STATUS_CREDITED)
                ->firstOrFail();

            $before = $this->wallet->balancePaise($distributor->id);

            $this->wallet->debit(
                distributorId: $distributor->id,
                amountPaise: $result->net_gsb_paise,
                type: 'reversal',
                referenceId: $result->id,
                referenceType: 'gsb_cutoff_result',
                memo: 'Admin reversal — '.$reason,
            );

            $result->update(['status' => 'reversed']);

            AuditLog::create([
                'actor_id' => auth()->id(),
                'action' => 'compensation.gsb.reversed',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'details' => [
                    'adn' => $distributor->adn,
                    'date' => $result->cutoff_date->toDateString(),
                    'amount_paise' => $result->net_gsb_paise,
                    'wallet_before' => $before,
                    'wallet_after' => $this->wallet->balancePaise($distributor->id),
                    'reason' => $reason,
                ],
                'ip' => $ip,
            ]);

            return $result->net_gsb_paise;
        });

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', '₹'.number_format($amountReversed / 100, 2).' GSB reversed for '.$distributor->adn.'.');
    }

    public function recalcCarryForward(Request $request): RedirectResponse
    {
        $request->validate([
            'adn' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'compensation.carryforward.recalculated',
            'subject_type' => 'distributor',
            'subject_id' => $distributor->id,
            'details' => [
                'adn' => $distributor->adn,
                'reason' => $request->input('reason'),
                'note' => 'Full rebuild deferred to Phase 4 implementation',
            ],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', 'Carry-forward recalculation logged — full rebuild available once GSB engine is active.');
    }

    public function manualCredit(Request $request): RedirectResponse
    {
        $request->validate([
            'adn' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $amountPaise = (int) round((float) $request->input('amount') * 100);
        $reason = $request->input('reason');
        $ip = $request->ip();

        DB::transaction(function () use ($distributor, $amountPaise, $reason, $ip): void {
            $before = $this->wallet->balancePaise($distributor->id);

            $this->wallet->credit(
                distributorId: $distributor->id,
                amountPaise: $amountPaise,
                type: 'manual_credit',
                memo: 'Admin: '.$reason,
            );

            AuditLog::create([
                'actor_id' => auth()->id(),
                'action' => 'compensation.gsb.manual_credit',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'details' => [
                    'adn' => $distributor->adn,
                    'amount_paise' => $amountPaise,
                    'wallet_before' => $before,
                    'wallet_after' => $this->wallet->balancePaise($distributor->id),
                    'reason' => $reason,
                ],
                'ip' => $ip,
            ]);
        });

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', '₹'.number_format($amountPaise / 100, 2).' manual credit added for '.$distributor->adn.'.');
    }

    public function forcePayout(Request $request): RedirectResponse
    {
        $request->validate([
            'adn' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'compensation.payout.force_triggered',
            'subject_type' => 'distributor',
            'subject_id' => $distributor->id,
            'details' => ['adn' => $distributor->adn, 'reason' => $request->input('reason')],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', 'Force payout logged for '.$distributor->adn.' — batch will run at next scheduled time.');
    }
}
