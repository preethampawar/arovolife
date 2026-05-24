<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves who to email for admin-facing system notifications (new KYC,
 * resubmissions, etc). Fans out to every active user holding the
 * 'admin-compliance' role, falling back to 'admin' so a fresh install
 * with only the base admin still receives notifications.
 *
 * Per CLAUDE.md, compliance has the duty to approve/reject KYC. The
 * notification recipients track who can act on the queue.
 */
final class AdminNotificationRecipients
{
    /** @return Collection<int, User> */
    public static function compliance(): Collection
    {
        $users = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin-compliance', 'admin']))
            ->get();

        return $users;
    }
}
