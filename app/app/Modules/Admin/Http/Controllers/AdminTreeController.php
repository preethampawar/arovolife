<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Genealogy\Http\Controllers\TreeController;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\JsonResponse;
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
    public function show(Request $request, ?int $id = null): View|RedirectResponse
    {
        // Resolve the root distributor for this view.
        if ($id === null) {
            // Company root = a distributor whose sponsor_id == its own id.
            // If multiple match (synthetic seed roots in dev), pick the oldest.
            $self = Distributor::query()
                ->whereColumn('sponsor_id', 'id')
                ->orderBy('id')
                ->withTreeUser()
                ->first();

            if ($self === null) {
                return redirect()->route('admin.distributors.index')
                    ->with('status', 'No distributors yet — the tree is empty.');
            }
        } else {
            $self = Distributor::query()
                ->withTreeUser()
                ->findOrFail($id);
        }

        $countByDepth = DB::table('genealogy_closure')
            ->where('ancestor_id', $self->id)
            ->where('depth', '>', 0)
            ->groupBy('depth')
            ->select('depth', DB::raw('COUNT(*) AS n'))
            ->pluck('n', 'depth')
            ->all();
        $maxObservedDepth = empty($countByDepth) ? 0 : max(array_keys($countByDepth));

        // Default depth is dynamic: open at the node's actual subtree depth
        // rather than a fixed cap, so the view never claims more levels than
        // exist. min 1 keeps a childless node showing its own card.
        $requested = $request->query('levels');
        if ($requested === null || $requested === '') {
            $levels = max(1, $maxObservedDepth);
        } else {
            $levels = max(1, (int) $requested);
        }

        $descendantIds = DB::table('genealogy_closure')
            ->where('ancestor_id', $self->id)
            ->where('depth', '<=', $levels)
            ->pluck('descendant_id', 'descendant_id');

        $nodesById = Distributor::query()
            ->withTreeUser()
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

    /**
     * Global tree search for admins — no subtree restriction; an admin may
     * locate anyone by ADN, name, email or phone. Reuses the same matching
     * predicate as the distributor view (see TreeController::buildMatchQuery).
     * Returns the lowest-id match for determinism; admins are already trusted
     * and the route is throttled. Non-match returns only `{found:false}`.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['found' => false]);
        }

        $match = TreeController::buildMatchQuery($q)
            ->orderBy('distributors.id')
            ->select('distributors.id', 'distributors.adn', 'distributors.depth')
            ->first();

        return TreeController::matchResponse($match);
    }

    /**
     * Global typeahead suggestions for admins — no subtree restriction; up to 8
     * matches by ADN, name, email or phone, ordered by id for determinism.
     * Reuses the same matching predicate as the distributor view (partial
     * mode). Results carry name + adn + email + phone; queries are not logged.
     */
    public function suggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3) {
            return response()->json(['results' => []]);
        }

        $rows = TreeController::buildMatchQuery($q, partial: true)
            ->with('user:id,full_name,email,phone_e164')
            ->orderBy('distributors.id')
            ->select('distributors.id', 'distributors.adn', 'distributors.user_id')
            ->limit(8)
            ->get();

        return response()->json([
            'results' => $rows->map(fn ($d): array => [
                'adn' => $d->adn,
                'id' => (int) $d->id,
                'name' => $d->user?->full_name ?? '—',
                'email' => $d->user?->email,
                'phone' => $d->user?->phone_e164,
            ])->values()->all(),
        ]);
    }
}
