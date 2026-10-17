<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compensation\Events\LifetimeAwardReleased;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\LifetimeAwardReward;
use App\Modules\Compensation\Notifications\LifetimeAwardWithdrawnNotification;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
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
                LifetimeAwardMilestone::STATUS_PENDING => 'Pending',
                LifetimeAwardMilestone::STATUS_DELIVERED => 'Delivered',
                LifetimeAwardMilestone::STATUS_CANCELLED => 'Cancelled',
            ], column: 'status', placeholder: 'All statuses'),
            FilterField::select('rank_number', 'Rank', $rankOptions, column: 'rank_number', placeholder: 'All ranks'),
            FilterField::month('month', 'Month'),
        ]);

        $query = LifetimeAwardMilestone::with('distributor');

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
            ->orderBy('tranche')
            ->paginate(50)
            ->withQueryString();

        // Per-rank reward catalogue (budget + itemised rewards) so the admin can
        // see exactly what each milestone's rank earns.
        $catalog = [];
        foreach (range(1, 9) as $rank) {
            $catalog[$rank] = [
                'budget_paise' => $plan->lifetimeAwardBudgetPaise($rank),
                'rewards' => $plan->lifetimeAwardRewards($rank),
            ];
        }

        return view('admin.lifetime-awards.index', compact('milestones', 'rankNames', 'catalog', 'filters'));
    }

    /** Read-only-until-Edit catalogue editor for the per-rank reward items. */
    public function catalog(): View
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $plan = app(CompensationPlanSettingsService::class);
        $rewards = LifetimeAwardReward::orderBy('rank_number')->orderBy('sort_order')->get();
        $rankNames = $plan->rankNames();
        $budgets = [];
        foreach (range(1, 9) as $rank) {
            $budgets[$rank] = $plan->lifetimeAwardBudgetPaise($rank);
        }

        return view('admin.lifetime-awards.catalog', compact('rewards', 'rankNames', 'budgets'));
    }

    /** Update one reward item's text/worth. Audit-logged. */
    public function updateReward(int $id, Request $request): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $reward = LifetimeAwardReward::findOrFail($id);

        $data = $request->validate([
            'item' => ['required', 'string', 'max:255'],
            'worth_paise' => ['required', 'integer', 'min:0', 'max:100000000000'],
        ]);

        $before = ['item' => $reward->item, 'worth_paise' => $reward->worth_paise];
        $reward->update([
            'item' => $data['item'],
            'worth_paise' => (int) $data['worth_paise'],
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.lifetime_award.reward_updated',
            'subject_type' => 'lifetime_award_reward',
            'subject_id' => $reward->id,
            'before_hash' => AuditDigests::of($before),
            'after_hash' => AuditDigests::of(['item' => $reward->item, 'worth_paise' => $reward->worth_paise]),
            'details' => ['before' => $before, 'after' => ['item' => $reward->item, 'worth_paise' => $reward->worth_paise]],
            'ip' => $request->ip(),
        ]);

        return redirect()
            ->route('admin.lifetime-awards.catalog')
            ->with('success', 'Reward item updated.');
    }

    public function markDelivered(int $id, Request $request): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $milestone = LifetimeAwardMilestone::findOrFail($id);

        // Only a part still awaiting hand-over: a delivered one is done, and a
        // withdrawn one was a compliance decision this button must not undo.
        abort_unless($milestone->status === LifetimeAwardMilestone::STATUS_PENDING, 409);

        abort_unless($milestone->isReleasable(), 422, 'Award tranche is not yet releasable — the rank has not been qualified enough times.');

        // Merchandise only, never cash (client 2026-10-09): no disbursement
        // choice, no admin charge, no TDS, no wallet credit. The tranche's
        // worth is recorded as delivered.
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $before = AuditDigests::of($milestone);

        // Conditional on the row still being pending, so a Withdraw landing
        // between the check above and this write cannot be overwritten.
        $changed = LifetimeAwardMilestone::whereKey($milestone->id)
            ->where('status', LifetimeAwardMilestone::STATUS_PENDING)
            ->update([
                'status' => LifetimeAwardMilestone::STATUS_DELIVERED,
                'delivered_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);
        abort_if($changed === 0, 409);
        $milestone->refresh();

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.lifetime_award.delivered',
            'subject_type' => 'lifetime_award_milestone',
            'subject_id' => $milestone->id,
            'before_hash' => $before,
            'after_hash' => AuditDigests::of($milestone),
            'details' => [
                'distributor_id' => $milestone->distributor_id,
                'rank_number' => $milestone->rank_number,
                'tranche' => $milestone->tranche,
                'amount_paise' => $milestone->amount_paise,
            ],
            'ip' => $request->ip(),
        ]);

        event(new LifetimeAwardReleased(
            milestoneId: $milestone->id,
            distributorId: $milestone->distributor_id,
            rankNumber: $milestone->rank_number,
            actorId: Auth::id(),
        ));

        return redirect()
            ->route('admin.lifetime-awards.index')
            ->with('success', 'Lifetime award marked as delivered.');
    }

    /**
     * Withhold a pending award part — a termination, a proven breach, or a
     * correction the rank run cannot make on its own (it only removes a part a
     * rebuilt month no longer earns). The distributor then sees the part as
     * Withdrawn, with the route to Grievance Redressal. A compliance decision,
     * not a finance one: `compliance.discipline`, the permission that freezes
     * and terminates. A delivered part is never withdrawn — the merchandise has
     * been handed over.
     */
    public function withdraw(int $id, Request $request): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);
        Gate::authorize('compliance.discipline');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $milestone = LifetimeAwardMilestone::findOrFail($id);

        if ($milestone->status !== LifetimeAwardMilestone::STATUS_PENDING) {
            return redirect()
                ->route('admin.lifetime-awards.index')
                ->with('error', 'Only an award part still awaiting hand-over can be withdrawn.');
        }

        $before = AuditDigests::of($milestone);

        // Conditional on the row still being pending: a Mark delivered landing
        // first wins, and this refuses instead of withdrawing handed-over goods.
        $changed = LifetimeAwardMilestone::whereKey($milestone->id)
            ->where('status', LifetimeAwardMilestone::STATUS_PENDING)
            ->update([
                'status' => LifetimeAwardMilestone::STATUS_CANCELLED,
                'notes' => $data['reason'],
            ]);

        if ($changed === 0) {
            return redirect()
                ->route('admin.lifetime-awards.index')
                ->with('error', 'Only an award part still awaiting hand-over can be withdrawn.');
        }

        $milestone->refresh();

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.lifetime_award.withdrawn',
            'subject_type' => 'lifetime_award_milestone',
            'subject_id' => $milestone->id,
            'before_hash' => $before,
            'after_hash' => AuditDigests::of($milestone),
            'details' => [
                'distributor_id' => $milestone->distributor_id,
                'rank_number' => $milestone->rank_number,
                'tranche' => $milestone->tranche,
                'amount_paise' => $milestone->amount_paise,
                'reason' => $data['reason'],
            ],
            'ip' => $request->ip(),
        ]);

        $user = Distributor::find($milestone->distributor_id)?->user;
        $user?->notify(new LifetimeAwardWithdrawnNotification(
            rankName: app(CompensationPlanSettingsService::class)->rankNames()[$milestone->rank_number] ?? 'Rank '.$milestone->rank_number,
            part: $milestone->trancheLetter(),
            reason: $data['reason'],
        ));

        return redirect()
            ->route('admin.lifetime-awards.index')
            ->with('success', 'Award part withdrawn. The distributor has been emailed the reason and the grievance route.');
    }
}
