<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\DistributorRequest;
use App\Modules\Identity\Models\DistributorRequestDocument;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\DistributorRequestDecidedNotification;
use App\Modules\Identity\Notifications\DistributorRequestSubmittedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Lifecycle of a distributor request (name / DOB correction, name change,
 * membership transfer, ID cancellation).
 *
 *   submitted → under_review → approved | rejected
 *
 * Every transition is audit-logged with before/after and the distributor is
 * notified. Approving a name or DOB request is the only step that touches
 * the distributor's record; it writes a second audit entry with the field's
 * before/after so the identity change is traceable on its own.
 */
final class DistributorRequestService
{
    public const string DISK = 'distributor-requests';

    /** Extension by validated MIME type — never by the user-supplied filename. */
    private const array EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * @param  array<string, mixed>  $details  validated type-specific answers
     * @param  array<string, list<UploadedFile>>  $files  document type => files
     */
    public function submit(Distributor $distributor, string $type, array $details, string $reason, array $files, ?string $ip): DistributorRequest
    {
        if (! array_key_exists($type, DistributorRequest::TYPES)) {
            throw new InvalidArgumentException('Unknown request type.');
        }

        if (DistributorRequest::query()->where('distributor_id', $distributor->id)->where('type', $type)->open()->exists()) {
            throw new InvalidArgumentException('You already have a '.strtolower(DistributorRequest::TYPES[$type]['label']).' request in progress.');
        }

        $uploaded = [];
        try {
            $request = DB::transaction(function () use ($distributor, $type, $details, $reason, $files, $ip, &$uploaded): DistributorRequest {
                $request = DistributorRequest::create([
                    'request_no' => $this->nextRequestNo(),
                    'distributor_id' => $distributor->id,
                    'type' => $type,
                    'status' => DistributorRequest::STATUS_SUBMITTED,
                    'details' => $details,
                    'reason' => $reason,
                    'snapshot_before' => $this->recordSnapshot($distributor),
                    'submitted_at' => Carbon::now(),
                ]);

                $uploaded = $this->storeDocuments($request, $files);
                $this->audit($request, 'distributor_request.submitted', null, $ip);

                return $request;
            });
        } catch (\Throwable $e) {
            // A rolled-back request must not leave identity documents on the
            // disk that no row points at (DPDP §8(7)).
            $this->deleteKeys($uploaded);
            throw $e;
        }

        $this->notify($request, new DistributorRequestSubmittedNotification($request->request_no, $request->typeLabel()));

        return $request;
    }

    public function markUnderReview(DistributorRequest $request, User $admin, ?string $ip): DistributorRequest
    {
        $this->assertTransition($request, [DistributorRequest::STATUS_SUBMITTED]);

        $before = $this->snapshot($request);
        $request->update([
            'status' => DistributorRequest::STATUS_UNDER_REVIEW,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => Carbon::now(),
        ]);
        $this->audit($request, 'distributor_request.under_review', $before, $ip, $admin);

        return $request;
    }

    public function reject(DistributorRequest $request, User $admin, string $reason, ?string $ip): DistributorRequest
    {
        $this->assertTransition($request, DistributorRequest::OPEN_STATUSES);

        $before = $this->snapshot($request);
        $request->update([
            'status' => DistributorRequest::STATUS_REJECTED,
            'admin_notes' => $reason,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => Carbon::now(),
        ]);
        $this->audit($request, 'distributor_request.rejected', $before, $ip, $admin);
        $this->notify($request, new DistributorRequestDecidedNotification($request->request_no, $request->typeLabel(), $request->status, $reason, false));

        return $request;
    }

