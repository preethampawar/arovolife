<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Compliance;

use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ethics-and-privacy half of `GrievanceThirdPartyOverdueProvider` (R-100),
 * on the same terms as `GrievanceSensitiveSlaDueOrBreachedProvider`: the same
 * 15-day condition, the complementary half of the categories, and
 * `compliance.discipline` instead of `grievance.handle` so the registry hands
 * it only to the desk that may open the tickets underneath.
 */
final class GrievanceSensitiveThirdPartyOverdueProvider extends GrievanceThirdPartyOverdueProvider
{
    public function key(): string
    {
        return 'grievance.sensitive_third_party_overdue';
    }

    public function label(): string
    {
        return 'Ethics & privacy third-party grievances overdue an update';
    }

    public function description(): string
    {
        return 'Third-party-dependent ethics, conduct and privacy grievances with no progress update to the complainant in 15 days. Visible to compliance only.';
    }

    public function permission(): string
    {
        return 'compliance.discipline';
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    protected function categoryScope(Builder $query): void
    {
        $query->whereIn('tickets.category', TicketCategory::sensitiveValues());
    }
}
