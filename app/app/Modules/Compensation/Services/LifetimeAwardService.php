<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\DTOs\LifetimeAwardPlan;
use App\Modules\Compensation\Services\DTOs\LifetimeAwardSyncOutcome;
use App\Modules\Compensation\Support\OpenMonthGuard;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Lifetime Awards paid in cash (the client, 2026-11-10; user decisions
 * 2026-11-11).
 *
 * A rank's award is a fixed amount — rank_tiers.lifetime_award_budget_paise,
 * frozen on the milestone when it is created — paid once per lifetime. It is
 * credited on the 1st with the Rank Bonus of the month just closed, as one
 * `awards_credit` wallet row referencing its milestone, and the monthly payout
 * on the 8th pays it: TDS at the bonus rate only, no admin charge, no
 * repurchase deduction, outside the ₹1 crore cap ({@see PayoutService}).
 *
 * Who is awarded, for month M:
 *  - only a partner on M's payable Rank Bonus roster — the same conditions as
 *    that month's Rank Bonus (Q2): not held by the qualifying-again condition,
 *    not an AO-GO grant, not a late qualifier after the freeze;
 *  - only for M's HIGHEST roster rank r, and only if r is above every rank the
 *    partner holds a milestone for from an earlier month, in any status
 *    (A1/A2). Ranks skipped by a jump are never paid, now or later (D1);
 *  - only the FIRST time r is achieved (user decision 2026-11-11 (a)): an
 *    achieved() qualification of r in any earlier month — including months
 *    before the cash effective month — means it is not paid now;
 *  - never for a month still open (OpenMonthGuard), so an --in-flight run
 *    credits nothing;
 *  - only when M is on or after comp.awards.cash_effective_month (Q3); blank
 *    = nobody. No retrospective catch-up;
 *  - only when the rank's award amount is above ₹0 (Q4) — a ₹0 rank has no
 *    award and writes nothing.
 *
 * Hard rule 2 / DSR Rule 5(1)(c): an award is not a share of a sale. Its
 * link to product sales is the evidence chain recorded on the milestone —
 * the rank_qualifications row it was earned on, and the personal and Genos BV
 * snapshot taken from it — and re-checked before payment
 * ({@see milestonesNoLongerQualified()}). R-128.
 */
final class LifetimeAwardService
{
    public const string WITHDRAWN = 'withdrawn';

    public const string ALREADY_PAID = 'already_paid';

    public const string NOT_WITHDRAWABLE = 'not_withdrawable';

    /** How many roster distributors one existing-milestone / qualification read covers. */
    private const CHUNK = 1_000;

    public function __construct(
        private readonly WalletService $wallet,
        private readonly CompensationPlanSettingsService $plan,
        private readonly BvLedgerService $bvLedger,
    ) {}

