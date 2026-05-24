<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DraftStateService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * WPM-01 .. WPM-04 — EnsureRegistrationProgress middleware behaviour
 * on the wizard step routes.
 *
 * Regression-locks the Bug 2 fix: removing `auth` from the wizard
 * route group must continue to mean wizard.progress is the SOLE gate
 * that decides what happens when an unauthenticated visitor hits a
 * mid-wizard URL. The middleware's three branches:
 *
 *  • av_draft cookie present + valid → loginUsingId + restore wizard
 *    state + pass through (this is the "Continue with registration"
 *    button on the draft-conflict screen for a user whose Laravel
 *    session expired)
 *  • Active wizard state in the session → pass through
 *  • Nothing → redirect to /login
 *
 * If anyone re-adds `Route::middleware(['auth'])->group(...)` around
 * the wizard step routes, WPM-01 fails because `auth` would bounce
 * to login BEFORE the middleware gets to read the cookie.
 */
function wpmSeedSponsorRoot(): int
{
    $user = User::create([
        'email' => 'wpm-sponsor-'.rand(10000, 99999).'@arovolife.local',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => null,
        'full_name' => 'Arovolife Private Limited',
        'status' => 'active',
        'activated_at' => now(),
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'aadhaar_ref' => 'WPM_ROOT',
            'aadhaar_last4' => '0000',
            'bank_account_enc' => null,
            'bank_ifsc' => null,
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update([
            'sponsor_id' => $id, 'placement_parent_id' => $id,
        ]);
    } finally {
        enableTestForeignKeys();
    }
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
    ]);

    return $id;
}

function wpmSeedPendingUserWithDraft(int $rootId, int $atStep = 3): array
{
    $user = User::create([
        'email' => 'wpm-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Mid-wizard Subject',
        'status' => 'pending',
    ]);

    $draft = app(DraftStateService::class)->create(
        $user->id,
        $rootId,
        $rootId,
        'L',
        ['sponsor_id' => $rootId, 'placement' => ['placement_id' => $rootId, 'side' => 'L']],
        $atStep,
    );

    return ['user' => $user, 'rawToken' => $draft->raw_token];
}

it('WPM-01: av_draft cookie + NO active session → middleware restores → page loads (NOT login redirect)', function (): void {
    // This is the Bug 2 scenario: user clicks "Continue with registration"
    // from the draft-conflict screen, their Laravel session has expired
    // but the av_draft cookie is still valid. Without the fix, the
    // `auth` route-group middleware would have bounced them to /login
    // BEFORE wizard.progress got to restore the session from the
    // cookie. We assert the page LOADS — confirming the auth gate is
    // no longer running before wizard.progress.
    $rootId = wpmSeedSponsorRoot();
    ['rawToken' => $rawToken, 'user' => $user] = wpmSeedPendingUserWithDraft($rootId, atStep: 3);

    $response = $this->withCookies(['av_draft' => $rawToken])
        ->get(route('register.orientation'));

    $response->assertStatus(200);
    // Authenticated session established by the middleware via loginUsingId.
    expect(auth()->id())->toBe($user->id);
});

it('WPM-02: NO session + NO cookie → middleware redirects to /join (not /login)', function (): void {
    // The fallback path — no way to identify which wizard belongs to the
    // visitor. A fresh user mid-wizard whose session vanished has no account
    // to log in to yet, so we send them to /join (the start of the funnel)
    // with a session-expired notice. The previous behaviour redirected to
    // /login, which stranded fresh users on a page they couldn't use.
    $response = $this->get(route('register.orientation'));
    $response->assertRedirect(route('join.show'));
});

it('WPM-03: active wizard session + correct step → passthrough', function (): void {
    // Regular case: the user is mid-flow with their session intact.
    $rootId = wpmSeedSponsorRoot();
    ['user' => $user] = wpmSeedPendingUserWithDraft($rootId, atStep: 3);

    $this->actingAs($user);
    app(WizardStateService::class)->start(
        userId: $user->id,
        sponsorId: $rootId,
        placementId: $rootId,
        sideOpt: 'L',
    );

    $response = $this->get(route('register.orientation'));
    $response->assertStatus(200);
});

it('WPM-04: garbage av_draft cookie (no matching draft) → middleware ignores it + bounces to /join', function (): void {
    // Defensive: a tampered or stale cookie value mustn't promote the
    // visitor into a stranger's session. Like WPM-02, the destination is
    // /join (the funnel start) rather than /login — a tampered cookie
    // doesn't imply the visitor has an account to log into.
    $response = $this->withCookies(['av_draft' => bin2hex(random_bytes(32))])
        ->get(route('register.orientation'));

    $response->assertRedirect(route('join.show'));
    expect(auth()->check())->toBeFalse();
});
