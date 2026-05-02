<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\AttributionTouch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sponsor attribution via first-party cookie. Sticky for 30 days.
 * Attribution freezes onto orders.attributed_distributor_id at checkout.
 */
final class AttributionService
{
    public const COOKIE_NAME = 'av_ref';

    public const ANON_COOKIE = 'av_anon';

    public const WINDOW_DAYS = 30;

    public function recordTouch(Request $request, string $refAdn): void
    {
        $refAdn = strtoupper(trim($refAdn));
        if ($refAdn === '' || ! preg_match('/^[A-Z0-9]{6,16}$/', $refAdn)) {
            return;
        }

        $distributor = DB::table('distributors')
            ->where('adn', $refAdn)
            ->whereIn('status', ['active', 'pending'])
            ->first();

        // Invalid/frozen ADN: silent demote, no cookie, audit-only
        if ($distributor === null) {
            AttributionTouch::create([
                'anonymous_key' => $this->anonymousKey($request),
                'ref_adn' => $refAdn,
                'distributor_id' => null,
                'landing_url' => (string) $request->fullUrl(),
                'ip_hash' => hash('sha256', (string) $request->ip()),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'occurred_at' => Carbon::now(),
            ]);

            return;
        }

        // Valid — set cookie and log touch
        Cookie::queue(self::COOKIE_NAME, $refAdn, self::WINDOW_DAYS * 24 * 60);

        AttributionTouch::create([
            'anonymous_key' => $this->anonymousKey($request),
            'ref_adn' => $refAdn,
            'distributor_id' => $distributor->id,
            'landing_url' => (string) $request->fullUrl(),
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'occurred_at' => Carbon::now(),
        ]);
    }

    /**
     * Resolve the distributor who should be attributed to a checkout.
     * Returns null if there's no valid attribution (= house sale).
     *
     * @return array{distributor_id: int|null, source: string}
     */
    public function resolveForCheckout(Request $request, ?int $loggedInDistributorId = null): array
    {
        // Logged-in distributor takes precedence if the admin setting allows
        if ($loggedInDistributorId !== null) {
            return ['distributor_id' => $loggedInDistributorId, 'source' => 'logged_in'];
        }

        $refAdn = $request->cookie(self::COOKIE_NAME);
        if (! is_string($refAdn) || $refAdn === '') {
            return ['distributor_id' => null, 'source' => 'direct'];
        }

        $distributor = DB::table('distributors')
            ->where('adn', strtoupper($refAdn))
            ->whereIn('status', ['active', 'pending'])
            ->first();

        if ($distributor === null) {
            return ['distributor_id' => null, 'source' => 'direct'];
        }

        return ['distributor_id' => (int) $distributor->id, 'source' => 'cookie'];
    }

    public function anonymousKey(Request $request): string
    {
        $existing = $request->cookie(self::ANON_COOKIE);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $key = (string) Str::uuid();
        Cookie::queue(self::ANON_COOKIE, $key, 60 * 24 * 90); // 90 days

        return $key;
    }
}
