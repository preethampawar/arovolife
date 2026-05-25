<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeRequested;
use App\Modules\Genealogy\Models\GenealogyClosure;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyProcessedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyRequestedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasDownlineError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeNewParentTooNewError;
use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeWindowExpiredError;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * T&C §10: within 5 working days of registration, a leaf distributor may
 * request to move their BINARY PLACEMENT under a different parent. Their
 * sponsor is NOT changed. One approved change per distributor, ever.
 *
 * This service only records the request. ApproveLineChange / RejectLineChange
 * perform the decision and the actual move.
 */
final class RequestLineChange
{
    private const BUSINESS_DAY_WINDOW = 5;

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(
        int $distributorId,
        int $toPlacementParentId,
        int $actorUserId,
        ?string $reason = null,
    ): LineChangeRequest {
        return $this->db->connection()->transaction(function () use ($distributorId, $toPlacementParentId, $actorUserId, $reason): LineChangeRequest {
            /** @var Distributor $distributor */
            $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);

            $now = Carbon::now();

            // 5 working days from effective_date. (int) truncation is
            // deliberate — see LCR-02/LCR-05.
            $businessDaysSince = (int) $distributor->effective_date->diffInWeekdays($now);
            if ($businessDaysSince > self::BUSINESS_DAY_WINDOW) {
                throw new LineChangeWindowExpiredError(
                    "Line-change window for distributor {$distributorId} ended; "
                    ."{$businessDaysSince} business days have elapsed (max ".self::BUSINESS_DAY_WINDOW.').',
                );
            }

            // No downline — keeps the requester a leaf so the move only
            // rewrites their own closure rows.
            $hasDownline = GenealogyClosure::query()
                ->where('ancestor_id', $distributorId)
                ->where('depth', '>=', 1)
                ->exists();
            if ($hasDownline) {
                throw new LineChangeHasDownlineError(
                    "Distributor {$distributorId} has descendants and cannot request a line-change.",
                );
            }

            // One change per distributor, ever: block if any prior request
            // was approved.
            $alreadyApproved = LineChangeRequest::query()
                ->where('distributor_id', $distributorId)
                ->where('status', 'approved')
                ->exists();
            if ($alreadyApproved) {
                throw new LineChangeAlreadyProcessedError(
                    "Distributor {$distributorId} has already used their one line change.",
                );
            }

            // Idempotency: one pending request at a time.
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

            // Target parent must have joined STRICTLY before the requester —
            // the binary tree's parent-older-than-child invariant.
            /** @var Distributor $newParent */
            $newParent = Distributor::query()->lockForUpdate()->findOrFail($toPlacementParentId);
            if (! $newParent->effective_date->lessThan($distributor->effective_date)) {
                throw new LineChangeNewParentTooNewError(
                    "Distributor {$distributorId} (joined {$distributor->effective_date->toDateString()}) "
                    ."cannot move under parent {$toPlacementParentId} (joined {$newParent->effective_date->toDateString()}); "
                    .'the new placement parent must have joined earlier.'
                );
            }

            // Target parent must have at least one open slot at request time.
            if (! $this->hasOpenSlot($toPlacementParentId)) {
                throw new LineChangePlacementSlotFullError(
                    "Target placement parent {$toPlacementParentId} has no open L/R slot.",
                );
            }

            $fromParentId = (int) $distributor->placement_parent_id;

            $request = LineChangeRequest::create([
                'distributor_id' => $distributorId,
                'from_placement_parent_id' => $fromParentId,
                'to_placement_parent_id' => $toPlacementParentId,
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
                    'from_placement_parent_id' => $fromParentId,
                    'to_placement_parent_id' => $toPlacementParentId,
                    'to_parent_effective_date' => $newParent->effective_date->toIso8601String(),
                    'requester_effective_date' => $distributor->effective_date->toIso8601String(),
                    'business_days_since_join' => $businessDaysSince,
                ],
            ]);

            LineChangeRequested::dispatch(
                $request->id,
                $distributorId,
                $fromParentId,
                $toPlacementParentId,
                $now,
            );

            return $request;
        });
    }

    /**
     * True when at least one of parent.L / parent.R is free. Mirrors
     * PlacementEngine::hasOpenSlot (children = rows whose placement_parent_id
     * is this parent, excluding the parent's own root self-reference).
     */
    private function hasOpenSlot(int $parentId): bool
    {
        $taken = $this->db->table('distributors')
            ->where('placement_parent_id', $parentId)
            ->where('id', '!=', $parentId)
            ->whereIn('placement_side', ['L', 'R'])
            ->pluck('placement_side')
            ->all();

        return ! (in_array('L', $taken, true) && in_array('R', $taken, true));
    }
}
