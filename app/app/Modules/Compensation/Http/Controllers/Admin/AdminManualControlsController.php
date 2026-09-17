<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Exceptions\CutoffReplayedOutOfOrder;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Services\MentorshipBonusService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

final class AdminManualControlsController extends Controller
{
    public function __construct(
        private readonly GsbCutoffService $cutoff,
        private readonly WalletService $wallet,
        private readonly MentorshipBonusService $mentorship,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

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
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $request->validate([
            'adn' => ['required', 'string'],
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $date = Carbon::parse((string) $request->input('date'));
        $reason = $request->input('reason');
        $ip = $request->ip();

        try {
            $result = DB::transaction(function () use ($distributor, $date, $reason, $ip) {
                // Lock any existing cut-off row for this distributor+date for the
                // whole retry so a concurrent writer (the nightly cut-off, or a
                // second admin on the same row) cannot interleave with it: whichever
                // runs first flips the status, the other then sees it and skips.
                GsbCutoffResult::where('distributor_id', $distributor->id)
                    ->where('cutoff_date', $date->toDateString())
                    ->lockForUpdate()
                    ->get();

                $before = GsbCutoffResult::where('distributor_id', $distributor->id)
                    ->where('cutoff_date', $date->toDateString())
                    ->where('status', GsbCutoffResult::STATUS_FAILED)
                    ->first();

                // The failed row the retry replaces, digested before it goes.
                $beforeState = $before === null ? null : AuditDigests::of($before);

                $before?->delete();

                $result = $this->cutoff->runForDistributor($distributor->id, $date);

                // The Mentorship Bonus rides on the sponsee's credit and is not
                // part of runForDistributor(), so a retry has to drive it too —
                // otherwise the sponsor is silently skipped for that day. It prices
                // against the day's already-frozen MSB pool; MB errors must not
                // roll back a GSB credit that already succeeded.
                $msbOutcome = ['status' => 'not_applicable'];
                if ($result->status === GsbCutoffResult::STATUS_CREDITED
                    && Feature::for(null)->active(MentorshipBonusFeature::class)) {
                    try {
                        $mb = $this->mentorship->processForSponsee($distributor->id, $result);
                        $msbOutcome = $mb === null
                            ? ['status' => 'skipped']   // no sponsor, gate failed, or no frozen pool
                            : [
                                'status' => 'credited',
                                'result_id' => $mb->id,
                                'sponsor_id' => $mb->sponsor_id,
                                'msb_points' => $mb->msb_points,
                                'point_value_paise' => $mb->msb_point_value_paise,
                                'gross_paise' => $mb->mb_gross_paise,
                            ];
                    } catch (\Throwable $e) {
                        $msbOutcome = ['status' => 'failed', 'error' => $e->getMessage()];
                        Log::error('mb.credit.exception', [
                            'sponsee_id' => $distributor->id,
                            'cutoff_date' => $date->toDateString(),
                            'error' => $e->getMessage(),
                            'exception' => get_class($e),
                        ]);
                    }
                }

                AuditLog::create([
                    'actor_id' => auth()->id(),
                    'action' => 'compensation.cutoff.manual_retry',
                    'subject_type' => 'distributor',
                    'subject_id' => $distributor->id,
                    'before_hash' => $beforeState,
                    'after_hash' => AuditDigests::of($result),
                    'details' => [
                        'adn' => $distributor->adn,
                        'date' => $date->toDateString(),
                        'result_status' => $result->status,
                        'net_gsb_paise' => $result->net_gsb_paise,
                        // A retry now drives the Mentorship Bonus too, so the audit
                        // row must say whether the sponsor was paid, skipped or errored.
                        'mentorship_bonus' => $msbOutcome,
                        'reason' => $reason,
                    ],
                    'ip' => $ip,
                ]);

                return $result;
            });
        } catch (CutoffReplayedOutOfOrder $e) {
            // The guard refused the date: a later night already advanced the
            // carry-forward store. Caught by its own type, not as a bare
            // RuntimeException, so the audit action below is true of every row
            // that carries it — any other failure from this call is a different
            // event and must not be filed as a refusal.
            //
            // Two things have to happen that an uncaught throw did not do. The
            // admin has to SEE the message: unhandled, it renders as a blank
            // 500 ("Something went wrong"), which made the runbook's
            // instruction to forward the error impossible to follow. And the
            // attempt has to be recorded — the rollback that undoes the failed
            // row's deletion (correctly, the row must survive a refused retry)
            // would take an audit row with it, so it is written out here.
            AuditLog::create([
                'actor_id' => auth()->id(),
                'action' => 'compensation.cutoff.manual_retry_refused',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                // Null on both sides because nothing changed: the transaction
                // rolled back and the subject is exactly as it was.
                'before_hash' => null,
                'after_hash' => null,
                'details' => [
                    'adn' => $distributor->adn,
                    'date' => $date->toDateString(),
                    'reason' => $reason,
                    'refusal' => $e->getMessage(),
                ],
                'ip' => $ip,
            ]);

            Log::warning('gsb.cutoff.manual_retry_refused', [
                'distributor_id' => $distributor->id,
                'cutoff_date' => $date->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', "Cut-off retry for {$distributor->adn} on {$date->format('d M')} completed — status: {$result->status}.");
    }

    public function freezeGsb(Request $request): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $request->validate([
            'adn' => ['required', 'string'],
            'freeze' => ['required', 'in:freeze,unfreeze'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $freeze = $request->input('freeze') === 'freeze';

        $frozenBefore = $distributor->gsb_frozen_at;
        $distributor->update(['gsb_frozen_at' => $freeze ? now() : null]);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => $freeze ? 'compensation.gsb.frozen' : 'compensation.gsb.unfrozen',
            'subject_type' => 'distributor',
            'subject_id' => $distributor->id,
            'before_hash' => AuditDigests::of(['gsb_frozen_at' => $frozenBefore]),
            'after_hash' => AuditDigests::of(['gsb_frozen_at' => $distributor->gsb_frozen_at]),
            'details' => ['adn' => $distributor->adn, 'reason' => $request->input('reason')],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', ($freeze ? 'GSB frozen' : 'GSB unfrozen')." for {$distributor->adn}.");
    }

    public function reverseCredit(Request $request): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $request->validate([
            'adn' => ['required', 'string'],
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $reason = $request->input('reason');
        $ip = $request->ip();

        $amountReversed = DB::transaction(function () use ($distributor, $request, $reason, $ip) {
            // Locked for the whole reversal so a concurrent submit of the same
            // form cannot pass the status filter twice; reverseBonusCredit() is
            // idempotent on its own, this just keeps the row and the ledger from
            // disagreeing in between.
            $result = GsbCutoffResult::where('distributor_id', $distributor->id)
                ->where('cutoff_date', Carbon::parse((string) $request->input('date'))->toDateString())
                ->where('status', GsbCutoffResult::STATUS_CREDITED)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $this->wallet->balancePaise($distributor->id);
            $repurchaseBefore = $this->wallet->repurchaseWalletBalancePaise($distributor->id);

            // Both wallets in one call: the net comes out of the main wallet and
            // the frozen repurchase deduction comes back out of the repurchase
            // wallet, so a reversed bonus stops failing the wallet-zero gates on
            // Fortune, Growth Booster and the Rank requalification.
            $outcome = $this->wallet->reverseBonusCredit(
                distributorId: $distributor->id,
                netPaise: $result->net_gsb_paise,
                repurchaseDeductionPaise: $result->repurchase_deduction_paise,
                referenceId: $result->id,
                referenceType: 'gsb_cutoff_result',
                bonusMonth: $result->cutoff_date->copy()->startOfMonth(),
                memo: 'Admin reversal — '.$reason,
            );

            $result->update(['status' => GsbCutoffResult::STATUS_REVERSED]);

            AuditLog::create([
                'actor_id' => auth()->id(),
                'action' => 'compensation.gsb.reversed',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'before_hash' => AuditDigests::of([
                    'status' => GsbCutoffResult::STATUS_CREDITED,
                    'wallet_paise' => $before,
                    'repurchase_wallet_paise' => $repurchaseBefore,
                ]),
                'after_hash' => AuditDigests::of([
                    'status' => $result->status,
                    'wallet_paise' => $this->wallet->balancePaise($distributor->id),
                    'repurchase_wallet_paise' => $this->wallet->repurchaseWalletBalancePaise($distributor->id),
                ]),
                'details' => [
                    'adn' => $distributor->adn,
                    'date' => $result->cutoff_date->toDateString(),
                    'amount_paise' => $result->net_gsb_paise,
                    'wallet_before' => $before,
                    'wallet_after' => $this->wallet->balancePaise($distributor->id),
                    'repurchase_deduction_paise' => $result->repurchase_deduction_paise,
                    'repurchase_reversed_paise' => $outcome !== null ? $outcome->repurchaseReversedPaise : 0,
                    // What the distributor had already spent and is not being
                    // clawed back — the company's loss on this reversal.
                    'repurchase_shortfall_paise' => $outcome !== null ? $outcome->repurchaseShortfallPaise : 0,
                    'repurchase_wallet_before' => $repurchaseBefore,
                    'repurchase_wallet_after' => $this->wallet->repurchaseWalletBalancePaise($distributor->id),
                    'already_reversed' => $outcome === null,
                    'reason' => $reason,
                ],
                'ip' => $ip,
            ]);

            return $outcome !== null ? $outcome->netReversedPaise : 0;
        });

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', '₹'.Number::format($amountReversed / 100, 2).' GSB reversed for '.$distributor->adn.'.');
    }
}
