<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Http\Controllers\IdPhotoController;
use App\Modules\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared storage helper for the distributor ID-card photo.
 *
 * Originally inlined in {@see IdPhotoController}
 * but lifted out so the admin "replace this distributor's ID photo" surface
 * can reuse the same EXIF-strip + atomic-swap behaviour without duplicating
 * the S3 dance (and the easy-to-miss orphan cleanup on DB failure).
 *
 * Lifecycle invariants (mirrored from IdPhotoController):
 *   - One ID photo per user at any time.
 *   - Re-uploading replaces atomically: write new S3 object → DB update →
 *     delete old. If the DB update fails after S3 write, the new object is
 *     rolled back. If the post-update delete of the old key fails, the row
 *     is correct but an orphan remains; the Phase-2 janitor reconciles.
 *   - The audit log entry is always written by the CALLER (admin vs
 *     self-upload use different action names + actor semantics).
 *
 * Storage key: `user_{userId}/id-photo/{uuid}.{ext}` on the `s3` disk.
 */
final class IdPhotoStorage
{
    /**
     * Replace the given user's ID photo with the uploaded file.
     *
     * @return array{old_key: string|null, new_key: string, size_bytes_stored: int, mime: string|null}
     */
    public function replace(User $user, UploadedFile $file): array
    {
        // extension() resolves via Symfony's MIME map. Normalise jpeg → jpg
        // so the stored key has a single canonical suffix (the same reason
        // IdPhotoController normalised — downstream PDF render shouldn't
        // have to handle both).
        $ext = strtolower($file->extension() ?: 'jpg');
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $newKey = sprintf('user_%d/id-photo/%s.%s', $user->id, Str::uuid()->toString(), $ext);

        $cleaned = $this->stripExif($file, $ext);
        if ($cleaned === null) {
            throw new IdPhotoDecodeError('We could not process that photo. Please try a different JPG or PNG file.');
        }

        Storage::disk('s3')->put($newKey, $cleaned, ['visibility' => 'private']);

        $oldKey = $user->id_photo_path;

        try {
            $user->update(['id_photo_path' => $newKey]);
        } catch (\Throwable $e) {
            // Orphan rollback — DB never saw the new key, so neither
            // should S3. Best effort; rethrow the original.
            Storage::disk('s3')->delete($newKey);

            throw $e;
        }

        // Best-effort delete of the previous object — failure leaves a
        // single orphan but the DB is the source of truth; the daily
        // janitor (TODO Phase 2) reconciles.
        if ($oldKey !== null && $oldKey !== $newKey) {
            try {
                Storage::disk('s3')->delete($oldKey);
            } catch (\Throwable) {
                // Swallow — janitor will reconcile.
            }
        }

        return [
            'old_key' => $oldKey,
            'new_key' => $newKey,
            'size_bytes_stored' => strlen($cleaned),
            'mime' => $file->getMimeType(),
        ];
    }

    /**
     * Delete the user's current ID photo (no-op if none).
     */
    public function delete(User $user, int $actorId, string $action = 'profile.id_photo.deleted'): bool
    {
        $oldKey = $user->id_photo_path;
        if ($oldKey === null) {
            return false;
        }

        $user->update(['id_photo_path' => null]);

        try {
            Storage::disk('s3')->delete($oldKey);
        } catch (\Throwable) {
            // Janitor.
        }

        AuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'details' => ['old_key' => $oldKey],
        ]);

        return true;
    }

    /**
     * Decode → re-encode via GD to drop all EXIF metadata.
     *
     * GD does not write EXIF on output, so the simplest "strip EXIF"
     * implementation is decode → re-encode. Mirrors the original
     * IdPhotoController helper bit-for-bit so behaviour is identical
     * across self-upload and admin-upload paths.
     */
    private function stripExif(UploadedFile $file, string $ext): ?string
    {
        $bytes = (string) $file->get();
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        try {
            ob_start();
            match ($ext) {
                'png' => imagepng($image, null, 6),
                default => imagejpeg($image, null, 90),
            };
            $out = ob_get_clean();

            return is_string($out) ? $out : null;
        } finally {
            imagedestroy($image);
        }
    }
}
