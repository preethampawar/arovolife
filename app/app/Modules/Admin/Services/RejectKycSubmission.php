<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Events\KycRejected;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * Manual KYC rejection. Sets the user's status to 'rejected' — a recoverable
 * state: the applicant can log in, see the reason on a re-upload page, and
 * resubmit replacement documents. Once they do, status flips back to 'pending'
 * and they reappear in the admin queue.
 *
 * The 'terminated' status is reserved for permanent closures (fraud, cooling-
 * off cancellation, repeat offenders) and is not what reject does any more.
 *
 * kyc_documents rows stay intact — reject is "decline this submission", not
 * "scrub everything". The KycRejected event fires the rejection email so the
 * applicant knows why and where to go next.
 */
final class RejectKycSubmission
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $distributorId, int $verifierUserId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Rejection reason cannot be empty.');
        }

        $this->db->connection()->transaction(function () use ($distributorId, $verifierUserId, $reason): void {
            /** @var Distributor $distributor */
            $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);

            // Couple registrations are rejected as a unit. Always operate
            // on the primary's row regardless of which the admin clicked.
            if ($distributor->spouse_distributor_id !== null && ! $distributor->is_primary_couple) {
                /** @var Distributor $primary */
                $primary = Distributor::query()->lockForUpdate()->findOrFail($distributor->spouse_distributor_id);
                $distributorId = (int) $primary->id;
                $distributor = $primary;
            }

            $idsToReject = [$distributorId];
            if ($distributor->is_primary_couple && $distributor->spouse_distributor_id !== null) {
                $idsToReject[] = (int) $distributor->spouse_distributor_id;
            }

            $now = Carbon::now();

            // Same scoped-update bugfix as ApproveKycSubmission — never call
            // ->user()->update() on a BelongsTo, which can run unscoped.
            $userIds = Distributor::query()
                ->whereIn('id', $idsToReject)
                ->pluck('user_id')
                ->filter()
                ->map(fn ($v) => (int) $v)
                ->values()
                ->all();

            if ($userIds !== []) {
                User::query()
                    ->whereIn('id', $userIds)
                    ->update(['status' => 'rejected']);
            }

            AuditLog::create([
                'actor_id' => $verifierUserId,
                'action' => 'admin.kyc.rejected',
                'subject_type' => 'distributor',
                'subject_id' => $distributorId,
                'details' => [
                    'reason' => mb_substr($reason, 0, 1024),
                    'rejected_at' => $now->toIso8601String(),
                    'distributor_ids' => $idsToReject,
                ],
            ]);

            foreach ($idsToReject as $id) {
                KycRejected::dispatch($id, $verifierUserId, $reason, $now);
            }
        });
    }
}
