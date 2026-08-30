<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterApplication;
use App\Modules\Compensation\Models\AreteCenterApplicationDeclaration;
use App\Modules\Compensation\Models\AreteCenterApplicationDocument;
use App\Modules\Compensation\Notifications\AreteCenterApplicationReviewedNotification;
use App\Modules\Compensation\Notifications\AreteCenterApplicationSubmittedNotification;
use App\Modules\Compensation\Support\AreteCenterDeclarations;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Lifecycle of an Arete Development Centre application.
 *
 *   submitted → under_review → approved | rejected
 *                            ↘ needs_changes → (applicant resubmits) → submitted
 *
 * Every transition is audit-logged with before/after and the applicant is
 * notified. Approval is the only step that touches `arete_centers`: it
 * creates the centre active at Phase 1 with the applicant as owner.
 */
final class AreteCenterApplicationService
{
    public const string DISK = 'adc';

    /**
     * @param  array<string, mixed>  $data  validated §A2–A3 fields
     * @param  array<string, list<UploadedFile>>  $files  document type => files
     * @param  list<string>  $declarations  accepted declaration keys
     */
    public function submit(Distributor $distributor, array $data, array $files, array $declarations, ?string $ip): AreteCenterApplication
    {
        if (AreteCenterApplication::query()->where('distributor_id', $distributor->id)->open()->exists()) {
            throw new InvalidArgumentException('You already have an application in progress.');
        }

        $this->assertDeclarationsComplete($declarations);

        $uploaded = [];
        try {
            $application = DB::transaction(function () use ($distributor, $data, $files, $declarations, $ip, &$uploaded): AreteCenterApplication {
                $application = AreteCenterApplication::create([
                    ...$this->centreAttributes($data),
                    'distributor_id' => $distributor->id,
                    'status' => AreteCenterApplication::STATUS_SUBMITTED,
                    'submitted_at' => Carbon::now(),
                ]);

                $uploaded = $this->storeDocuments($application, $files);
                $this->recordDeclarations($application, $declarations, $ip);

                $this->audit($application, 'adc.application.submitted', null, $ip);

                return $application;
            });
        } catch (\Throwable $e) {
            // A rolled-back application must not leave PII on the disk that
            // no row points at (DPDP §8(7)).
            $this->deleteKeys($uploaded);
            throw $e;
        }

        $this->notifyApplicant($application, new AreteCenterApplicationSubmittedNotification($application->centre_name));

        return $application;
    }

    /**
     * The applicant updates an application that was sent back for changes and
     * resubmits it. Existing documents stay unless replaced for that type.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, list<UploadedFile>>  $files
     * @param  list<string>  $declarations
     */
    public function resubmit(AreteCenterApplication $application, array $data, array $files, array $declarations, ?string $ip): AreteCenterApplication
    {
        if (! $application->isEditableByApplicant()) {
            throw new InvalidArgumentException('This application cannot be edited right now.');
        }

        $this->assertDeclarationsComplete($declarations);

        $uploaded = [];
        try {
            DB::transaction(function () use ($application, $data, $files, $declarations, $ip, &$uploaded): void {
                $before = $this->snapshot($application);

                $application->update([
                    ...$this->centreAttributes($data),
                    'status' => AreteCenterApplication::STATUS_SUBMITTED,
                    'submitted_at' => Carbon::now(),
                ]);

                $uploaded = $this->storeDocuments($application, $files, replace: true);
                $application->declarations()->delete();
                $this->recordDeclarations($application, $declarations, $ip);

                $this->audit($application, 'adc.application.resubmitted', $before, $ip);
            });
        } catch (\Throwable $e) {
            $this->deleteKeys($uploaded);
            throw $e;
        }

        $this->notifyApplicant($application, new AreteCenterApplicationSubmittedNotification($application->centre_name, resubmitted: true));

        return $application->refresh();
    }

    public function markUnderReview(AreteCenterApplication $application, User $admin, ?string $ip): AreteCenterApplication
    {
        $this->assertTransition($application, [AreteCenterApplication::STATUS_SUBMITTED]);

        $before = $this->snapshot($application);
        $application->update([
            'status' => AreteCenterApplication::STATUS_UNDER_REVIEW,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => Carbon::now(),
        ]);
        $this->audit($application, 'adc.application.under_review', $before, $ip, $admin);

        return $application;
    }

