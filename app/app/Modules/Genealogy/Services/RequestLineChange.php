<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeRequested;
use App\Modules\Genealogy\Models\GenealogyClosure;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyRequestedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasDownlineError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeNewSponsorTooNewError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeWindowExpiredError;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * T&C §10: distributor may request a line-change within 5 working days of
 * registration provided they have no downline (and no purchases — Phase 1
 * has none anyway, so no purchase guard needed yet; bolt on in Phase 2+).
 *
 * This service ONLY records the request. Admin approve/reject is a
 * separate workflow (Phase 7 in the master plan).
 */
final class RequestLineChange
{
    private const BUSINESS_DAY_WINDOW = 5;

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(
        int $distributorId,
        int $toSponsorId,
        int $actorUserId,
        ?string $reason = null,
    ): LineChangeRequest {
        return $this->db->connection()->transaction(function () use ($distributorId, $toSponsorId, $actorUserId, $reason): LineChangeRequest {
            /** @var Distributor $distributor */
            $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);

            $now = Carbon::now();

            // Window: 5 *working* days from effective_date. Carbon 3
            // returns a fractional value when the times-of-day differ, so
            // (int) truncates toward zero — i.e. "5 weekdays elapsed" stays
            // at boundary 5 until the 6th full weekday rolls over. The cast
            // is deliberate; do not silently change to round/ceil without
            // updating LCR-02 and the test fixtures.
            $businessDaysSince = (int) $distributor->effective_date->diffInWeekdays($now);
            if ($businessDaysSince > self::BUSINESS_DAY_WINDOW) {
                throw new LineChangeWindowExpiredError(
                    "Line-change window for distributor {$distributorId} ended; "
                    ."{$businessDaysSince} business days have elapsed (max ".self::BUSINESS_DAY_WINDOW.').',
                );
            }

            // No downline: closure rows where ancestor=self exclude depth 0
            // (the self-row). Any depth>=1 means a real descendant exists.
            $hasDownline = GenealogyClosure::query()
                ->where('ancestor_id', $distributorId)
                ->where('depth', '>=', 1)
                ->exists();
            if ($hasDownline) {
                throw new LineChangeHasDownlineError(
                    "Distributor {$distributorId} has descendants and cannot request a line-change.",
                );
            }

            // Idempotency: only one pending request at a time per distributor.
            $existing = LineChangeRequest::query()
                ->where('distributor_id', $distributorId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                throw new LineChangeAlreadyRequestedError(
                    "A pending line-change request (id={$existing->id}) already exists for distributor {$distributorId}.",
                );
            }

            // Senior-sponsor rule: the new sponsor must have registered with
            // the platform STRICTLY before the requesting distributor. Without
            // this guard, a recently-registered distributor could move under a
            // peer who registered later — breaking the "older registrant
            // sponsors newer registrant" invariant the binary tree relies on.
            // Lock the new sponsor's row so a concurrent mutation can't
            // change their effective_date mid-check.
            /** @var Distributor $newSponsor */
            $newSponsor = Distributor::query()->lockForUpdate()->findOrFail($toSponsorId);

            if (! $newSponsor->effective_date->lessThan($distributor->effective_date)) {
                throw new LineChangeNewSponsorTooNewError(
                    "Distributor {$distributorId} (joined {$distributor->effective_date->toDateString()}) "
                    ."cannot move under sponsor {$toSponsorId} (joined {$newSponsor->effective_date->toDateString()}); "
                    .'the new sponsor must have joined earlier.'
                );
            }

            $request = LineChangeRequest::create([
                'distributor_id' => $distributorId,
                'from_sponsor_id' => (int) $distributor->sponsor_id,
                'to_sponsor_id' => $toSponsorId,
                'requested_at' => $now,
                'status' => 'pending',
                'reason' => $reason !== null ? mb_substr($reason, 0, 512) : null,
            ]);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'genealogy.line_change.requested',
                'subject_type' => 'distributor',
                'subject_id' => $distributorId,
                'details' => [
                    'request_id' => $request->id,
                    'from_sponsor_id' => (int) $distributor->sponsor_id,
                    'to_sponsor_id' => $toSponsorId,
                    'to_sponsor_effective_date' => $newSponsor->effective_date->toIso8601String(),
                    'requester_effective_date' => $distributor->effective_date->toIso8601String(),
                    'business_days_since_join' => $businessDaysSince,
                ],
            ]);

            LineChangeRequested::dispatch(
                $request->id,
                $distributorId,
                (int) $distributor->sponsor_id,
                $toSponsorId,
                $now,
            );

            return $request;
        });
    }
}
