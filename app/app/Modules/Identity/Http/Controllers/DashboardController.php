<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Genealogy\Services\PlacementEngine;
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
 */
final class DashboardController extends Controller
{
    public function index(PlacementEngine $engine): View
    {
        $user = Auth::user();
        $distributor = $user?->distributor;

        $leftOpen = $rightOpen = false;
        $maxObservedDepth = 0;
        $teamStats = null;

        if ($distributor !== null) {
            $leftOpen = $engine->hasOpenSlot($distributor->id, 'L');
            $rightOpen = $engine->hasOpenSlot($distributor->id, 'R');

            $maxObservedDepth = (int) DB::table('genealogy_closure')
                ->where('ancestor_id', $distributor->id)
                ->where('depth', '>', 0)
                ->max('depth');

            $teamStats = $this->buildTeamStats((int) $distributor->id);
        }

        return view('dashboard.index', [
            'user' => $user,
            'distributor' => $distributor,
            'leftOpen' => $leftOpen,
            'rightOpen' => $rightOpen,
            'maxObservedDepth' => $maxObservedDepth,
            'teamStats' => $teamStats,
        ]);
    }

    /**
     * Single round-trip per stat. Genealogy closure is the source of
     * truth for the binary tree (descendant counts by leg); the
     * `sponsorship` table is the source for direct referrals (which
     * may differ from immediate placement children — sponsor and
     * placement target need not be the same person).
     *
     * @return array<string, int>
     */
    private function buildTeamStats(int $myId): array
    {
        // ── Binary tree counts ────────────────────────────────────────
        $totalBinary = (int) DB::table('genealogy_closure')
            ->where('ancestor_id', $myId)
            ->where('depth', '>', 0)
            ->count();

        $leftChildId = DB::table('distributors')
            ->where('placement_parent_id', $myId)
            ->where('placement_side', 'L')
            ->where('id', '!=', $myId)
            ->value('id');

        $rightChildId = DB::table('distributors')
            ->where('placement_parent_id', $myId)
            ->where('placement_side', 'R')
            ->where('id', '!=', $myId)
            ->value('id');

        $leftTeam = $leftChildId !== null
            ? (int) DB::table('genealogy_closure')->where('ancestor_id', $leftChildId)->count()
            : 0;

        $rightTeam = $rightChildId !== null
            ? (int) DB::table('genealogy_closure')->where('ancestor_id', $rightChildId)->count()
            : 0;

        // ── Direct referrals (sponsorship, regardless of placement) ──
        // The `sponsorship` table is flat — one row per (sponsor → directly-
        // introduced distributor) edge — so every row already represents a
        // direct referral. The legacy `->where('depth', 1)` referenced a
        // column that does not exist on this table (it exists on
        // `genealogy_closure` which is the multi-level placement closure).
        $directReferrals = (int) DB::table('sponsorship')
            ->where('sponsor_id', $myId)
            ->count();

        // ── Status breakdown of the binary downline (excludes self) ──
        $byStatus = DB::table('genealogy_closure as gc')
            ->join('distributors as d', 'd.id', '=', 'gc.descendant_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('gc.ancestor_id', $myId)
            ->where('gc.depth', '>', 0)
            ->groupBy('u.status')
            ->select('u.status', DB::raw('COUNT(*) as n'))
            ->pluck('n', 'status')
            ->all();

        // ── Joined recently ──────────────────────────────────────────
        $joinedThisWeek = (int) DB::table('distributors as d')
            ->join('genealogy_closure as gc', 'gc.descendant_id', '=', 'd.id')
            ->where('gc.ancestor_id', $myId)
            ->where('gc.depth', '>', 0)
            ->where('d.effective_date', '>=', now()->startOfWeek())
            ->count();

        $joinedThisMonth = (int) DB::table('distributors as d')
            ->join('genealogy_closure as gc', 'gc.descendant_id', '=', 'd.id')
            ->where('gc.ancestor_id', $myId)
            ->where('gc.depth', '>', 0)
            ->where('d.effective_date', '>=', now()->startOfMonth())
            ->count();

        // ── Cooling-off active in the team (within 30-day window) ────
        $coolingOff = (int) DB::table('distributors as d')
            ->join('genealogy_closure as gc', 'gc.descendant_id', '=', 'd.id')
            ->where('gc.ancestor_id', $myId)
            ->where('gc.depth', '>', 0)
            ->where('d.cooling_off_end_at', '>', now())
            ->count();

        return [
            'total_team' => $totalBinary,
            'direct_referrals' => $directReferrals,
            'left_team' => $leftTeam,
            'right_team' => $rightTeam,
            'active' => (int) ($byStatus['active'] ?? 0),
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'frozen' => (int) ($byStatus['frozen'] ?? 0),
            'terminated' => (int) ($byStatus['terminated'] ?? 0),
            'joined_this_week' => $joinedThisWeek,
            'joined_this_month' => $joinedThisMonth,
            'cooling_off' => $coolingOff,
        ];
    }
}
