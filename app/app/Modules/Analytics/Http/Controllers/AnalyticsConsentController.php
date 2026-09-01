<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Records Google Analytics consent decisions to the database.
 *
 * This endpoint is intentionally public (no `auth` middleware) so that guests
 * who accept or decline before registering are also covered. The response is
 * always JSON so the banner can call it with fetch() without a page reload.
 *
 * The corresponding browser-side preference is stored in localStorage by the
 * caller — this endpoint's sole job is to create the server-side audit record
 * that DPDP 2023 §6 requires us to be able to produce for a regulator.
 */
final class AnalyticsConsentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $decision = $request->input('decision');

        if (! in_array($decision, ['granted', 'denied'], true)) {
            return response()->json(['error' => 'Invalid decision.'], 422);
        }

        $distributorId = null;
        $user = $request->user();
        if ($user !== null) {
            $distributorId = $user->distributor?->id;
        }

        DB::table('analytics_consent_logs')->insert([
            'distributor_id' => $distributorId,
            'session_id' => $request->session()->getId(),
            'decision' => $decision,
            'ip' => $request->ip(),
            'user_agent' => mb_substr($request->userAgent() ?? '', 0, 512),
            'decided_at' => now()->format('Y-m-d H:i:s.v'),
        ]);

        return response()->json(['ok' => true]);
    }
}
