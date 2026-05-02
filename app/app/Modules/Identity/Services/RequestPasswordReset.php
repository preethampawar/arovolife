<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\PasswordResetNotification;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Creates / refreshes a password-reset token for the given email and emails
 * the user a signed reset link. Designed not to leak whether an email is
 * registered — the controller calls this and shows the same generic
 * confirmation regardless of outcome.
 */
final class RequestPasswordReset
{
    public const TOKEN_TTL_MINUTES = 60;

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(string $email): void
    {
        $email = strtolower(trim($email));

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            // Silent no-op — the controller above us issues a generic
            // success message regardless, so an attacker can't enumerate
            // valid emails by timing or response shape.
            return;
        }

        // Spouse accounts that haven't activated yet are NOT eligible for
        // password reset — they should use the activation magic link they
        // already received. Avoids two competing flows for the same account.
        if ($user->password_set_at === null) {
            return;
        }

        // Generate a 64-char hex URL token; store sha256(token) in the DB.
        // The link sent to the user contains the raw token; verification
        // re-hashes and compares.
        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken);

        $this->db->table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token_hash' => $hash, 'created_at' => Carbon::now()],
        );

        $resetUrl = url(route('password.reset.show', ['token' => $rawToken], false))
            .'?email='.urlencode($email);

        Notification::send($user, new PasswordResetNotification(
            resetUrl: $resetUrl,
            expiresMinutes: self::TOKEN_TTL_MINUTES,
        ));
    }
}
