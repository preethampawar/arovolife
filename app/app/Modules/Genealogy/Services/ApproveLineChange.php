<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeApproved;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasDownlineError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeLockTimeoutError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeNotPendingError;
use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Executes an approved line change: moves the requester's BINARY PLACEMENT
 * under the requested parent on the admin-chosen side, recomputes depth, and
 * rebuilds the requester's closure rows. The sponsor link is untouched.
 *
 * Safe because the requester is a leaf (RequestLineChange enforces no
 * downline), so only the requester's own closure rows change.
 */
final class ApproveLineChange
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $requestId, int $reviewerUserId, string $chosenSide): void
    {
        if (! in_array($chosenSide, ['L', 'R'], true)) {
            throw new RuntimeException("Invalid side '{$chosenSide}'; expected L or R.");
        }

        $this->db->connection()->transaction(function () use ($requestId, $reviewerUserId, $chosenSide): void {
            /** @var LineChangeRequest $request */
            $request = LineChangeRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ($request->status !== 'pending') {
                throw new LineChangeNotPendingError("Line-change request {$requestId} is not pending (status={$request->status}).");
            }

            $distributorId = (int) $request->distributor_id;
            $newParentId = (int) $request->to_placement_parent_id;

            $usingMysql = $this->db->connection()->getDriverName() === 'mysql';
            if ($usingMysql) {
                $got = $this->db->selectOne('SELECT GET_LOCK(?, 5) AS got', ["placement:{$newParentId}"]);
                if ((int) ($got->got ?? 0) !== 1) {
                    throw new LineChangeLockTimeoutError("Could not lock target parent {$newParentId}.");
                }
            }

            try {
                /** @var Distributor $newParent */
                $newParent = Distributor::query()->lockForUpdate()->findOrFail($newParentId);

                $taken = $this->db->table('distributors')
                    ->where('placement_parent_id', $newParentId)
                    ->where('id', '!=', $newParentId)
                    ->whereIn('placement_side', ['L', 'R'])
                    ->pluck('placement_side')
                    ->all();
                if (in_array($chosenSide, $taken, true)) {
                    throw new LineChangePlacementSlotFullError(
                        "Slot {$chosenSide} under parent {$newParentId} is already taken.",
                    );
                }

                /** @var Distributor $distributor */
                $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);

                // RequestLineChange enforced no-downline at REQUEST time, but that guard can
                // go stale before approval (a child may have been placed under the requester
                // since). The closure rebuild only touches the requester's own rows, so a
                // downline now present would corrupt the tree — re-check under the lock.
                $hasDownline = $this->db->table('genealogy_closure')
                    ->where('ancestor_id', $distributorId)
                    ->where('depth', '>=', 1)
                    ->exists();
                if ($hasDownline) {
                    throw new LineChangeHasDownlineError(
                        "Distributor {$distributorId} is no longer a leaf; line-change cannot be executed safely.",
                    );
                }

                $fromParentId = (int) $distributor->placement_parent_id;
                $newDepth = (int) $newParent->depth + 1;
                $now = Carbon::now();

                $this->db->table('distributors')->where('id', $distributorId)->update([
                    'placement_parent_id' => $newParentId,
                    'placement_side' => $chosenSide,
                    'side_chosen_by' => 'referral_explicit',
                    'depth' => $newDepth,
                    'updated_at' => $now->format('Y-m-d H:i:s.v'),
                ]);

                $this->db->table('genealogy_closure')
                    ->where('descendant_id', $distributorId)
                    ->where('depth', '>=', 1)
                    ->delete();

                $ancestors = $this->db->table('genealogy_closure')
                    ->where('descendant_id', $newParentId)
                    ->get(['ancestor_id', 'depth']);
                $rows = [];
                foreach ($ancestors as $a) {
                    $rows[] = [
                        'ancestor_id' => $a->ancestor_id,
                        'descendant_id' => $distributorId,
                        'depth' => $a->depth + 1,
                    ];
                }
                if ($rows !== []) {
                    $this->db->table('genealogy_closure')->insert($rows);
                }

                $request->status = 'approved';
                $request->chosen_side = $chosenSide;
                $request->reviewed_by = $reviewerUserId;
                $request->reviewed_at = $now;
                $request->approved_at = $now;
                $request->save();

                AuditLog::create([
                    'actor_id' => $reviewerUserId,
                    'action' => 'genealogy.line_change.approved',
                    'subject_type' => 'distributor',
                    'subject_id' => $distributorId,
                    'details' => [
                        'request_id' => $requestId,
                        'from_placement_parent_id' => $fromParentId,
                        'to_placement_parent_id' => $newParentId,
                        'chosen_side' => $chosenSide,
                        'new_depth' => $newDepth,
                    ],
                ]);

                LineChangeApproved::dispatch(
                    $requestId,
                    $distributorId,
                    $newParentId,
                    $chosenSide,
                    $reviewerUserId,
                    $now,
                );
            } finally {
                if ($usingMysql) {
                    $this->db->statement('SELECT RELEASE_LOCK(?)', ["placement:{$newParentId}"]);
                }
            }
        });
    }
}
