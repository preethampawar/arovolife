<?php

declare(strict_types=1);

namespace App\Modules\Public\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Notifications\OtpCodeNotification;
use App\Modules\Shared\Otp\OtpService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Find My ID" — a distributor who has forgotten their ADN can recover it by
 * entering their registered full name + PAN. We hash the submitted PAN the
 * SAME way registration does (sha256 of the trimmed/upper-cased value) and
 * match it against the stored hash; the raw PAN is never stored, displayed or
 * logged. Only an exact name + PAN match triggers an OTP to the registered
 * email; the ADN is disclosed only after the OTP is verified.
 *
 * Anti-abuse (so this can't become a PAN-guessing oracle that leaks members'
 * names/ADNs — DPDP Act 2023):
 *   - dual exact match (name AND pan) — neither alone reveals anything;
 *   - per-IP rate limit on every attempt (success or miss), plus a route
 *     throttle;
 *   - OTP gate before ADN is revealed — a correct name+PAN match only sends
 *     a code to the registered contact; the requester must prove they control
 *     that contact before the ADN is shown;
 *   - generic "no match" message — never says which field was wrong;
 *   - PAN validated by format before any DB touch; never written to logs;
 *   - each successful disclosure is audit-logged (distributor id + ip, no PAN).
 */
final class FindMyIdController extends Controller
{
    private const MAX_ATTEMPTS_PER_HOUR = 5;

    private const MAX_ATTEMPTS_PER_DAY = 15;

    private const OTP_PURPOSE = 'find_my_id';

    public const SESSION_DIST_ID = 'find_my_id_dist_id';

    public function show(): View
    {
        return view('public.find-my-id', ['step' => 'lookup']);
    }

