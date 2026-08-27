<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Policies;

use App\Modules\Grievance\Models\Ticket;
use App\Modules\Identity\Models\User;

/**
 * Who may read and work a grievance.
 *
 * Route middleware already keeps `admin-finance` out of the queue entirely.
 * This policy handles the two things middleware cannot see, because both
 * depend on the ticket:
 *
 *  1. **Sensitive categories.** Ethics and privacy complaints are readable
 *     only by the compliance side. Opening those to the whole console would
 *     undo the routing rule that keeps a bribe allegation away from the desk
 *     it might be about — bypassing front line changes who is *notified*, not
 *     who can open the record.
 *  2. **Conflict of interest.** Nobody works a grievance filed by their own
 *     distributor account. Self-resolution is the failure mode a grievance
 *     process exists to prevent.
 *
 * Note that `developer` and `admin` are super staff and pass every gate via
 * `Gate::before`. That is deliberate and unchanged here: this policy scopes the
 * three specialised roles, not the two that already hold everything.
 */
final class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        return $this->mayTouch($user, $ticket);
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $this->mayTouch($user, $ticket);
    }

    private function mayTouch(User $user, Ticket $ticket): bool
    {
        if ($this->isOwnComplaint($user, $ticket)) {
            return false;
        }

        if ($ticket->category->bypassesFrontLine()) {
            return $user->can('compliance.discipline');
        }

        return $user->can('grievance.handle');
    }

    /**
     * A staff member who also holds a distributor account cannot handle their
     * own complaint.
     */
    private function isOwnComplaint(User $user, Ticket $ticket): bool
    {
        if ($ticket->distributor_id === null) {
            return false;
        }

        return $user->distributor?->id === $ticket->distributor_id;
    }
}
