<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Services\IdPhotoDecodeError;
use App\Modules\Identity\Services\IdPhotoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * Distributor ID-card photo — a self-uploaded passport-style image
 * surfaced on the dashboard and (later) on the printable ID card.
 *
 * The S3 dance, EXIF strip, and atomic-swap rollback are delegated to
 * {@see IdPhotoStorage} so the admin "replace this distributor's photo"
 * surface can reuse the same code path. The validation rules and the
 * audit-log writer stay here — they're surface-specific (`actor_id` is
 * the user themselves on self-upload; the admin endpoint uses the
 * admin's id + `admin.distributor.id_photo_updated`).
 *
 * Lifecycle invariants are documented on {@see IdPhotoStorage}.
 */
final class IdPhotoController extends Controller
{
    public function __construct(
        private readonly IdPhotoStorage $storage,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => [
                'required',
                'image',
                // WebP is excluded because the production GD build in the
                // app container is compiled WITHOUT --with-webp (verified
                // via gd_info()['WebP Support']). Accepting WebP at the
                // validator would still pass `image` but then trip the
                // EXIF-strip imagecreatefromstring() call, surfacing as a
                // 500. JPG + PNG cover ID photos from every common camera
                // and OS export path. If WebP is needed later, rebuild
                // the PHP container with libwebp and re-enable.
                'mimes:jpg,jpeg,png',
                'max:5120', // 5 MB — passport-style photos are typically < 1 MB
                'dimensions:min_width=200,min_height=200,max_width=4000,max_height=4000',
            ],
        ], [
            'photo.dimensions' => 'Please upload an image between 200×200 and 4000×4000 pixels.',
            'photo.mimes' => 'Only JPG and PNG photos are supported right now.',
        ]);

        $user = Auth::user();
        abort_if($user === null, 401);

        $file = $request->file('photo');

        try {
            $meta = $this->storage->replace($user, $file);
        } catch (IdPhotoDecodeError $e) {
            // Rare — usually a truncated upload, malformed header, or a
            // format GD on this host can't read. Surface as a clean 422.
            return back()->withErrors(['photo' => $e->getMessage()]);
        }

        AuditLog::create([
            'actor_id' => $user->id,
            'action' => 'profile.id_photo.updated',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'details' => [
                'old_key' => $meta['old_key'],
                'new_key' => $meta['new_key'],
                'size_bytes_uploaded' => $file->getSize(),
                'size_bytes_stored' => $meta['size_bytes_stored'],
                // Server-resolved MIME from magic bytes — not the
                // attacker-controllable Content-Type header.
                'mime' => $meta['mime'],
            ],
        ]);

        return back()->with('status', 'ID photo updated.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = Auth::user();
        abort_if($user === null, 401);

        $this->storage->delete($user, $user->id);

        return back()->with('status', 'ID photo removed.');
    }
}
