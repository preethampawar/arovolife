<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns on `users`:
 *
 *  - `id_photo_path` — S3 object key (NOT a URL) of the user's
 *    self-uploaded passport-style photo. Used on the dashboard ID-card
 *    block and (later) on the printable ID card. The bucket is private,
 *    so the application generates a fresh short-lived signed URL on
 *    every render via `Storage::disk('s3')->temporaryUrl($key, ...)`.
 *    Storing the URL itself would not survive bucket rotation and
 *    would expire silently.
 *
 *  - `activated_at` — when the admin approved the user's KYC
 *    submission (see ApproveKycSubmission). This is the date that
 *    flips `users.status` from `pending` to `active`. Surfaced on the
 *    dashboard as "Activation Date". Distinct from
 *    `email_verified_at` (account creation), `password_set_at`
 *    (credential establishment), and the distributor's
 *    `effective_date` (registration submission moment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->string('id_photo_path', 255)->nullable()->after('email_verified_at');
            $table->dateTime('activated_at', 3)->nullable()->after('id_photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn(['id_photo_path', 'activated_at']);
        });
    }
};
