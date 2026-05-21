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
use Illuminate\Support\Facades\Storage;
use Throwable;

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
                // The status flip and the activation timestamp move together
                // by definition — `activated_at` is the audit-trail twin of
                // `status='active'`. Captured here (not in the audit_log) so
                // the dashboard's "Activation Date" stat is a cheap column
                // read rather than a join against audit_log.
                User::query()
                    ->whereIn('id', $userIds)
                    ->update([
                        'status' => 'active',
                        'activated_at' => $now,
                    ]);
            }

            // Post-verification PII purge (accepted-risk design from 2026-05).
            // Now that an admin has confirmed the documents match the text the
            // applicant submitted, we no longer need the full PAN / Aadhaar /
            // ID-card images. Drop them; keep only last-4 + hash + reference.
            $purged = $this->purgeIdNumbersAndFiles($idsToApprove);

            AuditLog::create([
                'actor_id' => $verifierUserId,
                'action' => 'admin.kyc.approved',
                'subject_type' => 'distributor',
                'subject_id' => $distributorId,
                'details' => [
                    'verified_at' => $now->toIso8601String(),
                    'document_count' => $docs->count(),
                    'distributor_ids' => $idsToApprove,
                    'purged_files' => $purged['paths'],
                    'purged_doc_ids' => $purged['doc_ids'],
                    'encrypted_numbers_nulled' => $purged['rows_updated'],
                ],
            ]);

            foreach ($idsToApprove as $id) {
                KycApproved::dispatch($id, $verifierUserId, $now);
            }
        });
    }

    /**
     * Wipe full PAN + Aadhaar + their uploaded ID-card files. Called after a
     * successful approval, inside the same transaction so that a downstream
     * failure rolls back the verified_at flip too.
     *
     * @param  list<int>  $distributorIds
     * @return array{paths: list<string>, doc_ids: list<int>, rows_updated: int}
     */
    private function purgeIdNumbersAndFiles(array $distributorIds): array
    {
        if ($distributorIds === []) {
            return ['paths' => [], 'doc_ids' => [], 'rows_updated' => 0];
        }

        /** @var list<KycDocument> $idDocs */
        $idDocs = KycDocument::query()
            ->whereIn('distributor_id', $distributorIds)
            ->whereIn('type', ['pan', 'aadhaar'])
            ->get()
            ->all();

        $disk = Storage::disk('kyc');
        $paths = [];
        $docIds = [];

        foreach ($idDocs as $doc) {
            $paths[] = $doc->object_storage_key;
            $docIds[] = (int) $doc->id;

            // S3 delete() returns true/false; we don't fail the approval on a
            // missing object (already-purged or never-uploaded) — but we do
            // capture the path in the audit log either way. Any other error
            // (auth, network) bubbles up and rolls back the transaction.
            try {
                $disk->delete($doc->object_storage_key);
            } catch (Throwable $e) {
                report($e);
                // Re-throw so the transaction rolls back and the admin sees
                // the failure rather than silently approving with files left
                // on disk. The retry mechanism is "try again from admin UI".
                throw $e;
            }
        }

        if ($docIds !== []) {
            KycDocument::query()->whereIn('id', $docIds)->delete();
        }

        $rowsUpdated = Distributor::query()
            ->whereIn('id', $distributorIds)
            ->update([
                'pan_encrypted' => null,
                'aadhaar_encrypted' => null,
            ]);

        return [
            'paths' => $paths,
            'doc_ids' => $docIds,
            'rows_updated' => (int) $rowsUpdated,
        ];
    }
}
