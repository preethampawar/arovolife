<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Events\KycApproved;
use App\Modules\Admin\Services\Exceptions\KycHasNoDocumentsError;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * Manual KYC approval. Replaces the automated PAN/Aadhaar/bank gateway gate
 * during Phase 1 — same gate, different mechanism. The admin has reviewed
 * the uploaded documents and is asserting they match the text fields the
 * applicant submitted.
 *
 * Refuses to run on a distributor with zero kyc_documents — this prevents
 * an admin from rubber-stamping a fully-stub registration.
 */
final class ApproveKycSubmission
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $distributorId, int $verifierUserId): void
    {
        $this->db->connection()->transaction(function () use ($distributorId, $verifierUserId): void {
            /** @var Distributor $distributor */
            $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);

            // Couple registrations are approved as a unit. The admin always
            // operates on the primary; if invoked on a secondary, redirect
            // to the primary so the action is symmetric regardless of which
            // row the admin clicked.
            if ($distributor->spouse_distributor_id !== null && ! $distributor->is_primary_couple) {
                /** @var Distributor $primary */
                $primary = Distributor::query()->lockForUpdate()->findOrFail($distributor->spouse_distributor_id);
                $distributorId = (int) $primary->id;
                $distributor = $primary;
            }

            $idsToApprove = [$distributorId];
            if ($distributor->is_primary_couple && $distributor->spouse_distributor_id !== null) {
                $idsToApprove[] = (int) $distributor->spouse_distributor_id;
            }

            $docs = KycDocument::query()
                ->whereIn('distributor_id', $idsToApprove)
                ->lockForUpdate()
                ->get();
            if ($docs->isEmpty()) {
                throw new KycHasNoDocumentsError(
                    "Distributor {$distributorId} has no KYC documents to approve.",
                );
            }

            $now = Carbon::now();

            foreach ($docs as $doc) {
                if ($doc->verified_at !== null) {
                    continue; // idempotent re-approval
                }
                $doc->verified_at = $now;
                $doc->verifier_id = $verifierUserId;
                $doc->save();
            }

            // Flip user.status='active' on every distributor in the unit
            // (one row for solo, two for couples).
            //
            // IMPORTANT: do NOT use `$d->user()->update(...)` here — calling
            // update() on a BelongsTo builder has historically been an
            // unscoped UPDATE in some Laravel versions (the `users.id = ?`
            // constraint isn't always applied to the update query), which
            // would flip every user.status='pending' row to 'active' and
            // approve every queued KYC. Pluck the specific user_ids first
            // and run one explicit whereIn update.
            $userIds = Distributor::query()
                ->whereIn('id', $idsToApprove)
                ->pluck('user_id')
                ->filter()
                ->map(fn ($v) => (int) $v)
                ->values()
                ->all();

            if ($userIds !== []) {
                User::query()
                    ->whereIn('id', $userIds)
                    ->update(['status' => 'active']);
            }

            AuditLog::create([
                'actor_id' => $verifierUserId,
                'action' => 'admin.kyc.approved',
                'subject_type' => 'distributor',
                'subject_id' => $distributorId,
                'details' => [
                    'verified_at' => $now->toIso8601String(),
                    'document_count' => $docs->count(),
                    'distributor_ids' => $idsToApprove,
                ],
            ]);

            foreach ($idsToApprove as $id) {
                KycApproved::dispatch($id, $verifierUserId, $now);
            }
        });
    }
}