    public function lookup(Request $request, OtpService $otp): View
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
                'step'  => 'lookup',
                'error' => "Too many attempts. Please try again in about {$minutes} minute(s).",
            ]);
        }

        $validated = $request->validate([
            'full_name'       => ['required', 'string', 'max:255'],
            // Indian PAN: 5 letters, 4 digits, 1 letter.
            'pan'             => ['required', 'string', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],
            // DPDP Act 2023 §5-6 — informed consent before processing the PAN.
            'consent_privacy' => ['required', 'accepted'],
        ], [
            'pan.regex'                => 'Enter a valid 10-character PAN (e.g. ABCDE1234F).',
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
            ->select('d.id', 'd.adn', 'u.id as user_id', 'u.email', 'u.status', 'u.full_name', 'd.state')
            ->first();

        if ($row === null) {
            // Audit misses too (no PAN) so a harvesting attempt leaves a trail.
            $this->auditAttempt($request, 'identity.find_my_id.miss', null);

            return view('public.find-my-id', [
                'step'  => 'lookup',
                'error' => "We couldn't find a distributor matching those details. Please check your name and PAN, or contact support.",
            ]);
        }

        // Match found — issue OTP, persist dist_id in session, show OTP step.
        $otpKey = 'dist:'.(int) $row->id;
        $code = $otp->issue(self::OTP_PURPOSE, $otpKey, [
            'dist_id' => (int) $row->id,
            'adn'     => (string) $row->adn,
            'name'    => (string) $row->full_name,
            'state'   => (string) $row->state,
            'status'  => (string) $row->status,
        ]);

        $user = User::find((int) $row->user_id);
        if ($user !== null) {
            Notification::send($user, new OtpCodeNotification($code, 'retrieve your Distributor Number (ADN)', 10));
        }

        $request->session()->put(self::SESSION_DIST_ID, (int) $row->id);

        $this->auditAttempt($request, 'identity.find_my_id.otp_issued', (int) $row->id);

        return view('public.find-my-id', [
            'step'          => 'otp',
            'maskedContact' => $this->maskEmail((string) $row->email),
        ]);
    }

    public function verifyOtp(Request $request, OtpService $otp): View
    {
        $distId = $request->session()->get(self::SESSION_DIST_ID);

        if (! is_int($distId)) {
            return view('public.find-my-id', [
                'step'  => 'lookup',
                'error' => 'Your session has expired. Please start again.',
            ]);
        }

        $validated = $request->validate([
            'otp_code' => ['required', 'string', 'digits:6'],
        ], [
            'otp_code.required' => 'Please enter the 6-digit code we sent you.',
            'otp_code.digits'   => 'Enter a valid 6-digit code.',
        ]);

        $result = $otp->verify(self::OTP_PURPOSE, 'dist:'.$distId, $validated['otp_code']);

        if (! $result->ok) {
            $this->auditAttempt($request, 'identity.find_my_id.otp_failed', $distId);

            $needsRestart = in_array($result->reason, ['expired', 'too_many_attempts'], true);

            if ($needsRestart) {
                $request->session()->forget(self::SESSION_DIST_ID);
            }

            return view('public.find-my-id', [
                'step'          => $needsRestart ? 'lookup' : 'otp',
                'error'         => $result->message(),
                'maskedContact' => $needsRestart ? null : $this->maskedContactForDist($distId),
            ]);
        }

        // OTP verified — safe to disclose the ADN.
        $request->session()->forget(self::SESSION_DIST_ID);

        $this->auditAttempt($request, 'identity.find_my_id.otp_verified', $distId);

        /** @var array{dist_id:int,adn:string,name:string,state:string,status:string} $payload */
        $payload = $result->payload;

        return view('public.find-my-id', [
            'step'   => 'result',
            'result' => [
                'adn'    => $payload['adn'],
                'name'   => $payload['name'],
                'state'  => $payload['state'],
                'status' => $payload['status'],
            ],
        ]);
    }

    public function resendOtp(Request $request, OtpService $otp): View
    {
        $distId = $request->session()->get(self::SESSION_DIST_ID);

        if (! is_int($distId)) {
            return view('public.find-my-id', [
                'step'  => 'lookup',
                'error' => 'Your session has expired. Please start again.',
            ]);
        }

        $row = DB::table('distributors as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.id', $distId)
            ->select('u.id as user_id', 'u.email', 'd.adn', 'u.full_name', 'd.state', 'u.status')
            ->first();

        if ($row === null) {
            $request->session()->forget(self::SESSION_DIST_ID);

            return view('public.find-my-id', [
                'step'  => 'lookup',
                'error' => 'Account not found. Please start again.',
            ]);
        }

        $otpKey = 'dist:'.$distId;
        $code = $otp->issue(self::OTP_PURPOSE, $otpKey, [
            'dist_id' => $distId,
            'adn'     => (string) $row->adn,
            'name'    => (string) $row->full_name,
            'state'   => (string) $row->state,
            'status'  => (string) $row->status,
        ]);

        $user = User::find((int) $row->user_id);
        if ($user !== null) {
            Notification::send($user, new OtpCodeNotification($code, 'retrieve your Distributor Number (ADN)', 10));
        }

        $this->auditAttempt($request, 'identity.find_my_id.otp_resent', $distId);

        return view('public.find-my-id', [
            'step'          => 'otp',
            'maskedContact' => $this->maskEmail((string) $row->email),
            'resent'        => true,
        ]);
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        $maskedLocal = substr($local, 0, 1).str_repeat('*', max(mb_strlen($local) - 1, 3));
        $dotPos = strrpos($domain, '.');
        $domainName = $dotPos !== false ? substr($domain, 0, $dotPos) : $domain;
        $tld = $dotPos !== false ? substr($domain, $dotPos) : '';

        return $maskedLocal.'@'.substr($domainName, 0, 1).'***'.$tld;
    }

    private function maskedContactForDist(int $distId): ?string
    {
        $email = DB::table('distributors as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.id', $distId)
            ->value('u.email');

        return $email !== null ? $this->maskEmail((string) $email) : null;
    }

    /**
     * Record a Find-My-ID attempt for the compliance trail. Never carries the
     * PAN or name — only the action, the matched distributor id (on success),
     * and the requester IP.
     */
    private function auditAttempt(Request $request, string $action, ?int $distributorId): void
    {
        AuditLog::create([
            'actor_id'     => null,
            'action'       => $action,
            // subject_type is NOT NULL; misses/throttles have no distributor.
            'subject_type' => $distributorId !== null ? 'distributor' : 'find_my_id_attempt',
            'subject_id'   => $distributorId,
            'details'      => ['channel' => 'public_find_my_id'],
            'ip'           => $request->ip(),
        ]);
    }
}
