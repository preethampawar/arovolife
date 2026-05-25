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

    /**
     * Who to email when a distributor submits a line-change request — every
     * active user holding the 'admin' or 'admin-compliance' role.
     *
     * Deliberately separate from compliance() even though the query is
     * currently identical: line-change approval may gain its own role
     * (e.g. 'admin-operations') in a future phase, and keeping the methods
     * apart avoids a refactor at that point.
     *
     * @return Collection<int, User>
     */
    public static function lineChangeReviewers(): Collection
    {
        return User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'admin-compliance']))
            ->get();
    }
}
