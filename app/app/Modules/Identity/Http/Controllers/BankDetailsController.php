<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Compensation\Exceptions\BankDecryptionException;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Identity\Http\Requests\BankDetailsRequest;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\BankDetailsUpdatedNotification;
use App\Modules\Shared\Crypto\PiiCrypter;
use App\Modules\Shared\Notifications\OtpCodeNotification;
use App\Modules\Shared\Otp\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Throwable;

/**
 * Self-service bank details (QA F70, client decision 2026-09-11).
 *
 * Bank capture is optional at registration, and until now the only way to add
 * or correct it afterwards was for an admin to key it in — so a distributor who
 * skipped the wizard's bank step sat on `no_bank_account` indefinitely while
 * their income accrued unpaid. This page is that missing path.
 *
 * Three things make it safe to hand to the distributor:
 *
 *  - The account number is typed twice and must match ({@see BankDetailsRequest}).
 *  - The change is OTP-gated exactly like a mobile/email change
 *    ({@see ProfileController}): nothing is written until the emailed code is
 *    confirmed, so a hijacked session cannot silently redirect payouts.
 *  - Everything written is PiiCrypter ciphertext, and only the last 4 digits
 *    ever leave the server — in the page, the audit row, or the email.
 *
 * While the code is pending, the account number lives in the OTP payload as
 * ciphertext, never as plaintext: that payload is cached (Redis in production)
 * and a raw account number has no business being there.
 *
 * No payout hold is released from here. Holds are re-read from live state when
 * a batch is (re-)run and again at approval (see PayoutService's
 * `releaseClearedHolds()`, QA F93), so a `no_bank_account` line clears itself
 * on the next batch touch — there is nothing for this controller to call and
 * nothing for it to reimplement.
 */
final class BankDetailsController extends Controller
{
    /** OTP scope for a self-service bank-details change. */
    private const OTP_PURPOSE = 'bank_details_change';

    public function show(): View
    {
        [$user, $distributor] = $this->actor();

        return view('profile.bank', [
            'user' => $user,
            'distributor' => $distributor,
            'bankLast4' => $this->bankLast4($distributor),
            'beneficiaryName' => $this->beneficiaryName($distributor),
        ]);
    }

    /**
     * Validate the submitted details, hold them as ciphertext behind an OTP,
     * and email the code. Nothing is written to the distributor row yet.
     */
    public function update(BankDetailsRequest $request, OtpService $otp): RedirectResponse
    {
        [$user, $distributor] = $this->actor();

        $data = $request->validated();
        $accountNumber = (string) $data['account_number'];

        $code = $otp->issue(self::OTP_PURPOSE, (string) $user->id, [
            // Ciphertext, not the number: this payload sits in the cache.
            'account_enc' => PiiCrypter::encryptString($accountNumber),
            'beneficiary_name_enc' => PiiCrypter::encryptString((string) $data['beneficiary_name']),
            'ifsc' => (string) $data['ifsc'],
            'bank_name' => $data['bank_name'] ?? null,
            'account_last4' => mb_substr($accountNumber, -4),
        ]);

        Notification::route('mail', $user->email)->notify(
            new OtpCodeNotification($code, 'update your arovolife bank details'),
        );

        $this->audit($user, $distributor, 'distributor.bank_details_otp_sent', [
            'ifsc' => $data['ifsc'],
            'account_last4' => mb_substr($accountNumber, -4),
        ]);

        return redirect()->route('profile.bank.show')
            ->with('bank_otp', ['email_masked' => $this->maskEmail((string) $user->email)]);
    }