    /**
     * The awards month M's close will create. Read-only — the Rank Bonus
     * engine calls it before its credit transaction (R2).
     *
     * @param  array<int, list<int>>  $rosterRanksByDistributor  distributor id → the ranks of their payable M roster rows
     *
     * @throws \RuntimeException when comp.awards.cash_effective_month is not a month
     */
    public function planForMonth(Carbon $month, array $rosterRanksByDistributor): LifetimeAwardPlan
    {
        $monthStart = $month->copy()->startOfMonth();

        // An --in-flight run over a month still open never credits an award:
        // the roster can still change, and an award is paid once for life.
        if (! Feature::for(null)->active(LifetimeAwardsFeature::class)
            || $rosterRanksByDistributor === []
            || OpenMonthGuard::isOpen($monthStart)) {
            return LifetimeAwardPlan::none($monthStart);
        }

        $effective = $this->plan->awardsCashEffectiveMonth();

        if ($effective === null || $monthStart->lt($effective)) {
            return LifetimeAwardPlan::none($monthStart);
        }

        /** @var array<int, int> $highest distributor id → highest payable roster rank */
        $highest = array_map(fn (array $ranks): int => max($ranks), array_filter($rosterRanksByDistributor));

        $awards = [];

        foreach (array_chunk(array_keys($highest), self::CHUNK) as $distributorIds) {
            $candidates = $this->candidatesWithoutBlockingMilestone($monthStart, $distributorIds, $highest);

            if ($candidates === []) {
                continue;
            }

            $qualifications = RankQualification::query()->achieved()
                ->whereIn('distributor_id', array_keys($candidates))
                ->where('month_start', $monthStart->toDateString())
                ->orderBy('id')
                ->get(['id', 'distributor_id', 'rank_number', 'left_genos_bv_paise', 'right_genos_bv_paise'])
                ->filter(fn (RankQualification $row): bool => $candidates[(int) $row->distributor_id] === (int) $row->rank_number)
                ->unique('distributor_id')
                ->keyBy('distributor_id');

            $personalBv = $this->bvLedger->netPersonalBvByPurchaseDate(
                array_values(array_map(intval(...), $qualifications->keys()->all())),
                null,
                $monthStart->copy()->endOfMonth(),
            );

            foreach ($candidates as $distributorId => $rank) {
                $qualification = $qualifications->get($distributorId);
                $amount = $this->plan->lifetimeAwardBudgetPaise($rank);

                // A roster row always stands on a qualification row; one
                // missing (a hand edit) is never awarded on a guess. A ₹0
                // rank has no award (Q4).
                if ($qualification === null || $amount <= 0) {
                    continue;
                }

                $awards[] = [
                    'distributor_id' => $distributorId,
                    'rank_number' => $rank,
                    'amount_paise' => $amount,
                    'rank_qualification_id' => (int) $qualification->id,
                    'personal_bv_paise' => $personalBv[$distributorId] ?? 0,
                    'left_genos_bv_paise' => $qualification->left_genos_bv_paise,
                    'right_genos_bv_paise' => $qualification->right_genos_bv_paise,
                ];
            }
        }

        return new LifetimeAwardPlan($monthStart, $awards);
    }

    /**
     * Write a plan: one milestone and one awards_credit row per award, each
     * with an audit_log row. Runs inside the caller's transaction; re-running
     * a plan already written writes nothing (the milestone unique key, then
     * the ledger's source key).
     */
    public function creditPlan(LifetimeAwardPlan $plan): LifetimeAwardSyncOutcome
    {
        $credited = 0;
        $amount = 0;
        $monthStart = $plan->month->toDateString();

        foreach ($plan->awards as $award) {
            $milestone = LifetimeAwardMilestone::firstOrCreate(
                ['distributor_id' => $award['distributor_id'], 'rank_number' => $award['rank_number'], 'tranche' => 1],
                [
                    'amount_paise' => $award['amount_paise'],
                    'triggered_month' => $monthStart,
                    'achieved_month' => $monthStart,
                    'rank_qualification_id' => $award['rank_qualification_id'],
                    'personal_bv_paise' => $award['personal_bv_paise'],
                    'left_genos_bv_paise' => $award['left_genos_bv_paise'],
                    'right_genos_bv_paise' => $award['right_genos_bv_paise'],
                    'qualification_count' => 1,
                    'award_description' => $this->plan->rankName($award['rank_number']).' Lifetime Award',
                    'status' => LifetimeAwardMilestone::STATUS_PENDING,
                ],
            );

            if (! $milestone->wasRecentlyCreated && $milestone->status !== LifetimeAwardMilestone::STATUS_PENDING) {
                continue;
            }

            $this->wallet->credit(
                distributorId: $milestone->distributor_id,
                amountPaise: $milestone->amount_paise,
                type: 'awards_credit',
                referenceId: $milestone->id,
                referenceType: 'lifetime_award_milestone',
                memo: $milestone->award_description.' '.$monthStart,
                bonusMonth: $plan->month,
            );

            $milestone->update([
                'status' => LifetimeAwardMilestone::STATUS_CREDITED,
                'credited_at' => now(),
            ]);

            AuditLog::create([
                'action' => 'awards.milestone.credited',
                'subject_type' => 'lifetime_award_milestone',
                'subject_id' => $milestone->id,
                'after_hash' => AuditDigests::of($milestone->fresh()),
                'details' => [
                    'distributor_id' => $milestone->distributor_id,
                    'rank_number' => $milestone->rank_number,
                    'achieved_month' => $monthStart,
                    'amount_paise' => $milestone->amount_paise,
                    'rank_qualification_id' => $award['rank_qualification_id'],
                    'personal_bv_paise' => $award['personal_bv_paise'],
                    'left_genos_bv_paise' => $award['left_genos_bv_paise'],
                    'right_genos_bv_paise' => $award['right_genos_bv_paise'],
                ],
            ]);

            $credited++;
            $amount += $milestone->amount_paise;
        }

        return new LifetimeAwardSyncOutcome($credited, $amount);
    }