    /**
     * Approve. Name and DOB requests are applied to the record here; transfer
     * and cancellation requests are acknowledged and carried out by compliance
     * with the existing account tools (they touch one-PAN-one-ADN, KYC and
     * the genealogy, so they are never automatic).
     */
    public function approve(DistributorRequest $request, User $admin, ?string $note, ?string $ip): DistributorRequest
    {
        $this->assertTransition($request, DistributorRequest::OPEN_STATUSES);

        DB::transaction(function () use ($request, $admin, $note, $ip): void {
            $before = $this->snapshot($request);
            $applied = false;

            if ($request->appliesOnApproval()) {
                $this->applyToRecord($request, $admin, $ip);
                $applied = true;
            }

            $request->update([
                'status' => DistributorRequest::STATUS_APPROVED,
                'admin_notes' => $note,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => Carbon::now(),
                'applied_at' => $applied ? Carbon::now() : null,
            ]);
            $this->audit($request, 'distributor_request.approved', $before, $ip, $admin, ['applied' => $applied]);
        });

        // Only after commit: a queued email must never describe a change that
        // was rolled back.
        $this->notify($request, new DistributorRequestDecidedNotification($request->request_no, $request->typeLabel(), $request->status, $note, $request->appliesOnApproval()));

        return $request->refresh();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function applyToRecord(DistributorRequest $request, User $admin, ?string $ip): void
    {
        $user = $request->distributor->user;
        if ($user === null) {
            throw new InvalidArgumentException('This distributor has no login record to update.');
        }

        $details = $request->details;
        [$field, $value] = match ($request->type) {
            DistributorRequest::TYPE_NAME_CORRECTION, DistributorRequest::TYPE_NAME_CHANGE => ['full_name', (string) ($details['requested_full_name'] ?? '')],
            DistributorRequest::TYPE_DOB_CORRECTION => ['date_of_birth', (string) ($details['requested_date_of_birth'] ?? '')],
            default => throw new InvalidArgumentException('This request type is not applied automatically.'),
        };

        if ($value === '') {
            throw new InvalidArgumentException('The requested value is missing.');
        }

        $old = $user->{$field};
        $user->forceFill([$field => $value])->save();

        AuditLog::create([
            'actor_id' => $admin->id,
            'action' => 'profile.identity_corrected',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'details' => [
                'before' => [$field => $old],
                'after' => [$field => $value],
                'request_id' => $request->id,
                'request_no' => $request->request_no,
                'request_type' => $request->type,
            ],
            'ip' => $ip,
        ]);
    }

    /** @param list<string> $allowed */
    private function assertTransition(DistributorRequest $request, array $allowed): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw new InvalidArgumentException('This request is '.strtolower($request->statusLabel()).' and cannot be changed that way.');
        }
    }

    private function nextRequestNo(): string
    {
        do {
            $no = 'DR-'.Carbon::now()->format('ym').'-'.Str::upper(Str::random(5));
        } while (DistributorRequest::query()->where('request_no', $no)->exists());

        return $no;
    }

    /** @return array<string, mixed> */
    private function recordSnapshot(Distributor $distributor): array
    {
        $user = $distributor->user;

        return [
            'full_name' => $user?->full_name,
            'date_of_birth' => $user?->date_of_birth,
            'adn' => $distributor->adn,
        ];
    }

    /**
     * @param  array<string, list<UploadedFile>>  $files
     * @return list<string> storage keys written
     */
    private function storeDocuments(DistributorRequest $request, array $files): array
    {
        $disk = Storage::disk(self::DISK);
        $written = [];

        foreach ($files as $type => $uploads) {
            foreach ($uploads as $file) {
                $sha256 = hash_file('sha256', $file->getRealPath()) ?: '';
                $mime = (string) $file->getMimeType();
                $extension = self::EXTENSIONS[$mime] ?? 'bin';
                $key = "request_{$request->id}/{$type}_".substr($sha256, 0, 12).".{$extension}";

                $disk->putFileAs(dirname($key), $file, basename($key));
                $written[] = $key;

                DistributorRequestDocument::create([
                    'request_id' => $request->id,
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
                Log::warning('distributor_request: could not delete document', ['key' => $key]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(DistributorRequest $request): array
    {
        return $request->only(['status', 'type', 'details', 'admin_notes', 'applied_at']);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $extra
     */
    private function audit(DistributorRequest $request, string $action, ?array $before, ?string $ip, ?User $actor = null, array $extra = []): void
    {
        AuditLog::create([
            'actor_id' => $actor !== null ? $actor->id : $request->distributor->user_id,
            'action' => $action,
            'subject_type' => 'distributor_request',
            'subject_id' => $request->id,
            'details' => ['before' => $before, 'after' => $this->snapshot($request->refresh()), 'request_no' => $request->request_no, ...$extra],
            'ip' => $ip,
        ]);
    }

    private function notify(DistributorRequest $request, object $notification): void
    {
        $user = $request->distributor->user;
        if ($user !== null) {
            $user->notify($notification);
        }
    }
}
