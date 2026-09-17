<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Exceptions\CutoffReplayedOutOfOrder;
use App\Modules\Compensation\Exceptions\ReversalCreditAlreadyPaid;
use App\Modules\Compensation\Exceptions\ReversalRequestStale;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbReversalRequest;
use App\Modules\Compensation\Models\WalletLedgerEntry;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

final class AdminManualControlsController extends Controller
{
    use Concerns\RefusesProjectedFigures;

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

        // Reversals now wait for a second admin (R-92), so the page has to show
        // what is waiting — a request nobody can see is a request nobody signs.
        $pendingReversals = GsbReversalRequest::with(['distributor', 'requester'])
            ->where('status', GsbReversalRequest::STATUS_PENDING)
            ->orderBy('created_at')
            ->get();

        return view('admin.compensation.manual-controls.index', compact(
            'distributor', 'adn', 'action', 'date', 'recentActions', 'pendingReversals',
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

    /**
     * The MAKER half of maker-checker on a GSB reversal (R-92).
     *
     * This no longer reverses anything. Reversing a credit permanently removes
     * commission earned against a product sale — `computeForDistributor()`
     * short-circuits a `reversed` row as already settled, so neither Retry nor
     * any other control can put it back — and until 2026-09-17 one holder of
     * `compliance.discipline` could do it in a single click. It now raises a
     * pending request that a second admin has to sign off.
     */
    public function reverseCredit(Request $request): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $request->validate([
            'adn' => ['required', 'string'],
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $date = Carbon::parse((string) $request->input('date'));
        $reason = (string) $request->input('reason');
        $ip = $request->ip();

        /** @var array{request: GsbReversalRequest, duplicate: bool} $outcome */
        $outcome = DB::transaction(function () use ($distributor, $date, $reason, $ip): array {
            // Only a credited day can be reversed, and the row is locked for the
            // whole check so two admins filing the same request cannot both pass
            // the "no pending request" test below.
            $result = GsbCutoffResult::where('distributor_id', $distributor->id)
                ->where('cutoff_date', $date->toDateString())
                ->where('status', GsbCutoffResult::STATUS_CREDITED)
                ->lockForUpdate()
                ->firstOrFail();

            $pending = GsbReversalRequest::where('gsb_cutoff_result_id', $result->id)
                ->where('status', GsbReversalRequest::STATUS_PENDING)
                ->first();

            if ($pending !== null) {
                return ['request' => $pending, 'duplicate' => true];
            }

            $reversalRequest = GsbReversalRequest::create([
                'gsb_cutoff_result_id' => $result->id,
                'distributor_id' => $distributor->id,
                'cutoff_date' => $result->cutoff_date->toDateString(),
                // Snapshotted so the approver signs off the figure the requester
                // saw; a drift against the live row is checked at approval.
                'net_gsb_paise' => $result->net_gsb_paise,
                'repurchase_deduction_paise' => $result->repurchase_deduction_paise,
                'status' => GsbReversalRequest::STATUS_PENDING,
                'reason' => $reason,
                'requested_by' => auth()->id(),
            ]);

            AuditLog::create([
                'actor_id' => auth()->id(),
                'action' => 'compensation.gsb.reversal_requested',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                // A request moves no money and changes nothing about the
                // subject; the matching digests say so.
                'before_hash' => AuditDigests::of($result),
                'after_hash' => AuditDigests::of($result),
                'details' => [
                    'adn' => $distributor->adn,
                    'date' => $result->cutoff_date->toDateString(),
                    'reversal_request_id' => $reversalRequest->id,
                    'amount_paise' => $result->net_gsb_paise,
                    'repurchase_deduction_paise' => $result->repurchase_deduction_paise,
                    'reason' => $reason,
                ],
                'ip' => $ip,
            ]);

            return ['request' => $reversalRequest, 'duplicate' => false];
        });

        if ($outcome['duplicate']) {
            return back()->withInput()->with('error',
                'A reversal request for '.$distributor->adn.' on '.$date->format('d M Y')
                .' is already waiting for approval. It has to be approved or rejected before another can be raised.');
        }

        return redirect()->route('admin.compensation.manual-controls.index')
            ->with('status', 'Reversal requested for '.$distributor->adn.' on '.$date->format('d M Y')
                .' — ₹'.Number::format($outcome['request']->net_gsb_paise / 100, 2)
                .'. It moves no money until a second admin approves it.');
    }

    /**
     * The CHECKER half (R-92). Two locks, the same shape as the payout-batch
     * approval in {@see HandlesPayoutBatchActions::approve()}: the route
     * restricts this to `compensation.reversal.approve`, which the scoped
     * admin-compliance role that raises the request does not hold — and on top
     * of that, whoever raised a request may not sign it off, checked here
     * against `requested_by`.
     */
    public function approveReversal(Request $request, GsbReversalRequest $reversalRequest): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $request->validate(['decision_note' => ['nullable', 'string', 'max:500']]);

        // The same refusal the payout approval makes: a projection prices
        // bonuses nobody has earned yet, and a reversal signed against one
        // debits a wallet for a figure that was never real.
        if (($projected = $this->projectedFiguresRefusal(
            'The credit this reversal would take back was computed on a clock that has not arrived, so it cannot be signed off as a real debit.'
        )) !== null) {
            return back()->with('error', $projected);
        }

        if ($reversalRequest->status !== GsbReversalRequest::STATUS_PENDING) {
            return back()->with('error', 'That reversal request has already been decided.');
        }

        $actorId = (int) $request->user()->id;
        $distributor = $reversalRequest->distributor;

        if ($reversalRequest->requested_by !== null && (int) $reversalRequest->requested_by === $actorId) {
            AuditLog::create([
                'actor_id' => $actorId,
                'action' => 'compensation.gsb.reversal_self_approval_refused',
                'subject_type' => 'distributor',
                'subject_id' => $reversalRequest->distributor_id,
                // A refusal moves nothing.
                'before_hash' => AuditDigests::of($reversalRequest),
                'after_hash' => AuditDigests::of($reversalRequest),
                'details' => [
                    'adn' => $distributor->adn,
                    'date' => $reversalRequest->cutoff_date->toDateString(),
                    'reversal_request_id' => $reversalRequest->id,
                    'amount_paise' => $reversalRequest->net_gsb_paise,
                ],
                'ip' => $request->ip(),
            ]);

            return back()->with('error', 'You raised this reversal, so you cannot also approve it. Separation of duties requires a second person to sign off money coming back out of a distributor\'s wallet.');
        }

        $note = $request->input('decision_note');
        $ip = $request->ip();

        try {
            $amountReversed = DB::transaction(function () use ($reversalRequest, $actorId, $note, $ip): int {
                $pending = GsbReversalRequest::where('id', $reversalRequest->id)
                    ->where('status', GsbReversalRequest::STATUS_PENDING)
                    ->lockForUpdate()
                    ->firstOrFail();

                $result = GsbCutoffResult::where('id', $pending->gsb_cutoff_result_id)
                    ->where('status', GsbCutoffResult::STATUS_CREDITED)
                    ->lockForUpdate()
                    ->firstOrFail();

                // A debit does not recall a bank transfer. If the weekly batch
                // has already swept this credit, reversing it would leave the
                // wallet short by money that is gone and net the difference
                // against future earnings — a clawback the published plan does
                // not provide for. Checked before the figure comparison because
                // it is the graver of the two refusals.
                $sweptBatchId = WalletLedgerEntry::where('type', 'gsb_credit')
                    ->where('reference_type', 'gsb_cutoff_result')
                    ->where('reference_id', $result->id)
                    ->value('swept_by_payout_batch_id');

                if ($sweptBatchId !== null) {
                    throw ReversalCreditAlreadyPaid::forBatch($pending->id, (int) $sweptBatchId);
                }

                // The approver signs off a figure. If the row has moved since the
                // request was raised, that figure is no longer what they were
                // shown — refuse rather than reverse a different amount.
                if ($result->net_gsb_paise !== $pending->net_gsb_paise
                    || $result->repurchase_deduction_paise !== $pending->repurchase_deduction_paise) {
                    throw new ReversalRequestStale($pending->id);
                }

                $distributor = $result->distributor;
                $before = $this->wallet->balancePaise($result->distributor_id);
                $repurchaseBefore = $this->wallet->repurchaseWalletBalancePaise($result->distributor_id);

                // Both wallets in one call: the net comes out of the main wallet and
                // the frozen repurchase deduction comes back out of the repurchase
                // wallet, so a reversed bonus stops failing the wallet-zero gates on
                // Fortune, Growth Booster and the Rank requalification.
                $outcome = $this->wallet->reverseBonusCredit(
                    distributorId: $result->distributor_id,
                    netPaise: $result->net_gsb_paise,
                    repurchaseDeductionPaise: $result->repurchase_deduction_paise,
                    referenceId: $result->id,
                    referenceType: 'gsb_cutoff_result',
                    bonusMonth: $result->cutoff_date->copy()->startOfMonth(),
                    memo: 'Admin reversal — '.$pending->reason,
                );

                $result->update(['status' => GsbCutoffResult::STATUS_REVERSED]);

                $pending->update([
                    'status' => GsbReversalRequest::STATUS_APPROVED,
                    'decided_by' => $actorId,
                    'decided_at' => now(),
                    'decision_note' => $note,
                ]);

                AuditLog::create([
                    'actor_id' => $actorId,
                    'action' => 'compensation.gsb.reversed',
                    'subject_type' => 'distributor',
                    'subject_id' => $result->distributor_id,
                    'before_hash' => AuditDigests::of([
                        'status' => GsbCutoffResult::STATUS_CREDITED,
                        'wallet_paise' => $before,
                        'repurchase_wallet_paise' => $repurchaseBefore,
                    ]),
                    'after_hash' => AuditDigests::of([
                        'status' => $result->status,
                        'wallet_paise' => $this->wallet->balancePaise($result->distributor_id),
                        'repurchase_wallet_paise' => $this->wallet->repurchaseWalletBalancePaise($result->distributor_id),
                    ]),
                    'details' => [
                        'adn' => $distributor->adn,
                        'date' => $result->cutoff_date->toDateString(),
                        'amount_paise' => $result->net_gsb_paise,
                        'wallet_before' => $before,
                        'wallet_after' => $this->wallet->balancePaise($result->distributor_id),
                        'repurchase_deduction_paise' => $result->repurchase_deduction_paise,
                        'repurchase_reversed_paise' => $outcome !== null ? $outcome->repurchaseReversedPaise : 0,
                        // What the distributor had already spent and is not being
                        // clawed back — the company's loss on this reversal.
                        'repurchase_shortfall_paise' => $outcome !== null ? $outcome->repurchaseShortfallPaise : 0,
                        'repurchase_wallet_before' => $repurchaseBefore,
                        'repurchase_wallet_after' => $this->wallet->repurchaseWalletBalancePaise($result->distributor_id),
                        'already_reversed' => $outcome === null,
                        // Maker and checker on the row that moved the money, so the
                        // audit trail answers "who signed this off" without a join.
                        'reversal_request_id' => $pending->id,
                        'requested_by' => $pending->requested_by,
                        'approved_by' => $actorId,
                        'reason' => $pending->reason,
                        'decision_note' => $note,
                    ],
                    'ip' => $ip,
                ]);

                return $outcome !== null ? $outcome->netReversedPaise : 0;
            });
        } catch (ReversalCreditAlreadyPaid|ReversalRequestStale $e) {
            Log::warning('gsb.reversal.approval_refused', [
                'reversal_request_id' => $reversalRequest->id,
                'distributor_id' => $reversalRequest->distributor_id,
                'reason' => class_basename($e),
            ]);

            return back()->with('error', $e->getMessage());
        } catch (ModelNotFoundException) {
            // The request or its credit stopped being eligible between the page
            // being drawn and the button being pressed — another approver got
            // there first, or the platform team ran the §14c rebuild, which
            // deletes result rows by design. Same answer as a decided request,
            // not a raw 404.
            return back()->with('error', 'That reversal request can no longer be approved: the credit it names is no longer a settled, unreversed GSB day. Reload the page to see its current state.');
        }

        return redirect()->route('admin.compensation.distributors.show', $reversalRequest->distributor_id)
            ->with('status', '₹'.Number::format($amountReversed / 100, 2).' GSB reversed for '.$distributor->adn.'.');
    }

    /**
     * Rejecting moves no money, so it sits behind the maker's own permission —
     * which lets the requester withdraw their own request, and lets an approver
     * (who holds every scoped permission) turn one down.
     */
    public function rejectReversal(Request $request, GsbReversalRequest $reversalRequest): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $request->validate(['decision_note' => ['required', 'string', 'min:10', 'max:500']]);

        if ($reversalRequest->status !== GsbReversalRequest::STATUS_PENDING) {
            return back()->with('error', 'That reversal request has already been decided.');
        }

        $note = (string) $request->input('decision_note');
        $before = AuditDigests::of($reversalRequest);

        $reversalRequest->update([
            'status' => GsbReversalRequest::STATUS_REJECTED,
            'decided_by' => (int) $request->user()->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        AuditLog::create([
            'actor_id' => (int) $request->user()->id,
            'action' => 'compensation.gsb.reversal_rejected',
            'subject_type' => 'distributor',
            'subject_id' => $reversalRequest->distributor_id,
            'before_hash' => $before,
            'after_hash' => AuditDigests::of($reversalRequest->fresh()),
            'details' => [
                'adn' => $reversalRequest->distributor->adn,
                'date' => $reversalRequest->cutoff_date->toDateString(),
                'reversal_request_id' => $reversalRequest->id,
                'amount_paise' => $reversalRequest->net_gsb_paise,
                'reason' => $reversalRequest->reason,
                'decision_note' => $note,
            ],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.compensation.manual-controls.index')
            ->with('status', 'Reversal request rejected. The credit stands and nothing moved.');
    }
}
