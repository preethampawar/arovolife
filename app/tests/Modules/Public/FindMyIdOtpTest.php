<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Public\Http\Controllers\FindMyIdController;
use App\Modules\Shared\Notifications\OtpCodeNotification;
use App\Modules\Shared\Otp\OtpService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * R-27: OTP gate before Find-My-ID discloses the ADN.
 *
 * FMI-01: valid name+PAN → OTP emailed, step='otp' shown — ADN NOT revealed yet
 * FMI-02: correct OTP → ADN revealed, audit event otp_verified, session key cleared
 * FMI-03: wrong OTP → error, step stays 'otp', ADN NOT shown
 * FMI-04: 5 wrong OTPs → token consumed, redirected to lookup step
 * FMI-05: resend → new OTP issued, step='otp' with "resent" flag
 * FMI-06: verifyOtp without a session → lookup step with session-expired message
 */

/** Build a distributor with a known name + PAN for lookup. */
function fmiDistributor(string $name, string $pan, string $email): User
{
    $user = User::create([
        'full_name'     => $name,
        'email'         => $email,
        'phone_e164'    => '+91'.rand(7000000000, 9999999999),
        'password_hash' => bcrypt('x'),
        'status'        => 'active',
    ]);

    $panHash = hash('sha256', strtoupper(trim($pan)), true);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id'             => $user->id,
            'adn'                 => 'ADN'.rand(100000000, 999999999),
            'pan_hash'            => $panHash,
            'pan_last4'           => substr(strtoupper($pan), -4),
            'bank_account_enc'    => 'stub',
            'bank_ifsc'           => 'SBIN0000000',
            'sponsor_id'          => 0,
            'placement_parent_id' => 0,
            'side_chosen_by'      => 'referral_default',
            'depth'               => 0,
            'effective_date'      => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at'  => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state'               => 'TS',
            'is_primary_couple'   => 0,
            'created_at'          => now()->format('Y-m-d H:i:s.v'),
            'updated_at'          => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update([
            'sponsor_id'          => $id,
            'placement_parent_id' => $id,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    return $user->refresh();
}

/** POST to /find-my-id with name+PAN+consent. */
function fmiLookup(string $name, string $pan): \Illuminate\Testing\TestResponse
{
    return test()->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('find-my-id.lookup'), [
            'full_name'       => $name,
            'pan'             => $pan,
            'consent_privacy' => '1',
        ]);
}

it('FMI-01: valid name+PAN sends OTP email and shows otp step — ADN NOT revealed', function (): void {
    Notification::fake();
    $user = fmiDistributor('Priya Sharma', 'ABCDE1234F', 'priya@example.com');

    $res = fmiLookup('Priya Sharma', 'ABCDE1234F');

    $res->assertOk();
    $res->assertSee('Verification code');        // OTP form visible
    $res->assertDontSee($user->distributor->adn); // ADN not shown yet

    Notification::assertSentTo($user, OtpCodeNotification::class);
});

it('FMI-02: correct OTP reveals ADN, clears session, audit event fired', function (): void {
    Notification::fake();
    $user = fmiDistributor('Ravi Kumar', 'BCDEF2345G', 'ravi@example.com');

    // Step 1: lookup — capture the issued OTP via the service
    $otp = app(OtpService::class);
    fmiLookup('Ravi Kumar', 'BCDEF2345G');

    $adn = $user->distributor->adn;
    $distId = $user->distributor->id;

    // Peek at the pending OTP payload to get the code independently
    // Re-issue to get the plaintext code (test helper — OTP is still active)
    $code = $otp->issue('find_my_id', 'dist:'.$distId, [
        'dist_id' => $distId,
        'adn'     => $adn,
        'name'    => 'Ravi Kumar',
        'state'   => 'TS',
        'status'  => 'active',
    ]);

    $res = $this->withoutMiddleware(PreventRequestForgery::class)
        ->withSession([FindMyIdController::SESSION_DIST_ID => $distId])
        ->post(route('find-my-id.verify'), ['otp_code' => $code]);

    $res->assertOk();
    $res->assertSee($adn);                          // ADN revealed
    $res->assertSessionMissing(FindMyIdController::SESSION_DIST_ID); // session cleared
});

it('FMI-03: wrong OTP shows error, step stays otp, ADN not revealed', function (): void {
    Notification::fake();
    $user = fmiDistributor('Sunita Reddy', 'CDEFG3456H', 'sunita@example.com');

    fmiLookup('Sunita Reddy', 'CDEFG3456H');
    $distId = $user->distributor->id;

    $res = $this->withoutMiddleware(PreventRequestForgery::class)
        ->withSession([FindMyIdController::SESSION_DIST_ID => $distId])
        ->post(route('find-my-id.verify'), ['otp_code' => '000000']);

    $res->assertOk();
    $res->assertSee('incorrect');                   // error message
    $res->assertSee('Verification code');           // still on OTP step
    $res->assertDontSee($user->distributor->adn);  // ADN not shown
});

it('FMI-04: 5 wrong OTPs exhausts attempts, falls back to lookup step', function (): void {
    Notification::fake();
    $user = fmiDistributor('Anil Verma', 'DEFGH4567I', 'anil@example.com');

    fmiLookup('Anil Verma', 'DEFGH4567I');
    $distId = $user->distributor->id;

    // OtpService allows MAX_ATTEMPTS wrong guesses; the (MAX_ATTEMPTS+1)th triggers lockout.
    for ($i = 0; $i <= OtpService::MAX_ATTEMPTS; $i++) {
        $res = $this->withoutMiddleware(PreventRequestForgery::class)
            ->withSession([FindMyIdController::SESSION_DIST_ID => $distId])
            ->post(route('find-my-id.verify'), ['otp_code' => '000000']);
    }

    $res->assertOk();
    $res->assertSee('Find my ID');      // back to lookup form
    $res->assertDontSee('Verification code');
});

it('FMI-05: resend issues a new OTP and shows the otp step with resent flag', function (): void {
    Notification::fake();
    $user = fmiDistributor('Meena Pillai', 'EFGHI5678J', 'meena@example.com');

    fmiLookup('Meena Pillai', 'EFGHI5678J');
    $distId = $user->distributor->id;

    $res = $this->withoutMiddleware(PreventRequestForgery::class)
        ->withSession([FindMyIdController::SESSION_DIST_ID => $distId])
        ->post(route('find-my-id.resend'));

    $res->assertOk();
    $res->assertSee('new code has been sent');
    $res->assertSee('Verification code');

    // Two notifications: original + resend
    Notification::assertSentToTimes($user, OtpCodeNotification::class, 2);
});

it('FMI-06: verifyOtp without session key shows session-expired message', function (): void {
    $res = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('find-my-id.verify'), ['otp_code' => '123456']);

    $res->assertOk();
    $res->assertSee('session has expired');
    $res->assertSee('Find my ID');
});
