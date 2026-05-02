<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin-side genealogy view. Two modes:
 *
 *   GET /admin/tree           → company root (the self-referencing top-most distributor)
 *   GET /admin/tree/{id}      → tree rooted at the chosen distributor
 *
 * Reuses the same `tree._content` partial as the distributor view; the only
 * differences are the layout wrapper (admin sidebar vs. public topnav) and a
 * small admin-context header above the tree showing whose tree is being
 * inspected and a button row to impersonate / view profile.
 */
final class AdminTreeController extends Controller
{
    private const DEFAULT_DEPTH = 4;

    public function show(Request $request, ?int $id = null): View|RedirectResponse
    {
        // Resolve the root distributor for this view.
        if ($id === null) {
            // Company root = a distributor whose sponsor_id == its own id.
            // If multiple match (synthetic seed roots in dev), pick the oldest.
            $self = Distributor::query()
                ->whereColumn('sponsor_id', 'id')
                ->orderBy('id')
                ->with(['user:id,full_name,status'])
                ->first();

            if ($self === null) {
                return redirect()->route('admin.distributors.index')
                    ->with('status', 'No distributors yet — the tree is empty.');
            }
        } else {
            $self = Distributor::query()
                ->with(['user:id,full_name,status'])
                ->findOrFail($id);
        }

        $levels = (int) $request->query('levels', self::DEFAULT_DEPTH);
        if ($levels < 1) {
            $levels = self::DEFAULT_DEPTH;
        }

        $countByDepth = DB::table('genealogy_closure')
            ->where('ancestor_id', $self->id)
            ->where('depth', '>', 0)
            ->groupBy('depth')
            ->select('depth', DB::raw('COUNT(*) AS n'))
            ->pluck('n', 'depth')
            ->all();
        $maxObservedDepth = empty($countByDepth) ? 0 : max(array_keys($countByDepth));

        $descendantIds = DB::table('genealogy_closure')
            ->where('ancestor_id', $self->id)
            ->where('depth', '<=', $levels)
            ->pluck('descendant_id', 'descendant_id');

        $nodesById = Distributor::query()
            ->with(['user:id,full_name,status'])
            ->whereIn('id', $descendantIds)
            ->get()
            ->keyBy('id');

        $childByParentSide = [];
        foreach ($nodesById as $node) {
            if ($node->id === $self->id || $node->placement_side === null) {
                continue;
            }
            $childByParentSide[$node->placement_parent_id][$node->placement_side] = $node;
        }

        return view('admin.tree.show', [
            'self' => $self,
            'childByParentSide' => $childByParentSide,
            'maxDepth' => $levels,
            'totalDescendants' => array_sum($countByDepth),
            'maxObservedDepth' => $maxObservedDepth,
            'isCompanyRoot' => $id === null,
        ]);
    }
}