    /**
     * R7: the milestones among `$milestoneIds` the payout must not pay: any
     * whose status is no longer `credited` (withdrawn — even when its wallet
     * reversal has not landed), and any whose rank qualification no longer
     * stands — no achieved() rank_qualifications row for the same
     * distributor, rank and month. The payout leaves their awards unswept.
     * Matched on (distributor, rank, month), not the advisory row id, which a
     * rebuild rewrites.
     *
     * @param  array<int, int>  $milestoneIds
     * @return list<int>
     */
    public function milestonesNoLongerQualified(array $milestoneIds): array
    {
        if ($milestoneIds === []) {
            return [];
        }

        $failed = [];

        $milestones = LifetimeAwardMilestone::whereIn('id', $milestoneIds)
            ->get(['id', 'distributor_id', 'rank_number', 'status', 'achieved_month', 'triggered_month'])
            ->keyBy('id');

        // An awards_credit whose milestone row is gone is never paid either.
        foreach (array_diff($milestoneIds, $milestones->keys()->map(fn ($id): int => (int) $id)->all()) as $missingId) {
            $failed[] = (int) $missingId;
        }

        foreach ($milestones as $milestone) {
            if ($milestone->status !== LifetimeAwardMilestone::STATUS_CREDITED) {
                $failed[] = (int) $milestone->id;

                continue;
            }

            $month = ($milestone->achieved_month ?? $milestone->triggered_month)->toDateString();

            $stands = RankQualification::query()->achieved()
                ->where('distributor_id', $milestone->distributor_id)
                ->where('rank_number', $milestone->rank_number)
                ->where('month_start', $month)
                ->exists();

            if (! $stands) {
                $failed[] = (int) $milestone->id;
            }
        }

        return $failed;
    }

