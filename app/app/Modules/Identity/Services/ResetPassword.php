<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\Exceptions\InvalidResetTokenError;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Verifies a password-reset token + email pair, updates the user's password,
 * stamps password_set_at, deletes the consumed token row. All in one
 * transaction so a partial failure can't leave a user with a stale password
 * and a "spent" token.
 */
final class ResetPassword
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(string $email, string $rawToken, string $newPassword): User
    {
        $email = strtolower(trim($email));
        $hash = hash('sha256', $rawToken);
        $expiry = RequestPasswordReset::TOKEN_TTL_MINUTES;

        // Pre-flight: lookup + token-match + expiry check OUTSIDE any
        // transaction. The expiry-cleanup delete needs to commit even when
        // we throw — placing it inside the transaction would have the
        // throw roll the cleanup back, leaving the stale row for a retry.
        $row = $this->db->table('password_reset_tokens')->where('email', $email)->first();
        if ($row === null) {
            throw new InvalidResetTokenError('No active reset request for this email.');
        }
        if (! hash_equals((string) $row->token_hash, $hash)) {
            throw new InvalidResetTokenError('Reset link is invalid.');
        }
        if (Carbon::parse($row->created_at)->addMinutes($expiry)->isPast()) {
            $this->db->table('password_reset_tokens')->where('email', $email)->delete();
            throw new InvalidResetTokenError('Reset link has expired. Please request a new one.');
        }

        // Apply the reset under a row lock so concurrent submissions of the
        // same valid token can't both flip the password.
        return $this->db->connection()->transaction(function () use ($email, $hash, $newPassword): User {
            $row = $this->db->table('password_reset_tokens')
                ->where('email', $email)
                ->lockForUpdate()
                ->first();
            if ($row === null || ! hash_equals((string) $row->token_hash, $hash)) {
                throw new InvalidResetTokenError('Reset link is invalid.');
            }

            $user = User::query()->where('email', $email)->firstOrFail();

            $user->update([
                'password_hash' => Hash::make($newPassword),
                'password_set_at' => Carbon::now(),
            ]);

            $this->db->table('password_reset_tokens')->where('email', $email)->delete();

            AuditLog::create([
                'actor_id' => $user->id,
                'action' => 'identity.password.reset',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'details' => ['email' => $email],
            ]);

            return $user;
        });
    }
}
