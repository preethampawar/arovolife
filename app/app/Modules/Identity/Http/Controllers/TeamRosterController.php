<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Services\TeamStatsService;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backs the dashboard's stat-card click → "show me the actual people in
 * this bucket" modal. Both endpoints reuse the same TeamStatsService roster
 * source so the modal table and the CSV download stay byte-for-byte aligned
 * with the count shown on the card.
 */
final class TeamRosterController extends Controller
{
    private const SCOPE_LABELS = [
        'total' => 'Total team',
        'direct' => 'Direct referrals',
        'left' => 'Left team',
        'right' => 'Right team',
    ];

    public function index(Request $request, TeamStatsService $teamStats, string $scope): JsonResponse
    {
        $user = Auth::user();
        $distributor = $user?->distributor;

        if ($distributor === null) {
            abort(404);
        }

        $rows = $teamStats->roster($distributor, $scope);

        return response()->json([
            'scope' => $scope,
            'label' => self::SCOPE_LABELS[$scope] ?? ucfirst($scope),
            'rows' => $rows,
        ]);
    }

    public function download(Request $request, TeamStatsService $teamStats, string $scope): StreamedResponse
    {
        $user = Auth::user();
        $distributor = $user?->distributor;

        if ($distributor === null) {
            abort(404);
        }

        $rows = $teamStats->roster($distributor, $scope);
        $label = self::SCOPE_LABELS[$scope] ?? ucfirst($scope);
        $filename = sprintf(
            'arovolife-%s-%s',
            str_replace(' ', '-', strtolower($label)),
            now()->format('Y-m-d'),
        );

        $columns = [
            ['key' => 'sno', 'label' => 'S.No.'],
            ['key' => 'adn', 'label' => 'ADN No.'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'state', 'label' => 'State'],
            ['key' => 'status', 'label' => 'Status'],
        ];

        $out = [];

        foreach ($rows as $i => $row) {
            $out[] = [
                'sno' => $i + 1,
                'adn' => $row['adn'],
                'name' => $row['name'],
                'state' => $row['state'],
                'status' => $row['status'],
            ];
        }

        return ReportExport::respond($request, $filename, $columns, $out);
    }
}
