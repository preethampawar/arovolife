<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\DatabaseManager;

/**
 * Canonical team-stats aggregation.
 *
 * Encapsulates every "how many people are in $distributor's downline,
 * grouped by X" query so multiple pages (dashboard, ID-card popup,
 * future reports) all read from the same source. If the counting
 * semantics ever change — e.g. excluding spouse rows, including
 * pending KYC — this is the only place to update.
 *
 * The two entry points are deliberately distinct:
 *   - {@see counts()} — the cheap subset (3 numbers). Used wherever a
 *     compact ID card is rendered, e.g. per node in a tree view.
 *   - {@see full()} — the rich breakdown (status / activity rings).
 *     Used by the dashboard's "My Team" panel.
 *
 * Splitting them keeps the per-node tree render from paying for joins
 * it doesn't need.
 */
final class TeamStatsService
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Three headline counts: left_team, right_team, total_team.
     *
     * @return array{left_team: int, right_team: int, total_team: int}
     */
    public function counts(Distributor $distributor): array
    {
        $myId = (int) $distributor->id;

        $totalBinary = (int) $this->db->table('genealogy_closure')
            ->where('ancestor_id', $myId)
            ->where('depth', '>', 0)
            ->count();

        $leftChildId = $this->db->table('distributors')
            ->where('placement_parent_id', $myId)
            ->where('placement_side', 'L')
            ->where('id', '!=', $myId)
            ->value('id');

        $rightChildId = $this->db->table('distributors')
            ->where('placement_parent_id', $myId)
            ->where('placement_side', 'R')
            ->where('id', '!=', $myId)
            ->value('id');

        $leftTeam = $leftChildId !== null
            ? (int) $this->db->table('genealogy_closure')->where('ancestor_id', $leftChildId)->count()
            : 0;

        $rightTeam = $rightChildId !== null
            ? (int) $this->db->table('genealogy_closure')->where('ancestor_id', $rightChildId)->count()
            : 0;

        return [
            'left_team' => $leftTeam,
            'right_team' => $rightTeam,
            'total_team' => $totalBinary,
        ];
    }

    /**
     * Full breakdown: the three counts plus direct-referral, by-status
     * rings, joined-recent rings, and cooling-off-active. Used by the
     * dashboard "My Team" panel.
     *
     * @return array<string, int>
     */
    public function full(Distributor $distributor): array
    {
        $myId = (int) $distributor->id;
        $counts = $this->counts($distributor);

        $directReferrals = (int) $this->db->table('sponsorship')
            ->where('sponsor_id', $myId)
            ->count();

        $byStatus = $this->db->table('genealogy_closure as gc')
            ->join('distributors as d', 'd.id', '=', 'gc.descendant_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('gc.ancestor_id', $myId)
            ->where('gc.depth', '>', 0)
            ->groupBy('u.status')
            ->select('u.status', $this->db->raw('COUNT(*) as n'))
            ->pluck('n', 'status')
            ->all();

        $joinedThisWeek = (int) $this->db->table('distributors as d')
            ->join('genealogy_closure as gc', 'gc.descendant_id', '=', 'd.id')
            ->where('gc.ancestor_id', $myId)
            ->where('gc.depth', '>', 0)
            ->where('d.effective_date', '>=', now()->startOfWeek())
            ->count();

        $joinedThisMonth = (int) $this->db->table('distributors as d')
            ->join('genealogy_closure as gc', 'gc.descendant_id', '=', 'd.id')
            ->where('gc.ancestor_id', $myId)
            ->where('gc.depth', '>', 0)
            ->where('d.effective_date', '>=', now()->startOfMonth())
            ->count();

        $coolingOff = (int) $this->db->table('distributors as d')
            ->join('genealogy_closure as gc', 'gc.descendant_id', '=', 'd.id')
            ->where('gc.ancestor_id', $myId)
            ->where('gc.depth', '>', 0)
            ->where('d.cooling_off_end_at', '>', now())
            ->count();

        return [
            'total_team' => $counts['total_team'],
            'direct_referrals' => $directReferrals,
            'left_team' => $counts['left_team'],
            'right_team' => $counts['right_team'],
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
