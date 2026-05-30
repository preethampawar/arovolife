<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Canonical team-stats aggregation.
 *
 * Every "how many people are in $distributor's downline, grouped by X"
 * question — headline counts on the dashboard, the status / activity
 * rings, the per-card "click to see the list" modal — is answered from
 * ONE place: {@see scopedQuery()}. counts(), full() and roster() all
 * call into it, so the number shown on a card is guaranteed to equal
 * the number of rows the modal renders for that same card.
 *
 * Scopes (the only ones we surface to the UI):
 *   - 'total':  every binary descendant (closure depth > 0; excludes self)
 *   - 'direct': people the distributor personally sponsored
 *   - 'left' /
 *     'right':  the entire subtree rooted at the left / right child
 *               (closure ancestor_id = childId; includes the child itself
 *                via the depth=0 closure row, matching the binary-leg count
 *                shown elsewhere on the dashboard)
 *
 * If the counting semantics ever change — e.g. excluding terminated rows,
 * or excluding pending KYC — change scopedQuery() and every consumer
 * follows automatically.
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
        return [
            'left_team' => $this->scopedCount($distributor, 'left'),
            'right_team' => $this->scopedCount($distributor, 'right'),
            'total_team' => $this->scopedCount($distributor, 'total'),
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
        $totalQuery = $this->scopedQuery($distributor, 'total');

        $byStatus = (clone $totalQuery)
            ->reorder()
            ->groupBy('u.status')
            ->select('u.status', $this->db->raw('COUNT(*) as n'))
            ->pluck('n', 'status')
            ->all();

        $joinedThisWeek = (int) (clone $totalQuery)
            ->where('d.effective_date', '>=', now()->startOfWeek())
            ->count();

        $joinedThisMonth = (int) (clone $totalQuery)
            ->where('d.effective_date', '>=', now()->startOfMonth())
            ->count();

        $coolingOff = (int) (clone $totalQuery)
            ->where('d.cooling_off_end_at', '>', now())
            ->count();

        return [
            'total_team' => $this->scopedCount($distributor, 'total'),
            'direct_referrals' => $this->scopedCount($distributor, 'direct'),
            'left_team' => $this->scopedCount($distributor, 'left'),
            'right_team' => $this->scopedCount($distributor, 'right'),
            'active' => (int) ($byStatus['active'] ?? 0),
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'frozen' => (int) ($byStatus['frozen'] ?? 0),
            'terminated' => (int) ($byStatus['terminated'] ?? 0),
            'joined_this_week' => $joinedThisWeek,
            'joined_this_month' => $joinedThisMonth,
            'cooling_off' => $coolingOff,
        ];
    }

    /**
     * Per-distributor roster for a headline count. Powers the
     * dashboard "click a stat card → modal with a downloadable list" UX.
     *
     * @return list<array{adn: string, name: string, state: string, status: string}>
     */
    public function roster(Distributor $distributor, string $scope): array
    {
        return $this->scopedQuery($distributor, $scope)
            ->select(
                'd.adn',
                'u.full_name',
                'd.state',
                'u.status',
            )
            ->orderBy('d.effective_date')
            ->get()
            ->map(fn ($row) => [
                'adn' => (string) $row->adn,
                'name' => (string) $row->full_name,
                'state' => (string) $row->state,
                'status' => $this->statusLabel((string) $row->status),
            ])
            ->all();
    }

    public function scopedCount(Distributor $distributor, string $scope): int
    {
        return (int) $this->scopedQuery($distributor, $scope)->count();
    }

    /**
     * The single source of truth — every downline / referral question
     * about a distributor flows through here. Returns a builder seeded
     * at `distributors as d` joined to `users as u`, with the WHERE/JOIN
     * predicates that define the requested scope already applied.
     *
     * Callers add ->count(), ->select(...), ->orderBy(...) etc.
     */
    private function scopedQuery(Distributor $distributor, string $scope): Builder
    {
        $myId = (int) $distributor->id;

        $base = $this->db->table('distributors as d')
            ->join('users as u', 'u.id', '=', 'd.user_id');

        return match ($scope) {
            'total' => $base
                ->join('genealogy_closure as gc', 'gc.descendant_id', '=', 'd.id')
                ->where('gc.ancestor_id', $myId)
                ->where('gc.depth', '>', 0),

            'direct' => $base
                ->join('sponsorship as s', 's.distributor_id', '=', 'd.id')
                ->where('s.sponsor_id', $myId),

            'left', 'right' => (function () use ($base, $myId, $scope) {
                $side = $scope === 'left' ? 'L' : 'R';
                $childId = $this->db->table('distributors')
                    ->where('placement_parent_id', $myId)
                    ->where('placement_side', $side)
                    ->where('id', '!=', $myId)
                    ->value('id');
                if ($childId === null) {
                    // No child on this leg — return a builder that matches
                    // zero rows, so count() returns 0 and roster() returns [].
                    return $base->whereRaw('1 = 0');
                }

                return $base
                    ->join('genealogy_closure as gc', 'gc.descendant_id', '=', 'd.id')
                    ->where('gc.ancestor_id', (int) $childId);
            })(),

            default => throw new \InvalidArgumentException("Unknown team-stats scope: {$scope}"),
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'pending' => 'Pending',
            'frozen' => 'Blocked',
            'terminated' => 'Inactive',
            'rejected' => 'Rejected',
            default => ucfirst($status),
        };
    }
}
