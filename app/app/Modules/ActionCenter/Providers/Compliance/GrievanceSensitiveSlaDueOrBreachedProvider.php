<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Compliance;

use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ethics-and-privacy half of `GrievanceSlaDueOrBreachedProvider` (R-100).
 *
 * The two rows are the same statutory condition over disjoint halves of the
 * category vocabulary, which is why this extends its sibling rather than
 * restating the clock: whatever "past an SLA clock" means, it means the same
 * thing on both, and a ticket is counted on exactly one of them.
 *
 * The split exists because a count is a disclosure. "Ethics & fraud — 3" tells
 * an operations officer that ethics complaints exist and roughly when, which is
 * the same thing the grievance queue and the monthly report already refuse to
 * tell them (`AdminGrievanceController::applyVisibility()`,
 * `AdminGrievanceReportController::hiddenCategoriesFor()`). Hiding them from
 * everyone was the other option and it was worse: a breached **statutory**
 * clock on an ethics grievance would then appear on no screen at all. So the
 * clocks are not filtered away, they are moved to the desk that may read them.
 *
 * Nothing here needs to test the viewer. `ActionCenterRegistry::for()` already
 * drops any provider whose `permission()` the viewer does not hold, so naming
 * `compliance.discipline` below is the whole access rule — the same permission
 * `TicketCategory::sensitiveValues()` is gated on everywhere else.
 */
final class GrievanceSensitiveSlaDueOrBreachedProvider extends GrievanceSlaDueOrBreachedProvider
{
    public function key(): string
    {
        return 'grievance.sensitive_sla_due_or_breached';
    }

    public function label(): string
    {
        return 'Ethics & privacy grievances past an SLA clock';
    }

    public function description(): string
    {
        return 'Unsettled ethics, conduct and privacy grievances past the acknowledgement, first-response or resolution clock. Visible to compliance only.';
    }

    public function permission(): string
    {
        return 'compliance.discipline';
    }

    /**
     * The exact complement of the parent's scope, so every ticket past a clock
     * is counted once across the two rows and never twice.
     *
     * @param  Builder<Ticket>  $query
     */
    protected function categoryScope(Builder $query): void
    {
        $query->whereIn('tickets.category', TicketCategory::sensitiveValues());
    }
}
