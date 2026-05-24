<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * Permanent closure of a distributor account ('terminated' status). Distinct
 * from RejectKycSubmission, which yields 'rejected' (recoverable). Use this
 * when reject + resubmit isn't appropriate any more: confirmed fraud, repeat
 * rejections, cooling-off cancellation initiated by the distributor, or any
 * other end-of-relationship case.
 *
 * No recovery from here — the distributor can no longer sign in and their
 * encrypted PII stays on file for audit but is not surfaced to them. If
 * the closure is in error the operator must intervene at the DB level.
 */
final class TerminateDistributor
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $distributorId, int $actorUserId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Termination reason cannot be empty.');
        }

        $this->db->connection()->transaction(function () use ($distributorId, $actorUserId, $reason): void {
            /** @var Distributor $distributor */
            $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);

            // Couple registrations close as a unit, primary or secondary.
            if ($distributor->spouse_distributor_id !== null && ! $distributor->is_primary_couple) {
                /** @var Distributor $primary */
                $primary = Distributor::query()->lockForUpdate()->findOrFail($distributor->spouse_distributor_id);
                $distributorId = (int) $primary->id;
                $distributor = $primary;
            }

            $idsToClose = [$distributorId];
            if ($distributor->is_primary_couple && $distributor->spouse_distributor_id !== null) {
                $idsToClose[] = (int) $distributor->spouse_distributor_id;
            }

            $userIds = Distributor::query()
                ->whereIn('id', $idsToClose)
                ->pluck('user_id')
                ->filter()
                ->map(fn ($v) => (int) $v)
                ->values()
                ->all();

            $now = Carbon::now();

            if ($userIds !== []) {
                User::query()
                    ->whereIn('id', $userIds)
                    ->update(['status' => 'terminated']);
            }

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'admin.distributor.terminated',
                'subject_type' => 'distributor',
                'subject_id' => $distributorId,
                'details' => [
                    'reason' => mb_substr($reason, 0, 1024),
                    'terminated_at' => $now->toIso8601String(),
                    'distributor_ids' => $idsToClose,
                ],
            ]);

            foreach ($idsToClose as $id) {
                DistributorTerminated::dispatch($id, $actorUserId, $reason, $now);
            }
        });
    }
}
