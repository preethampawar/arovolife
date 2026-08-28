<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compensation\Events\LifetimeAwardReleased;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\LifetimeAwardReward;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;

final class AdminLifetimeAwardsController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $milestones = LifetimeAwardMilestone::with('distributor')
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->input('status')),
            )
            ->orderByDesc('triggered_month')
            ->orderBy('rank_number')
            ->paginate(50)
            ->withQueryString();

        $plan = app(CompensationPlanSettingsService::class);
        $rankNames = $plan->rankNames();

        // Per-rank reward catalogue (budget + itemised rewards) so the admin can
        // see exactly what each milestone's rank earns.
        $catalog = [];
        foreach (range(1, 9) as $rank) {
            $catalog[$rank] = [
                'budget_paise' => $plan->lifetimeAwardBudgetPaise($rank),
                'rewards' => $plan->lifetimeAwardRewards($rank),
            ];
        }

        return view('admin.lifetime-awards.index', compact('milestones', 'rankNames', 'catalog'));
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

        abort_if($milestone->status === LifetimeAwardMilestone::STATUS_DELIVERED, 409);

        abort_unless($milestone->isReleasable(), 422, 'Award is not yet releasable — re-qualification threshold not met.');

        $data = $request->validate([
            'disbursement_type' => ['required', 'in:goods,cash'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $plan = app(CompensationPlanSettingsService::class);
        $grossPaise = $plan->lifetimeAwardBudgetPaise($milestone->rank_number);

        $adminChargePaise = 0;
        $tdsPaise = 0;
        $netPaise = $grossPaise;

        if ($data['disbursement_type'] === LifetimeAwardMilestone::DISBURSEMENT_CASH && $grossPaise > 0) {
            // Group C admin charge: 3% or monthly cap (₹25,000), whichever is lower.
            $rateBp = $plan->adminChargeRateBp();
            $capPaise = $plan->adminChargeMonthlyCapPaise();
            $adminChargePaise = (int) min((int) round($grossPaise * $rateBp / 10000), $capPaise);
            $afterAdmin = $grossPaise - $adminChargePaise;
            $tdsPaise = (int) round($afterAdmin * 500 / 10000); // 5% TDS
            $netPaise = $afterAdmin - $tdsPaise;

            app(WalletService::class)->credit(
                distributorId: $milestone->distributor_id,
                amountPaise: $netPaise,
                // `awards_credit`, not `lifetime_award_cash`: the latter is not in
                // the wallet_ledger_entries type enum and never was, so on MySQL
                // this insert fails outright and the award is never paid. Found
                // by the hard-rule-2 credit registry, 2026-08-17.
                type: 'awards_credit',
                referenceId: $milestone->id,
                referenceType: 'lifetime_award_milestone',
                memo: 'Lifetime Award Cash — Rank '.$milestone->rank_number,
            );
        }

        $milestone->update([
            'status' => LifetimeAwardMilestone::STATUS_DELIVERED,
            'disbursement_type' => $data['disbursement_type'],
            'gross_paise' => $grossPaise ?: null,
            'admin_charge_paise' => $adminChargePaise,
            'tds_paise' => $tdsPaise,
            'net_paise' => $netPaise ?: null,
            'delivered_at' => now(),
            'notes' => $data['notes'],
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.lifetime_award.delivered',
            'subject_type' => 'lifetime_award_milestone',
            'subject_id' => $milestone->id,
            'details' => [
                'distributor_id' => $milestone->distributor_id,
                'rank_number' => $milestone->rank_number,
                'disbursement_type' => $data['disbursement_type'],
                'gross_paise' => $grossPaise,
                'admin_charge_paise' => $adminChargePaise,
                'tds_paise' => $tdsPaise,
                'net_paise' => $netPaise,
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
}
