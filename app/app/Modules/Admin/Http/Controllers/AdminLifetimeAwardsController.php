<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
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

        $rankNames = app(CompensationPlanSettingsService::class)->rankNames();

        return view('admin.lifetime-awards.index', compact('milestones', 'rankNames'));
    }

    public function markDelivered(int $id, Request $request): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $milestone = LifetimeAwardMilestone::findOrFail($id);

        abort_if($milestone->status === LifetimeAwardMilestone::STATUS_DELIVERED, 409);

        $milestone->update([
            'status' => LifetimeAwardMilestone::STATUS_DELIVERED,
            'delivered_at' => now(),
            'notes' => $request->input('notes'),
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.lifetime_award.delivered',
            'subject_type' => 'lifetime_award_milestone',
            'subject_id' => $milestone->id,
            'details' => [
                'distributor_id' => $milestone->distributor_id,
                'rank_number' => $milestone->rank_number,
                'award_description' => $milestone->award_description,
            ],
            'ip' => $request->ip(),
        ]);

        return redirect()
            ->route('admin.lifetime-awards.index')
            ->with('success', 'Lifetime award marked as delivered.');
    }
}
