<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Services\DistributorIdCardStats;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Returns a Blade-rendered fragment of the ID-card panel for any
 * distributor visible to the requester. Used by the tree-view "Details"
 * menu item — the modal fetches this HTML and injects it without
 * client-side templating, so the view layer stays the single source of
 * markup truth.
 *
 * Authorization mirrors the existing tree-pivot rules: a viewer can see
 * a distributor's details if the viewer is an admin, OR the target is
 * the viewer themselves, OR the target sits inside the viewer's binary
 * downline (`genealogy_closure(ancestor_id=viewer, descendant_id=target)`).
 * Anything else is a 403 — same access surface the user already has via
 * the tree views.
 */
final class DistributorDetailsController extends Controller
{
    public function show(Request $request, Distributor $distributor, DistributorIdCardStats $service): View
    {
        $authUser = Auth::user();
        abort_if($authUser === null, 401);
        $authDistributor = $authUser->distributor;

        $isAdmin = $authUser->hasRole('admin');
        $isSelf = $authDistributor !== null && (int) $authDistributor->id === (int) $distributor->id;
        $isDescendant = false;
        if (! $isAdmin && ! $isSelf && $authDistributor !== null) {
            $isDescendant = DB::table('genealogy_closure')
                ->where('ancestor_id', $authDistributor->id)
                ->where('descendant_id', $distributor->id)
                ->where('depth', '>', 0)
                ->exists();
        }
        abort_unless($isAdmin || $isSelf || $isDescendant, 403);

        // Eager-load the user (and the columns the panel reads) so the
        // service doesn't trigger N+1 lookups inside the loop.
        $distributor->loadMissing(['user:id,full_name,email,status,activated_at,id_photo_path']);

        return view('partials._id-card-modal-body', [
            'distributor' => $distributor,
            'idCardStats' => $service->full($distributor),
            'idPhotoUrl' => $service->photoUrl($distributor),
        ]);
    }
}
