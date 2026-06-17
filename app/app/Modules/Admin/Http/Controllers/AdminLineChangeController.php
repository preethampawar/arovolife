<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Commerce\Services\DistributorCommerceActivity;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\ApproveLineChange;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasCommerceError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasDownlineError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeLockTimeoutError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeNotPendingError;
use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
use App\Modules\Genealogy\Services\RejectLineChange;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class AdminLineChangeController extends Controller
{
    public function __construct(
        private readonly ApproveLineChange $approve,
        private readonly RejectLineChange $reject,
        private readonly DistributorCommerceActivity $commerceActivity,
    ) {}

    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'decided' ? 'decided' : 'pending';

        $query = LineChangeRequest::query()
            ->with(['distributor.user', 'fromPlacementParent', 'toPlacementParent'])
            ->when($tab === 'pending',
                fn ($q) => $q->where('status', 'pending'),
                fn ($q) => $q->whereIn('status', ['approved', 'rejected', 'expired']),
            )
            ->orderByDesc('requested_at');

        $rows = $query->paginate(50)->withQueryString();

        $pendingCount = LineChangeRequest::query()->where('status', 'pending')->count();
        $decidedCount = LineChangeRequest::query()->whereIn('status', ['approved', 'rejected', 'expired'])->count();

        return view('admin.line-change.index', [
            'rows' => $rows,
            'currentTab' => $tab,
            'pendingCount' => $pendingCount,
            'decidedCount' => $decidedCount,
        ]);
    }

    public function show(int $id): View
    {
        $lcr = LineChangeRequest::query()
            ->with(['distributor.user', 'fromPlacementParent.user', 'toPlacementParent.user', 'reviewer'])
            ->findOrFail($id);

        // Which legs are free under the target parent (for the side picker).
        $taken = Distributor::query()
            ->where('placement_parent_id', $lcr->to_placement_parent_id)
            ->where('id', '!=', $lcr->to_placement_parent_id)
            ->whereIn('placement_side', ['L', 'R'])
            ->pluck('placement_side')
            ->all();
        $freeSides = array_values(array_diff(['L', 'R'], $taken));

        return view('admin.line-change.show', [
            'lcr' => $lcr,
            'freeSides' => $freeSides,
            'commerceActivity' => $this->commerceActivity->summary((int) $lcr->distributor_id),
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'chosen_side' => ['required', Rule::in(['L', 'R'])],
        ]);

        try {
            ($this->approve)($id, (int) Auth::id(), $validated['chosen_side']);
        } catch (LineChangePlacementSlotFullError) {
            return back()->withErrors(['chosen_side' => 'That leg is no longer free under the target parent. Pick the other leg or reject.']);
        } catch (LineChangeHasDownlineError) {
            return back()->withErrors(['chosen_side' => 'This distributor now has referrals in their tree, so their placement can no longer be moved. Reject this request instead.']);
        } catch (LineChangeHasCommerceError) {
            return back()->withErrors(['chosen_side' => 'This distributor now has order or BV activity, so their placement can no longer be moved. Reject this request instead.']);
        } catch (LineChangeLockTimeoutError) {
            return back()->withErrors(['chosen_side' => 'The tree is busy right now. Please try again in a moment.']);
        } catch (LineChangeNotPendingError) {
            return redirect()->route('admin.line-changes.index')
                ->with('status', 'That request was already decided by another admin.');
        }

        return redirect()->route('admin.line-changes.index')->with('status', 'Line change approved and placement moved.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'decision_note' => ['required', 'string', 'min:8', 'max:1024'],
        ]);

        try {
            ($this->reject)($id, (int) Auth::id(), $validated['decision_note']);
        } catch (LineChangeNotPendingError) {
            return redirect()->route('admin.line-changes.index')
                ->with('status', 'That request was already decided by another admin.');
        }

        return redirect()->route('admin.line-changes.index')->with('status', 'Line change rejected. The distributor has been emailed.');
    }
}
