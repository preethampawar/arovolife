<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\DTOs\InactivityAssessment;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Compliance\Notifications\InactivityTerminationNoticeNotification;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * T&C §21 — termination for twelve continuous months without a sale.
 *
 * Two stages, never one:
 *
 *  1. **Notice.** A distributor who crosses twelve months without a sale gets
 *     a seven-day written notice. Nothing is frozen and nothing is lost — a
 *     single sale inside the window clears the notice completely.
 *  2. **Termination.** If the notice expires and there is still no sale, the
 *     account is terminated and the re-registration clock starts.
 *
 * The gap between the two stages is the whole point. §21 promises notice
 * *before* termination, so a sweep that terminated directly would breach the
 * agreement even though it reached the same end state.
 *
 * The clock runs from the later of the effective date and the last sale, so a
 * distributor who never sells is measured from the day they joined.
 */
final class InactivityTerminationService
{
    /**
     * Order states that count as a sale. A cancelled or refunded order is not
     * a sale — the money went back — so it must not reset the clock.
     */
    private const SALE_STATUSES = ['paid', 'ready_to_ship', 'shipped', 'delivered', 'confirmed'];

    public function __construct(private readonly ComplianceTerminationSettings $settings) {}

    /**
     * Where a single distributor stands against §21 today.
     */
    public function assess(Distributor $distributor, ?Carbon $asOf = null): InactivityAssessment
    {
        $asOf ??= Carbon::now();

        $lastSaleAt = $this->lastSaleAt($distributor->id);
        $clockFrom = $this->clockStart($distributor, $lastSaleAt);
        $dormantSince = $clockFrom->copy()->addMonths($this->settings->inactivityMonths());

        return new InactivityAssessment(
            distributorId: $distributor->id,
            lastSaleAt: $lastSaleAt,
            clockRunningFrom: $clockFrom,
            dormantFrom: $dormantSince,
            isDormant: $dormantSince->lessThanOrEqualTo($asOf),
            noticeIssuedAt: $distributor->inactivity_notice_at,
            noticeExpiresAt: $distributor->inactivity_notice_expires_at,
        );
    }

    /**
     * Issue the seven-day §21 notice. Idempotent — a distributor already under
     * an unexpired notice is left alone.
     */
    public function issueNotice(Distributor $distributor, ?Carbon $at = null): bool
    {
        if ($distributor->inactivity_notice_at !== null) {
            return false;
        }

        $at ??= Carbon::now();
        $expiresAt = $at->copy()->addDays($this->settings->noticeDays());

        $assessment = $this->assess($distributor, $at);

        DB::transaction(function () use ($distributor, $at, $expiresAt, $assessment): void {
            $distributor->forceFill([
                'inactivity_notice_at' => $at,
                'inactivity_notice_expires_at' => $expiresAt,
            ])->save();

            AuditLog::create([
                'actor_id' => null,
                'action' => 'distributor.inactivity_notice_issued',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'details' => [
                    'adn' => $distributor->adn,
                    'last_sale_at' => $assessment->lastSaleAt?->toDateString(),
                    'clock_from' => $assessment->clockRunningFrom->toDateString(),
                    'notice_expires_at' => $expiresAt->toDateString(),
                ],
            ]);
        });

        $user = $distributor->user;

        if ($user !== null && $user->email !== null) {
            $user->notify(new InactivityTerminationNoticeNotification(
                adn: $distributor->adn,
                lastSaleAt: $assessment->lastSaleAt?->format('d M Y'),
                noticeExpiresAt: $expiresAt->format('d M Y'),
                noticeDays: $this->settings->noticeDays(),
            ));
        }

        return true;
    }

    /**
     * Clear a notice because the distributor sold something inside the window.
     *
     * §21 is about dormancy, not punishment: one sale and the account is live
     * again with no residue.
     */
    public function clearNotice(Distributor $distributor, string $reason): void
    {
        if ($distributor->inactivity_notice_at === null) {
            return;
        }

        DB::transaction(function () use ($distributor, $reason): void {
            $distributor->forceFill([
                'inactivity_notice_at' => null,
                'inactivity_notice_expires_at' => null,
            ])->save();

            AuditLog::create([
                'actor_id' => null,
                'action' => 'distributor.inactivity_notice_cleared',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'details' => ['adn' => $distributor->adn, 'reason' => $reason],
            ]);
        });
    }

    /**
     * Terminate an account whose notice has expired.
     *
     * Records the date the same PAN may hold an account again: §21 sets one
     * year for a ranked seller and two for the Diamond tiers and above. An
     * unranked seller carries no wait — they never held a position the wait
     * exists to protect.
     */
    public function terminate(Distributor $distributor, ?Carbon $at = null): bool
    {
        if ($distributor->inactivity_notice_expires_at === null) {
            return false;
        }

        $at ??= Carbon::now();

        if ($distributor->inactivity_notice_expires_at->greaterThan($at)) {
            return false;
        }

        $user = $distributor->user;

        if ($user === null || $user->status === 'terminated') {
            return false;
        }

        $highestRank = $this->highestRankAchieved($distributor->id);
        $waitYears = $this->settings->reregistrationWaitYears($highestRank);
        $allowedFrom = $waitYears > 0 ? $at->copy()->addYears($waitYears)->startOfDay() : null;
        $reason = sprintf(
            'Automatic termination under agreement §21 — no sale for %d continuous months; %d-day notice issued %s expired.',
            $this->settings->inactivityMonths(),
            $this->settings->noticeDays(),
            $distributor->inactivity_notice_at?->format('d M Y') ?? 'previously'
        );

        DB::transaction(function () use ($distributor, $user, $at, $allowedFrom, $reason, $highestRank, $waitYears): void {
            $user->update([
                'status' => 'terminated',
                'closure_type' => 'admin_termination',
            ]);

            $distributor->forceFill([
                'status' => 'inactive',
                'terminated_at' => $at,
                'termination_reason' => $reason,
                'reregistration_allowed_from' => $allowedFrom,
            ])->save();

            AuditLog::create([
                'actor_id' => null,
                'action' => 'distributor.inactivity_terminated',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'details' => [
                    'adn' => $distributor->adn,
                    'highest_rank' => $highestRank,
                    'reregistration_wait_years' => $waitYears,
                    'reregistration_allowed_from' => $allowedFrom?->toDateString(),
                ],
            ]);
        });

        DistributorTerminated::dispatch($distributor->id, null, $reason, $at);

        return true;
    }

    /**
     * The most recent sale attributed to this distributor, or null if there
     * has never been one.
     */
    public function lastSaleAt(int $distributorId): ?Carbon
    {
        $timestamp = DB::table('orders')
            ->where('attributed_distributor_id', $distributorId)
            ->whereIn('status', self::SALE_STATUSES)
            ->max('paid_at');

        return $timestamp === null ? null : Carbon::parse($timestamp);
    }

    /**
     * Highest rank ever qualified for. Voided qualifications do not count —
     * a rank that was taken back was never held.
     */
    public function highestRankAchieved(int $distributorId): int
    {
        return (int) DB::table('rank_qualifications')
            ->where('distributor_id', $distributorId)
            ->where('status', 'qualified')
            ->max('rank_number');
    }

    private function clockStart(Distributor $distributor, ?Carbon $lastSaleAt): Carbon
    {
        $effectiveDate = $distributor->effective_date !== null
            ? Carbon::parse($distributor->effective_date)
            : Carbon::parse($distributor->created_at);

        if ($lastSaleAt === null) {
            return $effectiveDate;
        }

        return $lastSaleAt->greaterThan($effectiveDate) ? $lastSaleAt : $effectiveDate;
    }
}
