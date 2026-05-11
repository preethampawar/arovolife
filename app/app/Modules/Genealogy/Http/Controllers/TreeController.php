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

    public function sponsorship(): View|RedirectResponse
    {
        $self = Auth::user()?->distributor;
        if ($self === null) {
            return redirect()->route('dashboard');
        }

        $directReferrals = Distributor::query()
            ->where('sponsor_id', $self->id)
            ->where('id', '!=', $self->id) // exclude the self-row from root seed
            ->orderByDesc('created_at')
            ->paginate(50);

        return view('tree.sponsorship', [
            'self' => $self,
            'direct' => $directReferrals,
        ]);
    }
}
