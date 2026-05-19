<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Http\Controllers;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Distributor-facing genealogy. By default the tree is rooted at the
 * auth'd user. An optional /tree/{adn} segment can re-root the view at
 * any descendant — but only descendants of the auth'd user; foreign
 * ADNs return a redirect with a flash message rather than expose a
 * neighbour's data.
 */
final class TreeController extends Controller
{
    /** Default depth shown when the user hasn't picked a value. */
    private const DEFAULT_DEPTH = 4;

    public function binary(Request $request, ?string $adn = null): View|RedirectResponse
    {
        $authDistributor = Auth::user()?->distributor;
        if ($authDistributor === null) {
            return redirect()->route('dashboard');
        }

        $self = $authDistributor;

        if ($adn !== null) {
            // Re-root at the requested ADN, but ONLY if it's the auth user's
            // self-row or a descendant. Anything else is treated as a
            // tampered URL and silently bounces back to /tree.
            $candidate = Distributor::query()
                ->with(['user:id,full_name'])
                ->where('adn', $adn)
                ->first();

            if ($candidate === null) {
                return redirect()->route('tree.binary')->with('status', 'That distributor was not found in your tree.');
            }

            $inMySubtree = (int) $candidate->id === (int) $authDistributor->id
                || DB::table('genealogy_closure')
                    ->where('ancestor_id', $authDistributor->id)
                    ->where('descendant_id', $candidate->id)
                    ->where('depth', '>', 0)
                    ->exists();

            if (! $inMySubtree) {
                return redirect()->route('tree.binary')->with('status', 'You can only view distributors in your own downline.');
            }

            $self = $candidate;
        }

        // Inject the auth'd user back into the distributor's `user` relation
        // so the tree partial can show the self-name without an extra query.
        if ((int) $self->id === (int) $authDistributor->id) {
            $self->setRelation('user', Auth::user());
        }

        // Compute the user's actual max depth first so the view can
        // suggest sensible options + render up to that depth on demand.
        // No artificial upper cap — the closure-table scan is bounded by
        // the user's actual descendants, so a `?levels=999999` request
        // does no more work than `?levels=<maxObservedDepth>`.
        $countByDepth = DB::table('genealogy_closure')
            ->where('ancestor_id', $self->id)
            ->where('depth', '>', 0)
            ->groupBy('depth')
            ->select('depth', DB::raw('COUNT(*) AS n'))
            ->pluck('n', 'depth')
            ->all();
        $maxObservedDepth = empty($countByDepth) ? 0 : max(array_keys($countByDepth));

        $requested = $request->query('levels');
        if ($requested === null || $requested === '') {
            $levels = self::DEFAULT_DEPTH;
        } else {
            $levels = max(1, (int) $requested);
        }

        // closure-table fetches every descendant within $levels in one query
        $descendantIds = DB::table('genealogy_closure')
            ->where('ancestor_id', $self->id)
            ->where('depth', '<=', $levels)
            ->pluck('descendant_id', 'descendant_id');

        $nodesById = Distributor::query()
            ->with(['user:id,full_name'])
            ->whereIn('id', $descendantIds)
            ->get()
            ->keyBy('id');

        // Build a lookup: parent_id|side → child_distributor — so the Blade
        // view can render binary slots without N+1 lookups.
        $childByParentSide = [];
        foreach ($nodesById as $node) {
            if ($node->id === $self->id || $node->placement_side === null) {
                continue;
            }
            $childByParentSide[$node->placement_parent_id][$node->placement_side] = $node;
        }

        return view('tree.binary', [
            'self' => $self,
            'nodesById' => $nodesById,
            'childByParentSide' => $childByParentSide,
            'maxDepth' => $levels,
            'totalDescendants' => array_sum($countByDepth),
            'maxObservedDepth' => $maxObservedDepth,
        ]);
    }

    public function sponsorship(Request $request, ?string $adn = null): View|RedirectResponse
    {
        $authDistributor = Auth::user()?->distributor;
        if ($authDistributor === null) {
            return redirect()->route('dashboard');
        }

        $self = $authDistributor;

        if ($adn !== null) {
            // Re-root the sponsorship view at an ADN — must be the auth
            // user's own row, a binary descendant, or a sponsorship
            // descendant. Anything else returns to the user's own root.
            $candidate = Distributor::query()
                ->with(['user:id,full_name'])
                ->where('adn', $adn)
                ->first();
            if ($candidate === null) {
                return redirect()->route('tree.sponsorship')->with('status', 'That distributor was not found in your tree.');
            }

            $inMyBinary = (int) $candidate->id === (int) $authDistributor->id
                || DB::table('genealogy_closure')
                    ->where('ancestor_id', $authDistributor->id)
                    ->where('descendant_id', $candidate->id)
                    ->where('depth', '>', 0)
                    ->exists();

            if (! $inMyBinary) {
                return redirect()->route('tree.sponsorship')->with('status', 'You can only view distributors in your own downline.');
            }
            $self = $candidate;
        }

        if ((int) $self->id === (int) $authDistributor->id) {
            $self->setRelation('user', Auth::user());
        }

        $requested = $request->query('levels');
        $levels = ($requested === null || $requested === '')
            ? self::DEFAULT_DEPTH
            : max(1, (int) $requested);

        // Walk the sponsorship graph breadth-first from $self for $levels.
        // Each ring is one SELECT against distributors WHERE sponsor_id IN
        // (...) — bounded by the user's actual fan-out, not the whole
        // table. Self-rows are excluded (sponsor_id == id) because the L0
        // root sponsors itself in the seed data.
        $childrenByParent = [];
        $allDescendantIds = [];
        $observedDepth = 0;
        $frontier = [(int) $self->id];
        for ($depth = 1; $depth <= $levels && ! empty($frontier); $depth++) {
            $rows = Distributor::query()
                ->with(['user:id,full_name,status'])
                ->whereIn('sponsor_id', $frontier)
                ->whereColumn('id', '!=', 'sponsor_id')
                ->get();
            if ($rows->isEmpty()) {
                break;
            }
            $observedDepth = $depth;
            $next = [];
            foreach ($rows as $row) {
                $childrenByParent[(int) $row->sponsor_id][] = $row;
                $allDescendantIds[] = (int) $row->id;
                $next[] = (int) $row->id;
            }
            $frontier = $next;
        }

        $totalDescendants = count($allDescendantIds);

        // Probe whether there is more below the requested depth so the
        // "observed depth" hint in the toolbar matches reality (this is a
        // cheap aggregate — one SELECT, no fetch).
        $maxObservedDepth = $observedDepth;
        if ($observedDepth === $levels && ! empty($frontier)) {
            $hasMoreBelow = Distributor::query()
                ->whereIn('sponsor_id', $frontier)
                ->whereColumn('id', '!=', 'sponsor_id')
                ->exists();
            if ($hasMoreBelow) {
                $maxObservedDepth = $observedDepth + 1;
            }
        }

        return view('tree.sponsorship', [
            'self'             => $self,
            'childrenByParent' => $childrenByParent,
            'maxDepth'         => $levels,
            'totalDescendants' => $totalDescendants,
            'maxObservedDepth' => $maxObservedDepth,
        ]);
    }
}
