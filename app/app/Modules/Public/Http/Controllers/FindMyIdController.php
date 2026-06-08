<?php

declare(strict_types=1);

namespace App\Modules\Public\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Find My ID" — a distributor who has forgotten their ADN can recover it by
 * entering their registered full name + PAN. We hash the submitted PAN the
 * SAME way registration does (sha256 of the trimmed/upper-cased value) and
 * match it against the stored hash; the raw PAN is never stored, displayed or
 * logged. Only an exact name + PAN match returns an ADN.
 *
 * Anti-abuse (so this can't become a PAN-guessing oracle that leaks members'
 * names/ADNs — DPDP Act 2023):
 *   - dual exact match (name AND pan) — neither alone reveals anything;
 *   - per-IP rate limit on every attempt (success or miss), plus a route
 *     throttle;
 *   - generic "no match" message — never says which field was wrong;
 *   - PAN validated by format before any DB touch; never written to logs;
 *   - each successful disclosure is audit-logged (distributor id + ip, no PAN).
 */
final class FindMyIdController extends Controller
{
    private const MAX_ATTEMPTS_PER_HOUR = 5;

    private const MAX_ATTEMPTS_PER_DAY = 15;

    public function show(): View
    {
        return view('public.find-my-id', ['result' => null, 'searched' => false]);
    }

    public function lookup(Request $request): View
    {
        $hourKey = 'find-my-id:'.$request->ip();
        $dayKey = 'find-my-id-daily:'.$request->ip();

        // Two-tier per-IP cap: a tight hourly limit for bursts and a daily cap
        // so distributed slow guessing can't harvest ADNs over a long window.
        if (RateLimiter::tooManyAttempts($hourKey, self::MAX_ATTEMPTS_PER_HOUR)
            || RateLimiter::tooManyAttempts($dayKey, self::MAX_ATTEMPTS_PER_DAY)) {
            $this->auditAttempt($request, 'identity.find_my_id.throttled', null);

            $minutes = (int) ceil(RateLimiter::availableIn($hourKey) / 60);

            return view('public.find-my-id', [
                'result' => null,
                'searched' => true,
                'error' => "Too many attempts. Please try again in about {$minutes} minute(s).",
            ]);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            // Indian PAN: 5 letters, 4 digits, 1 letter.
            'pan' => ['required', 'string', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],
            // DPDP Act 2023 §5-6 — informed consent before processing the PAN.
            'consent_privacy' => ['required', 'accepted'],
        ], [
            'pan.regex' => 'Enter a valid 10-character PAN (e.g. ABCDE1234F).',
            'consent_privacy.required' => 'Please agree to the privacy notice before continuing.',
            'consent_privacy.accepted' => 'Please agree to the privacy notice before continuing.',
        ]);

        // Every validated attempt counts toward BOTH limits, so brute-forcing
        // PANs is throttled regardless of whether a match is found.
        RateLimiter::hit($hourKey, decaySeconds: 3600);
        RateLimiter::hit($dayKey, decaySeconds: 86400);

        $pan = strtoupper(trim($validated['pan']));
        $panHash = hash('sha256', $pan, true);
        $name = trim($validated['full_name']);

        // Account liveness is tracked on the USER status (active / pending /
        // frozen / terminated / rejected); distributors.status is a separate
        // slot flag. Only a live (active/pending) account may recover its ADN —
        // a terminated/frozen account must not be disclosed.
        $row = DB::table('distributors as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.pan_hash', $panHash)
            ->whereRaw('LOWER(TRIM(u.full_name)) = ?', [mb_strtolower($name)])
            ->whereIn('u.status', ['active', 'pending'])
            ->select('d.id', 'd.adn', 'u.status', 'u.full_name', 'd.state')
            ->first();

        if ($row === null) {
            // Audit misses too (no PAN) so a harvesting attempt leaves a trail.
            $this->auditAttempt($request, 'identity.find_my_id.miss', null);

            return view('public.find-my-id', [
                'result' => null,
                'searched' => true,
                'error' => "We couldn't find a distributor matching those details. Please check your name and PAN, or contact support.",
            ]);
        }

        // Audit the disclosure — distributor id + requester IP only, never PAN.
        $this->auditAttempt($request, 'identity.find_my_id.success', (int) $row->id);

        return view('public.find-my-id', [
            'result' => [
                'adn' => (string) $row->adn,
                'name' => (string) $row->full_name,
                'state' => (string) $row->state,
                'status' => (string) $row->status,
            ],
            'searched' => true,
        ]);
    }

    /**
     * Record a Find-My-ID attempt for the compliance trail. Never carries the
     * PAN or name — only the action, the matched distributor id (on success),
     * and the requester IP.
     */
    private function auditAttempt(Request $request, string $action, ?int $distributorId): void
    {
        AuditLog::create([
            'actor_id' => null,
            'action' => $action,
            // subject_type is NOT NULL; misses/throttles have no distributor.
            'subject_type' => $distributorId !== null ? 'distributor' : 'find_my_id_attempt',
            'subject_id' => $distributorId,
            'details' => ['channel' => 'public_find_my_id'],
            'ip' => $request->ip(),
        ]);
    }
}
