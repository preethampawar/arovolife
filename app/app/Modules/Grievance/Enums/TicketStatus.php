<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Enums;

/**
 * Lifecycle of a grievance ticket.
 *
 * `Open` → `Acknowledged` (within 48h, policy §2) → `InProgress` →
 * `Resolved` → `Closed`. A ticket may be resolved without ever passing
 * through `InProgress` when the first response settles it.
 */
enum TicketStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Acknowledged',
            self::InProgress => 'In progress',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /**
     * A settled ticket no longer accrues SLA time.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Resolved, self::Closed], true);
    }

    /**
     * Statuses a complainant may still add a reply to.
     */
    public function acceptsComplainantReply(): bool
    {
        return $this !== self::Closed;
    }

    /**
     * Tailwind chip classes, matching the status-dot vocabulary used across
     * the admin console.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-rose-100 text-rose-800',
            self::Acknowledged => 'bg-amber-100 text-amber-800',
            self::InProgress => 'bg-sky-100 text-sky-800',
            self::Resolved => 'bg-emerald-100 text-emerald-800',
            self::Closed => 'bg-slate-200 text-slate-700',
        };
    }
}
