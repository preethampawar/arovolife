<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Events\CoolingOffCancelled;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Models\CoolingOffEvent;
use App\Modules\Compliance\Services\Exceptions\CoolingOffAlreadyCancelledError;
use App\Modules\Compliance\Services\Exceptions\CoolingOffWindowExpiredError;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * Statutory: T&C §4 + DSR 2021 Rule 5(1)(g) — a distributor may cancel
 * within 30 days of Effective Date in a single click. We never delete the
 * tree node (slot is preserved as a "ghost"); we only flip user.status to
 * 'terminated' and stamp cancelled_at.
 *
 * Phase 1 has no money flowing at registration, so there is no refund to
 * issue. Phase 3+ wallet listeners pick up the CoolingOffCancelled event
 * and reverse downstream credits when those exist.
 */
final class CancelCoolingOff
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $distributorId, int $actorUserId): void
    {
        $this->db->connection()->transaction(function () use ($distributorId, $actorUserId): void {
            /** @var Distributor $distributor */
            $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);

            $now = Carbon::now();

            if ($now->greaterThan($distributor->cooling_off_end_at)) {
                throw new CoolingOffWindowExpiredError(
                    "Cooling-off window for distributor {$distributorId} ended at {$distributor->cooling_off_end_at}",
                );
            }

            // Couple cascade: T&C §7 treats a couple as one business unit, so
            // either party cancelling dissolves the unit. We close BOTH spouses'
            // cooling-off events and flip BOTH user.status to 'terminated' in a
            // single transaction. The audit_log details record which side was
            // the trigger ("self") vs the cascade target ("cascaded_from_spouse").
            $idsToCancel = [$distributorId];
            if ($distributor->spouse_distributor_id !== null) {
                $idsToCancel[] = (int) $distributor->spouse_distributor_id;
            }

            foreach ($idsToCancel as $id) {
                $isTrigger = $id === $distributorId;
                $this->cancelOne(
                    distributorId: $id,
                    triggerDistributorId: $distributorId,
                    actorUserId: $actorUserId,
                    now: $now,
                    isTrigger: $isTrigger,
                );
            }
        });
    }

    private function cancelOne(
        int $distributorId,
        int $triggerDistributorId,
        int $actorUserId,
        Carbon $now,
        bool $isTrigger,
    ): void {
        /** @var Distributor $distributor */
        $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);

        // The cooling_off_events row is opened at registration; if it
        // is missing (legacy distributor) we upsert with opened_at =
        // effective_date so the historical clock is reconstructable.
        $event = CoolingOffEvent::query()
            ->where('distributor_id', $distributorId)
            ->lockForUpdate()
            ->first();

        if ($event === null) {
            $event = CoolingOffEvent::create([
                'distributor_id' => $distributorId,
                'opened_at' => $distributor->effective_date,
            ]);
        }

        if ($event->cancelled_at !== null) {
            // Already cancelled — only the originally-clicked side throws
            // a user-visible error. The cascade target being already
            // cancelled is benign (idempotent).
            if ($isTrigger) {
                throw new CoolingOffAlreadyCancelledError(
                    "Distributor {$distributorId} already cancelled at {$event->cancelled_at}",
                );
            }

            return;
        }

        $event->cancelled_at = $now;
        $event->save();

        // Scoped to this distributor's user only. Calling ->user()->update()
        // on a BelongsTo runs unscoped on some Laravel versions and would
        // terminate every user; updating the loaded model directly is safe.
        if ($distributor->user !== null) {
            $distributor->user->update([
                'status' => 'terminated',
                'closure_type' => 'cooling_off_cancellation',
            ]);
        }

        // The distributor-record flag follows the account into its terminal
        // state, so the admin show page no longer reads "Distributor: Active"
        // for a cancelled account. The tree node itself is preserved (ghost
        // slot) — only the status flag flips.
        $distributor->update(['status' => 'inactive']);

        AuditLog::create([
            'actor_id' => $actorUserId,
            'action' => 'compliance.cooling_off.cancelled',
            'subject_type' => 'distributor',
            'subject_id' => $distributorId,
            'details' => [
                'cancelled_at' => $now->toIso8601String(),
                'self_cancel' => $actorUserId === (int) $distributor->user_id,
                'trigger_distributor_id' => $triggerDistributorId,
                'cascaded' => ! $isTrigger,
            ],
        ]);

        CoolingOffCancelled::dispatch($distributorId, $actorUserId, $now);
    }
}
