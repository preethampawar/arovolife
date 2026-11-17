<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Notifications\LifetimeAwardWithdrawnNotification;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\LifetimeAwardService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\ListFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;

/**
 * Lifetime Awards paid in cash (the client, 2026-11-10): the engine credits
 * each award with the Rank Bonus close and the monthly payout on the 8th pays
 * it. This page lists them and lets compliance withdraw one before it is paid.
 * There is nothing to hand over or deliver any more.
 */
final class AdminLifetimeAwardsController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $plan = app(CompensationPlanSettingsService::class);
        $rankNames = $plan->rankNames();

        /** @var array<string, string> $rankOptions */
        $rankOptions = array_combine(
            array_map(strval(...), array_keys($rankNames)),
            array_values($rankNames),
        );

        $filters = ListFilters::make($request, [
            FilterField::text('q', 'Search', 'ADN or name'),
            FilterField::select('status', 'Status', [
                LifetimeAwardMilestone::STATUS_CREDITED => 'Credited',
                LifetimeAwardMilestone::STATUS_CANCELLED => 'Withdrawn',
                LifetimeAwardMilestone::STATUS_DELIVERED => 'Delivered (merchandise, retired plan)',
            ], column: 'status', placeholder: 'All statuses'),
            FilterField::select('rank_number', 'Rank', $rankOptions, column: 'rank_number', placeholder: 'All ranks'),
            FilterField::month('month', 'Month'),
        ]);

        $query = LifetimeAwardMilestone::with(['distributor.user', 'walletCredit.sweptByBatch']);

        // No column mapping declared: adn lives on the distributor, full_name
        // on its user, so the term is applied here rather than through
        // ListFilters::apply().
        if (($search = $filters->value('q')) !== null) {
            $term = '%'.ListFilters::escapeLike($search).'%';

            $query->where(function ($sub) use ($term): void {
                $sub->whereHas('distributor', function ($d) use ($term): void {
                    $d->where('adn', 'like', $term)
                        ->orWhereHas('user', fn ($u) => $u->where('full_name', 'like', $term));
                });
            });
        }

        // triggered_month is stored as the month's start date, not `Y-m`, so
        // the month field maps no column and is applied here as a range.
        if (($month = $filters->value('month')) !== null) {
            $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
            $query->whereBetween('triggered_month', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()]);
        }

        $milestones = $filters->apply($query)
            ->orderByDesc('triggered_month')
            ->orderBy('rank_number')
            ->paginate(50)
            ->withQueryString();

        // Paid = the partner's line in the batch that swept the award was
        // transferred (the bank response came back paid) — the same test the
        // partner's Awards page uses. The milestone itself stays `credited`.
        $sweptPairs = $milestones->getCollection()
            ->filter(fn (LifetimeAwardMilestone $m): bool => $m->walletCredit?->swept_by_payout_batch_id !== null)
            ->map(fn (LifetimeAwardMilestone $m): array => [(int) $m->distributor_id, (int) $m->walletCredit->swept_by_payout_batch_id]);

        $paidKeys = $sweptPairs->isEmpty() ? [] : PayoutLineItem::query()
            ->where('status', PayoutLineItem::STATUS_TRANSFERRED)
            ->whereIn('payout_batch_id', $sweptPairs->pluck(1)->unique()->values()->all())
            ->whereIn('distributor_id', $sweptPairs->pluck(0)->unique()->values()->all())
            ->get(['payout_batch_id', 'distributor_id'])
            ->map(fn (PayoutLineItem $line): string => $line->payout_batch_id.':'.$line->distributor_id)
            ->flip()
            ->all();

        return view('admin.lifetime-awards.index', compact('milestones', 'rankNames', 'filters', 'paidKeys'));
    }

    /**
     * Withdraw an award before it is paid — a termination, a proven breach, or
     * a correction the rank run cannot make on its own. A pending award is
     * cancelled; a credited one the payout has not swept is reversed in the
     * wallet and cancelled. One already swept into a payout batch has been
     * paid and is refused (no clawback). The partner then sees it as
     * Withdrawn, with the reason and the route to Grievance Redressal. A
     * compliance decision, not a finance one: `compliance.discipline`, the
     * permission that freezes and terminates.
     */
    public function withdraw(int $id, Request $request): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);
        Gate::authorize('compliance.discipline');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $milestone = LifetimeAwardMilestone::findOrFail($id);

        $outcome = app(LifetimeAwardService::class)->withdraw(
            $milestone,
            $data['reason'],
            is_numeric($actorId = Auth::id()) ? (int) $actorId : null,
            'admin.lifetime_award.withdrawn',
            $request->ip(),
        );

        abort_if($outcome === LifetimeAwardService::ALREADY_PAID, 409, 'This award has already been paid.');

        if ($outcome !== LifetimeAwardService::WITHDRAWN) {
            return redirect()
                ->route('admin.lifetime-awards.index')
                ->with('error', 'Only an award not yet paid can be withdrawn.');
        }

        $user = Distributor::find($milestone->distributor_id)?->user;
        $user?->notify(new LifetimeAwardWithdrawnNotification(
            rankName: app(CompensationPlanSettingsService::class)->rankNames()[$milestone->rank_number] ?? 'Rank '.$milestone->rank_number,
            reason: $data['reason'],
        ));

        return redirect()
            ->route('admin.lifetime-awards.index')
            ->with('success', 'Award withdrawn. The distributor has been emailed the reason and the grievance route.');
    }
}
