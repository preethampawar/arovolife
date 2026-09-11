<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Events\KycApproved;
use App\Modules\Admin\Services\Exceptions\KycHasFlaggedDocumentsError;
use App\Modules\Admin\Services\Exceptions\KycHasNoDocumentsError;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
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
 * an admin from rubber-stamping a fully-stub registration — and on one whose
 * documents include an unresolved re-upload flag.
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

            // Refuses to run while a document is flagged for re-upload. The
            // flag is a reviewer saying "this one is not acceptable"; approving
            // over it accepts that document anyway and silently revokes the
            // applicant's re-upload link, which only works while the flag
            // stands. The rows are already held under lockForUpdate, so a
            // concurrent flag either lands before this check or waits for it.
            $flagged = $docs->filter(fn (KycDocument $doc): bool => $doc->flagged_at !== null);
            if ($flagged->isNotEmpty()) {
                throw new KycHasFlaggedDocumentsError(
                    "Distributor {$distributorId} has {$flagged->count()} document(s) flagged for re-upload.",
                );
            }

            $now = Carbon::now();

            // Everything this approval moves, captured before it moves: the
            // status of every user in the unit, the verification stamp on
            // every document, and the distributor rows the PII purge rewrites.
            $before = $this->auditedState($idsToApprove);

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

            // Post-verification number purge. Now that an admin has confirmed
            // the documents match the text the applicant submitted, the full
            // PAN / Aadhaar numbers go; only last-4 + hash remain. The scans
            // themselves stay — encrypted, served only through the audited
            // admin route, and erased by `kyc:purge-expired-documents` once
            // the published retention period has run (client decision
            // 2026-09-11, R-31).
            $numbersNulled = $this->purgeIdNumbers($idsToApprove);

            AuditLog::create([
                'actor_id' => $verifierUserId,
                'action' => 'admin.kyc.approved',
                'subject_type' => 'distributor',
                'subject_id' => $distributorId,
                'before_hash' => AuditDigests::of($before),
                'after_hash' => AuditDigests::of($this->auditedState($idsToApprove)),
                'details' => [
                    'verified_at' => $now->toIso8601String(),
                    'document_count' => $docs->count(),
                    'distributor_ids' => $idsToApprove,
                    'documents_retained' => $docs->count(),
                    'encrypted_numbers_nulled' => $numbersNulled,
                ],
            ]);

            foreach ($idsToApprove as $id) {
                KycApproved::dispatch($id, $verifierUserId, $now);
            }
        });
    }

    /**
     * The state an approval changes, for the before/after audit digests: the
     * status of every user in the unit, the verification stamp on every
     * document, and the distributor rows themselves — so the PII purge shows
     * up as a moved digest rather than only as a detail count.
     *
     * @param  list<int>  $distributorIds
     * @return array<string, mixed>
     */
    private function auditedState(array $distributorIds): array
    {
        $distributors = Distributor::query()
            ->whereIn('id', $distributorIds)
            ->orderBy('id')
            ->get();

        return [
            'distributors' => $distributors
                ->mapWithKeys(fn (Distributor $d): array => [(int) $d->id => AuditDigests::snapshot($d)])
                ->all(),
            'user_status' => User::query()
                ->whereIn('id', $distributors->pluck('user_id')->filter()->all())
                ->orderBy('id')
                ->pluck('status', 'id')
                ->all(),
            'documents_verified' => KycDocument::query()
                ->whereIn('distributor_id', $distributorIds)
                ->orderBy('id')
                ->pluck('verified_at', 'id')
                ->all(),
        ];
    }

    /**
     * Null the full PAN + Aadhaar numbers. Called after a successful approval,
     * inside the same transaction so that a downstream failure rolls back the
     * verified_at flip too.
     *
     * @param  list<int>  $distributorIds
     * @return int distributor rows updated
     */
    private function purgeIdNumbers(array $distributorIds): int
    {
        if ($distributorIds === []) {
            return 0;
        }

        return (int) Distributor::query()
            ->whereIn('id', $distributorIds)
            ->update([
                'pan_encrypted' => null,
                'aadhaar_encrypted' => null,
            ]);
    }
}