    /**
     * Withdraw an award before it is paid (Q1, R9): a pending milestone is
     * cancelled; a credited one whose awards_credit row no payout has swept is
     * reversed in the wallet and cancelled. One the payout has swept is
     * already paid and is never withdrawn (no clawback — D6). `$reason` is
     * stored on the milestone, where the partner's Awards page shows it;
     * `$auditReason` (default `$reason`) goes on the audit_log row with the
     * before/after digests — an exit withdrawal shows the partner a fixed
     * text and keeps the admin's free text in the audit row only.
     *
     * @return self::WITHDRAWN|self::ALREADY_PAID|self::NOT_WITHDRAWABLE
     */
    public function withdraw(LifetimeAwardMilestone $milestone, string $reason, ?int $actorId, string $auditAction, ?string $ip = null, ?string $auditReason = null): string
    {
        return DB::transaction(function () use ($milestone, $reason, $actorId, $auditAction, $ip, $auditReason): string {
            /** @var LifetimeAwardMilestone $locked */
            $locked = LifetimeAwardMilestone::whereKey($milestone->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [LifetimeAwardMilestone::STATUS_PENDING, LifetimeAwardMilestone::STATUS_CREDITED], true)) {
                return self::NOT_WITHDRAWABLE;
            }

            // Locked like the payout locks it (PayoutService::processMonthlyDistributor()
            // reads the month's credits FOR UPDATE before its re-check), so a
            // withdrawal and a sweep of the same award serialise: either the
            // sweep saw it first (already paid), or it sees it cancelled.
            $credit = $this->awardCredit($locked->id, lockForUpdate: true);

            if ($credit !== null && $credit->swept_by_payout_batch_id !== null) {
                return self::ALREADY_PAID;
            }

            $before = AuditDigests::of($locked);

            if ($credit !== null) {
                $this->wallet->reverseBonusCredit(
                    distributorId: $locked->distributor_id,
                    netPaise: (int) $credit->amount_paise,
                    repurchaseDeductionPaise: 0,
                    referenceId: $locked->id,
                    referenceType: 'lifetime_award_milestone',
                    bonusMonth: $locked->achieved_month ?? $locked->triggered_month,
                    memo: 'Lifetime Award withdrawn: '.$reason,
                );
            }

            $locked->update([
                'status' => LifetimeAwardMilestone::STATUS_CANCELLED,
                'notes' => $reason,
            ]);

            AuditLog::create([
                'actor_id' => $actorId,
                'action' => $auditAction,
                'subject_type' => 'lifetime_award_milestone',
                'subject_id' => $locked->id,
                'before_hash' => $before,
                'after_hash' => AuditDigests::of($locked->fresh()),
                'details' => [
                    'distributor_id' => $locked->distributor_id,
                    'rank_number' => $locked->rank_number,
                    'amount_paise' => $locked->amount_paise,
                    'wallet_reversed' => $credit !== null,
                    'reason' => $auditReason ?? $reason,
                ],
                'ip' => $ip,
            ]);

            return self::WITHDRAWN;
        });
    }

    /**
     * Withdraw every award of a distributor that is not yet paid — on a
     * cooling-off cancellation or a termination (Q1). Awards already paid are
     * left as they are.
     *
     * @return list<LifetimeAwardMilestone> the awards withdrawn
     */
    public function withdrawUnpaidForDistributor(int $distributorId, string $reason, ?int $actorId, string $auditAction, ?string $auditReason = null): array
    {
        $withdrawn = [];

        $milestones = LifetimeAwardMilestone::query()
            ->where('distributor_id', $distributorId)
            ->whereIn('status', [LifetimeAwardMilestone::STATUS_PENDING, LifetimeAwardMilestone::STATUS_CREDITED])
            ->orderBy('id')
            ->get();

        foreach ($milestones as $milestone) {
            if ($this->withdraw($milestone, $reason, $actorId, $auditAction, auditReason: $auditReason) === self::WITHDRAWN) {
                $withdrawn[] = $milestone;
            }
        }

        return $withdrawn;
    }

    /** The award's awards_credit wallet row, or null when it was never credited. */
    public function awardCredit(int $milestoneId, bool $lockForUpdate = false): ?WalletLedgerEntry
    {
        return WalletLedgerEntry::query()
            ->where('type', 'awards_credit')
            ->where('reference_type', 'lifetime_award_milestone')
            ->where('reference_id', $milestoneId)
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * Of `$distributorIds`, those whose highest roster rank this month may be
     * awarded: the rank not achieved in any earlier month (first time only),
     * no milestone at that rank (any month, any status — once per lifetime),
     * and none at that rank or above from an EARLIER month. A later
     * month's milestone never blocks — a rebuild replays months out of order.
     *
     * @param  list<int>  $distributorIds
     * @param  array<int, int>  $highest  distributor id → highest roster rank
     * @return array<int, int> distributor id → rank to award
     */
    private function candidatesWithoutBlockingMilestone(Carbon $monthStart, array $distributorIds, array $highest): array
    {
        $existing = LifetimeAwardMilestone::query()
            ->whereIn('distributor_id', $distributorIds)
            ->get(['distributor_id', 'rank_number', 'achieved_month', 'triggered_month'])
            ->groupBy('distributor_id');

        // First time only (2026-11-11 (a)): the ranks each partner already
        // achieved in an earlier month, whether or not an award was paid.
        $achievedBefore = RankQualification::query()->achieved()
            ->whereIn('distributor_id', $distributorIds)
            ->where('month_start', '<', $monthStart->toDateString())
            ->distinct()
            ->get(['distributor_id', 'rank_number'])
            ->groupBy('distributor_id')
            ->map(fn ($rows): array => $rows->pluck('rank_number')->map(fn ($rank): int => (int) $rank)->all());

        $candidates = [];

        foreach ($distributorIds as $distributorId) {
            $rank = $highest[$distributorId];

            $blocked = in_array($rank, $achievedBefore->get($distributorId, []), true)
                || $existing->get($distributorId, collect())->contains(
                    fn (LifetimeAwardMilestone $milestone): bool => $milestone->rank_number === $rank
                        || ($milestone->rank_number > $rank && ($milestone->achieved_month ?? $milestone->triggered_month)->lt($monthStart)),
                );

            if (! $blocked) {
                $candidates[$distributorId] = $rank;
            }
        }

        return $candidates;
    }
}
