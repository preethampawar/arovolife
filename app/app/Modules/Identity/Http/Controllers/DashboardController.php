<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Services\DistributorIdCardStats;
use App\Modules\Identity\Services\TeamStatsService;
use App\Modules\Messaging\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Distributor "My Office" dashboard.
 *
 * Aggregates everything a logged-in distributor would want to see at a
 * glance: their identity card (ADN, placement leg, cooling-off status),
 * a slot-aware referral-link widget, and a team summary covering both
 * the sponsorship tree (people they personally recruited) and the
 * binary genealogy (their full downline by leg + status breakdown).
 *
 * All stat assembly is delegated to {@see TeamStatsService} and
 * {@see DistributorIdCardStats} — the same services power the tree-view
 * card display and the Details popup, so every surface reads from a
 * single source of truth.
 */
final class DashboardController extends Controller
{
    public function index(
        PlacementEngine $engine,
        TeamStatsService $teamStatsService,
        DistributorIdCardStats $idCardService,
    ): View|RedirectResponse {
        $user = Auth::user();

        // Admin accounts have no distributor row, so the dashboard would
        // otherwise render the "Registration not yet complete" prompt at
        // them. They have their own console — send them there instead.
        if ($user !== null && $user->hasRole('admin')) {
            return redirect()->route('admin.dashboard');
        }

        $distributor = $user?->distributor;

        $leftOpen = $rightOpen = false;
        $maxObservedDepth = 0;
        $teamStats = null;
        $idCardStats = null;
        $idPhotoUrl = null;

        if ($distributor !== null) {
            $leftOpen = $engine->hasOpenSlot($distributor->id, 'L');
            $rightOpen = $engine->hasOpenSlot($distributor->id, 'R');

            $maxObservedDepth = (int) DB::table('genealogy_closure')
                ->where('ancestor_id', $distributor->id)
                ->where('depth', '>', 0)
                ->max('depth');

            $teamStats = $teamStatsService->full($distributor);
            $idCardStats = $idCardService->full($distributor);
            $idPhotoUrl = $idCardService->photoUrl($distributor);
        }

        // Messages card — unread count + latest received message preview.
        // The eager-load on fromUser is cheap (one extra SELECT for one row)
        // and keeps the Blade simple: $latestMessage->fromUser->full_name.
        $unreadMessagesCount = $user !== null
            ? (int) Message::unreadFor((int) $user->id)->count()
            : 0;

        $latestMessage = $user !== null
            ? Message::query()
                ->where('to_user_id', $user->id)
                ->with(['fromUser:id,full_name,email'])
                ->latest('created_at')
                ->first()
            : null;

        return view('dashboard.index', [
            'user' => $user,
            'distributor' => $distributor,
            'leftOpen' => $leftOpen,
            'rightOpen' => $rightOpen,
            'maxObservedDepth' => $maxObservedDepth,
            'teamStats' => $teamStats,
            'idCardStats' => $idCardStats,
            'idPhotoUrl' => $idPhotoUrl,
            'unreadMessagesCount' => $unreadMessagesCount,
            'latestMessage' => $latestMessage,
        ]);
    }
}