    public function requestChanges(AreteCenterApplication $application, User $admin, string $reason, ?string $ip): AreteCenterApplication
    {
        $this->assertTransition($application, [AreteCenterApplication::STATUS_SUBMITTED, AreteCenterApplication::STATUS_UNDER_REVIEW]);

        $before = $this->snapshot($application);
        $application->update([
            'status' => AreteCenterApplication::STATUS_NEEDS_CHANGES,
            'admin_notes' => $reason,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => Carbon::now(),
        ]);
        $this->audit($application, 'adc.application.changes_requested', $before, $ip, $admin);
        $this->notifyApplicant($application, new AreteCenterApplicationReviewedNotification($application->centre_name, $application->status, $reason));

        return $application;
    }

    public function reject(AreteCenterApplication $application, User $admin, string $reason, ?string $ip): AreteCenterApplication
    {
        $this->assertTransition($application, AreteCenterApplication::OPEN_STATUSES);

        $before = $this->snapshot($application);
        $application->update([
            'status' => AreteCenterApplication::STATUS_REJECTED,
            'admin_notes' => $reason,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => Carbon::now(),
        ]);
        $this->audit($application, 'adc.application.rejected', $before, $ip, $admin);
        $this->notifyApplicant($application, new AreteCenterApplicationReviewedNotification($application->centre_name, $application->status, $reason));

        return $application;
    }

