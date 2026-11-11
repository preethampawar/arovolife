<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Compensation\Notifications\LifetimeAwardWithdrawnNotification;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\LifetimeAwardService;
use App\Modules\Compliance\Events\CoolingOffCancelled;
use App\Modules\Identity\Models\Distributor;

/**
 * A partner who cancels within the cooling-off period, or whose account is
 * terminated, loses every Lifetime Award not yet paid (user decision
 * 2026-11-11, Q1): a pending award is cancelled, a credited one the payout has
 * not swept is reversed in the wallet. An award already swept into a payout
 * batch stays paid (no clawback). Each withdrawal is audited, the partner is
 * emailed, and the Awards page shows a fixed reason — "Cooling-off
 * cancellation." or "Account terminated." — while the admin's termination
 * text stays in the audit row only.
 *
 * Synchronous, and dispatched after the cancellation or termination has been
 * written. A sweep that runs at the same moment cannot pay a withdrawn award:
 * withdraw() and the payout lock the same awards_credit row, and the payout
 * pays only milestones still `credited`. Not flag-gated: an award credited
 * while the flag was on is still paid after it is switched off, so it must
 * still be withdrawable.
 */
final class WithdrawLifetimeAwardsOnExit
{
    public function __construct(
        private readonly LifetimeAwardService $awards,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    public function handle(CoolingOffCancelled|DistributorTerminated $event): void
    {
        if ($event instanceof CoolingOffCancelled) {
            $reason = 'Cooling-off cancellation.';
            $withdrawn = $this->awards->withdrawUnpaidForDistributor(
                $event->distributorId,
                $reason,
                $event->actorUserId,
                'awards.milestone.withdrawn_cooling_off',
            );
        } else {
            $reason = 'Account terminated.';
            $withdrawn = $this->awards->withdrawUnpaidForDistributor(
                $event->distributorId,
                $reason,
                $event->actorUserId,
                'awards.milestone.withdrawn_termination',
                auditReason: 'Account terminated: '.$event->reason,
            );
        }

        if ($withdrawn === []) {
            return;
        }

        $user = Distributor::find($event->distributorId)?->user;

        foreach ($withdrawn as $milestone) {
            $user?->notify(new LifetimeAwardWithdrawnNotification(
                rankName: $this->plan->rankNames()[$milestone->rank_number] ?? 'Rank '.$milestone->rank_number,
                reason: $reason,
            ));
        }
    }
}