    /** Confirm the emailed code and only then write the new bank details. */
    public function confirmOtp(Request $request, OtpService $otp): RedirectResponse
    {
        [$user, $distributor] = $this->actor();

        $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'otp.regex' => 'Enter the 6-digit code we emailed you.',
        ]);

        $result = $otp->verify(self::OTP_PURPOSE, (string) $user->id, $request->string('otp')->toString());

        if (! $result->ok) {
            $redirect = redirect()->route('profile.bank.show')->withErrors(['otp' => $result->message()]);
            // Keep the modal open for a retry while a code is still pending.
            if ($otp->peek(self::OTP_PURPOSE, (string) $user->id) !== null) {
                $redirect->with('bank_otp', ['email_masked' => $this->maskEmail((string) $user->email)]);
            }

            return $redirect;
        }

        /** @var array{account_enc: string, beneficiary_name_enc: string, ifsc: string, bank_name: string|null, account_last4: string} $pending */
        $pending = $result->payload;

        $before = $this->stateLabel($distributor);

        $distributor->update([
            'bank_account_enc' => $pending['account_enc'],
            'bank_beneficiary_name_enc' => $pending['beneficiary_name_enc'],
            'bank_ifsc' => $pending['ifsc'],
            'bank_name' => $pending['bank_name'],
        ]);

        $after = $this->stateLabel($distributor->refresh());

        AuditLog::create([
            'actor_id' => $user->id,
            'action' => 'distributor.bank_details_updated',
            'subject_type' => 'distributor',
            'subject_id' => $distributor->id,
            // NEVER the account number — only its last 4, as the admin bank
            // edit path also records it.
            'details' => [
                'ifsc' => $pending['ifsc'],
                'bank_name' => $pending['bank_name'],
                'account_last4' => $pending['account_last4'],
                'via' => 'self_service_otp',
            ],
            'before_hash' => AuditDigests::of($before),
            'after_hash' => AuditDigests::of($after),
            'ip' => $request->ip(),
        ]);

        $user->notify(new BankDetailsUpdatedNotification(
            accountLast4: $pending['account_last4'],
            ifsc: $pending['ifsc'],
        ));

        return redirect()->route('profile.bank.show')
            ->with('status', 'Your bank details have been updated. Payouts will use them from the next payout run.');
    }

    /** Resend the OTP for the still-pending bank change (rate-limited). */
    public function resendOtp(OtpService $otp): RedirectResponse
    {
        [$user] = $this->actor();

        $pending = $otp->peek(self::OTP_PURPOSE, (string) $user->id);
        if ($pending === null) {
            return redirect()->route('profile.bank.show');
        }

        $rlKey = 'bank-otp-resend:'.$user->id;
        if (RateLimiter::tooManyAttempts($rlKey, maxAttempts: 3)) {
            return redirect()->route('profile.bank.show')
                ->withErrors(['otp' => 'Too many code requests. Please wait a few minutes and try again.'])
                ->with('bank_otp', ['email_masked' => $this->maskEmail((string) $user->email)]);
        }
        RateLimiter::hit($rlKey, decaySeconds: 600);

        $code = $otp->issue(self::OTP_PURPOSE, (string) $user->id, $pending);
        Notification::route('mail', $user->email)->notify(
            new OtpCodeNotification($code, 'update your arovolife bank details'),
        );

        return redirect()->route('profile.bank.show')
            ->with('status', 'A new code has been sent.')
            ->with('bank_otp', ['email_masked' => $this->maskEmail((string) $user->email)]);
    }

    /**
     * The signed-in distributor, or a 403. Bank details belong to a
     * distributor record; a staff account has none.
     *
     * @return array{0: User, 1: Distributor}
     */
    private function actor(): array
    {
        /** @var User|null $user */
        $user = Auth::user();
        abort_if($user === null, 401);
        $distributor = $user->distributor;
        abort_if($distributor === null, 403, 'Only a distributor has bank details.');

        return [$user, $distributor];
    }

    /** Last 4 of the account on file, or null when there is none / it will not decrypt. */
    private function bankLast4(Distributor $distributor): ?string
    {
        if (blank($distributor->bank_account_enc)) {
            return null;
        }

        try {
            return app(PayoutService::class)->bankLast4ForDistributor($distributor->id);
        } catch (BankDecryptionException) {
            return null;
        }
    }

    /** The account holder's name on file, or null when unreadable / not yet captured. */
    private function beneficiaryName(Distributor $distributor): ?string
    {
        $raw = $distributor->bank_beneficiary_name_enc;
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return PiiCrypter::decryptString((string) $raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The audited state of the bank details — masked, because a digest of a
     * 9-18 digit account number is a digest anyone can brute-force.
     */
    private function stateLabel(Distributor $distributor): string
    {
        return implode('|', [
            $distributor->bank_ifsc ?? '',
            $distributor->bank_name ?? '',
            $this->bankLast4($distributor) ?? '',
            $this->beneficiaryName($distributor) === null ? 'no-beneficiary' : 'beneficiary',
        ]);
    }

    /** ravikumar@gmail.com → r•••••••@gmail.com (for on-screen confirmation copy). */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            return '•••';
        }
        [$local, $domain] = $parts;

        return mb_substr($local, 0, 1).str_repeat('•', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(User $user, Distributor $distributor, string $action, array $details): void
    {
        // Sending the OTP moves nothing: the bank details on file stand until
        // the code is confirmed, and the matching digests say exactly that.
        $state = AuditDigests::of($this->stateLabel($distributor));

        AuditLog::create([
            'actor_id' => $user->id,
            'action' => $action,
            'subject_type' => 'distributor',
            'subject_id' => $distributor->id,
            'before_hash' => $state,
            'after_hash' => $state,
            'details' => $details,
            'ip' => request()->ip(),
        ]);
    }
}
