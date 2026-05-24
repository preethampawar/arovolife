<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Events\KycResubmitted;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Re-submission of KYC documents by a distributor whose previous submission
 * was rejected. Replaces unverified documents of the same type, flips the
 * user's status from 'rejected' back to 'pending' so the admin sees them in
 * the review queue, and dispatches KycResubmitted so the admin gets a
 * notification email and the applicant gets a confirmation.
 *
 * Verified docs are never replaced here — by definition a rejected
 * distributor has no verified docs (rejection happens before approval), but
 * the guard exists for safety.
 */
final class ResubmitKycSubmission
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, UploadedFile>  $files  keyed by document type
     *                                              (e.g. 'pan', 'aadhaar')
     */
    public function __invoke(int $distributorId, array $files): void
    {
        if ($files === []) {
            throw new \InvalidArgumentException('No documents provided for resubmission.');
        }

        $this->db->connection()->transaction(function () use ($distributorId, $files): void {
            /** @var Distributor $distributor */
            $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);

            // Couple registrations are rejected as a unit (see
            // RejectKycSubmission) — both spouses' user.status flips to
            // 'rejected' simultaneously. The primary is the document owner
            // (the wizard captures one PAN, one Aadhaar, etc.); if the
            // secondary tries to resubmit we redirect them to the primary's
            // row so we never have a half-recovered couple. Either way the
            // user-status flip later in this method targets BOTH user rows.
            if ($distributor->spouse_distributor_id !== null && ! $distributor->is_primary_couple) {
                /** @var Distributor $primary */
                $primary = Distributor::query()->lockForUpdate()->findOrFail($distributor->spouse_distributor_id);
                $distributorId = (int) $primary->id;
                $distributor = $primary;
            }

            $userIdsToReactivate = [(int) $distributor->user_id];
            if ($distributor->is_primary_couple && $distributor->spouse_distributor_id !== null) {
                $spouseUserId = (int) Distributor::query()
                    ->where('id', $distributor->spouse_distributor_id)
                    ->value('user_id');
                if ($spouseUserId > 0) {
                    $userIdsToReactivate[] = $spouseUserId;
                }
            }

            $disk = Storage::disk('kyc');
            $now = Carbon::now();
            $replaced = [];

            foreach ($files as $type => $file) {
                $existing = KycDocument::query()
                    ->where('distributor_id', $distributorId)
                    ->where('type', $type)
                    ->latest()
                    ->first();

                if ($existing !== null && $existing->verified_at !== null) {
                    // Should never hit this for a rejected distributor — they
                    // can't have verified docs by definition — but guard anyway.
                    continue;
                }

                $sha256 = hash_file('sha256', $file->getRealPath());
                $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
                $path = "user_{$distributor->user_id}/{$type}_resubmit_".substr($sha256, 0, 12).".{$extension}";

                if ($existing !== null) {
                    try {
                        $disk->delete($existing->object_storage_key);
                    } catch (\Throwable) {
                        Log::warning('kyc.resubmit: could not delete old object', [
                            'key' => $existing->object_storage_key,
                        ]);
                    }
                    $existing->delete();
                }

                $disk->putFileAs(dirname($path), $file, basename($path));

                KycDocument::create([
                    'distributor_id' => $distributorId,
                    'type' => $type,
                    'object_storage_key' => $path,
                    'checksum_sha256' => hex2bin($sha256),
                ]);

                $replaced[] = $type;
            }

            // Move the user (and the spouse, for couple registrations) back
            // into the review queue. Limited to status='rejected' so we don't
            // accidentally pull an already-active spouse back into review.
            User::query()
                ->whereIn('id', $userIdsToReactivate)
                ->where('status', 'rejected')
                ->update(['status' => 'pending']);

            AuditLog::create([
                'actor_id' => $distributor->user_id,
                'action' => 'kyc.resubmitted',
                'subject_type' => 'distributor',
                'subject_id' => $distributorId,
                'details' => [
                    'document_types' => $replaced,
                    'resubmitted_at' => $now->toIso8601String(),
                ],
            ]);

            KycResubmitted::dispatch($distributorId, $replaced, $now);
        });
    }
}