    /**
     * Approve: create the centre active at Phase 1, owned by the applicant,
     * and link it to the application.
     */
    public function approve(AreteCenterApplication $application, User $admin, ?string $note, ?string $ip): AreteCenter
    {
        $this->assertTransition($application, [AreteCenterApplication::STATUS_SUBMITTED, AreteCenterApplication::STATUS_UNDER_REVIEW]);

        $nameTaken = AreteCenter::query()->active()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($application->centre_name)])
            ->exists();
        if ($nameTaken) {
            throw new InvalidArgumentException('An active centre with this name already exists — ask the applicant to change the name, or rename the existing centre first.');
        }

        $center = DB::transaction(function () use ($application, $admin, $note, $ip): AreteCenter {
            $before = $this->snapshot($application);

            $center = AreteCenter::create([
                'name' => $application->centre_name,
                'centre_type' => AreteCenter::TYPE_DISTRIBUTOR,
                'location' => $application->displayLocation(),
                'district' => $application->city,
                'address_line_1' => $application->address_line_1,
                'address_line_2' => $application->address_line_2,
                'landmark' => $application->landmark,
                'city' => $application->city,
                'pincode' => $application->pincode,
                'state' => $application->state,
                'property_type' => $application->property_type,
                'premises_sqft' => $application->premises_sqft,
                'distance_to_nearest_adc_km' => $application->distance_to_nearest_adc_km,
                'opening_time' => $application->opening_time,
                'closing_time' => $application->closing_time,
                'weekly_off' => $application->weekly_off,
                'contact_person' => $application->contact_person,
                'contact_number' => $application->distributor->user?->phone_e164,
                'alternate_contact_number' => $application->alternate_contact_number,
                'assigned_distributor_id' => $application->distributor_id,
                'status' => AreteCenter::STATUS_ACTIVE,
                'development_phase' => 1,
                'approved_at' => Carbon::now()->toDateString(),
                'notes' => $note,
                'is_company_default' => false,
            ]);

            $application->update([
                'status' => AreteCenterApplication::STATUS_APPROVED,
                'center_id' => $center->id,
                'admin_notes' => $note,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => Carbon::now(),
            ]);

            $this->audit($application, 'adc.application.approved', $before, $ip, $admin, ['center_id' => $center->id]);

            AuditLog::create([
                'actor_id' => $admin->id,
                'action' => 'adc.center.created',
                'subject_type' => 'arete_center',
                'subject_id' => $center->id,
                'details' => ['before' => null, 'after' => $center->only(['name', 'centre_type', 'city', 'state', 'pincode', 'assigned_distributor_id', 'status', 'development_phase']), 'application_id' => $application->id],
                'ip' => $ip,
            ]);

            return $center;
        });

        // Only after commit: a queued "approved" email must never describe a
        // centre whose row was rolled back.
        $this->notifyApplicant($application, new AreteCenterApplicationReviewedNotification($application->centre_name, $application->status, $note));

        return $center;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @param list<string> $allowed */
    private function assertTransition(AreteCenterApplication $application, array $allowed): void
    {
        if (! in_array($application->status, $allowed, true)) {
            throw new InvalidArgumentException('This application is '.strtolower($application->statusLabel()).' and cannot be changed that way.');
        }
    }

    /** @param list<string> $declarations */
    private function assertDeclarationsComplete(array $declarations): void
    {
        $missing = array_diff(AreteCenterDeclarations::keys(), $declarations);
        if ($missing !== []) {
            throw new InvalidArgumentException('Every declaration must be accepted before the application can be submitted.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function centreAttributes(array $data): array
    {
        return [
            'centre_name' => $data['centre_name'],
            'contact_person' => $data['contact_person'] ?? null,
            'alternate_contact_number' => $data['alternate_contact_number'] ?? null,
            'address_line_1' => $data['address_line_1'],
            'address_line_2' => $data['address_line_2'] ?? null,
            'landmark' => $data['landmark'],
            'pincode' => $data['pincode'],
            'city' => $data['city'],
            'state' => $data['state'],
            'property_type' => $data['property_type'],
            'premises_sqft' => (int) $data['premises_sqft'],
            'distance_to_nearest_adc_km' => $data['distance_to_nearest_adc_km'],
            'opening_time' => $data['opening_time'],
            'closing_time' => $data['closing_time'],
            'weekly_off' => $data['weekly_off'],
        ];
    }

    /**
     * Delete every document file and row of an application. Used by the
     * retention purge; the DB cascade alone would strand the S3 objects.
     */
    public function purgeDocuments(AreteCenterApplication $application): int
    {
        $keys = [];
        foreach ($application->documents()->get() as $document) {
            $keys[] = $document->object_storage_key;
        }
        $this->deleteKeys($keys);
        $application->documents()->delete();

        return count($keys);
    }

    /** Extension by validated MIME type — never by the user-supplied filename. */
    private const array EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * @param  array<string, list<UploadedFile>>  $files
     * @return list<string> storage keys written
     */
    private function storeDocuments(AreteCenterApplication $application, array $files, bool $replace = false): array
    {
        $disk = Storage::disk(self::DISK);
        $written = [];

        foreach ($files as $type => $uploads) {
            if ($uploads === []) {
                continue;
            }

            if ($replace) {
                foreach ($application->documents()->where('type', $type)->get() as $old) {
                    try {
                        $disk->delete($old->object_storage_key);
                    } catch (\Throwable) {
                        Log::warning('adc.application: could not delete replaced document', ['key' => $old->object_storage_key]);
                    }
                    $old->delete();
                }
            }

            foreach ($uploads as $file) {
                $sha256 = hash_file('sha256', $file->getRealPath()) ?: '';
                $mime = (string) $file->getMimeType();
                $extension = self::EXTENSIONS[$mime] ?? 'bin';
                $key = "application_{$application->id}/{$type}_".substr($sha256, 0, 12).".{$extension}";

                $disk->putFileAs(dirname($key), $file, basename($key));
                $written[] = $key;

                AreteCenterApplicationDocument::create([
                    'application_id' => $application->id,
                    'type' => $type,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'object_storage_key' => $key,
                    'mime_type' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                    'checksum_sha256' => $sha256,
                ]);
            }
        }

        return $written;
    }

    /** @param list<string> $keys */
    private function deleteKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $disk = Storage::disk(self::DISK);
        foreach ($keys as $key) {
            try {
                $disk->delete($key);
            } catch (\Throwable) {
                Log::warning('adc.application: could not delete document', ['key' => $key]);
            }
        }
    }

    /** @param list<string> $declarations */
    private function recordDeclarations(AreteCenterApplication $application, array $declarations, ?string $ip): void
    {
        $now = Carbon::now();
        foreach (array_unique($declarations) as $key) {
            AreteCenterApplicationDeclaration::create([
                'application_id' => $application->id,
                'declaration_key' => $key,
                'version' => AreteCenterDeclarations::VERSION,
                'accepted_at' => $now,
                'ip' => $ip,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(AreteCenterApplication $application): array
    {
        return $application->only([
            'status', 'centre_name', 'city', 'state', 'pincode', 'premises_sqft',
            'property_type', 'admin_notes', 'center_id',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $extra
     */
    private function audit(AreteCenterApplication $application, string $action, ?array $before, ?string $ip, ?User $actor = null, array $extra = []): void
    {
        AuditLog::create([
            'actor_id' => $actor !== null ? $actor->id : $application->distributor->user_id,
            'action' => $action,
            'subject_type' => 'arete_center_application',
            'subject_id' => $application->id,
            'details' => ['before' => $before, 'after' => $this->snapshot($application->refresh()), ...$extra],
            'ip' => $ip,
        ]);
    }

    private function notifyApplicant(AreteCenterApplication $application, object $notification): void
    {
        $user = $application->distributor->user;
        if ($user !== null) {
            $user->notify($notification);
        }
    }
}
