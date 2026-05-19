<?php

declare(strict_types=1);

/**
 * Returning-user authentication flow tests.
 *
 * When a user with an active draft POSTs to /register/account with their
 * email again (e.g. on a new browser / after clearing cookies), handleAccount()
 * must authenticate them — not try to create a second account.
 *
 * DRFT-001: correct password → redirect to the draft's current step, av_draft
 *           cookie set, no duplicate user row created.
 * DRFT-002: wrong password → redirect back with session error on 'password'.
 * DRFT-003: fully registered user (no active draft) → 'email' validation error.
 */

use App\Modules\Identity\Models\RegistrationDraft;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DraftStateService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// CSRF middleware is enabled on the web routes — disable per-test below so the
// POSTs from these tests aren't rejected with 419. The middleware itself is
// covered by Laravel's own tests; what we care about here is the returning-
// user authentication branch in RegistrationWizardController::handleAccount().

// ─── helpers ────────────────────────────────────────────────────────────────

/**
 * Seed a sponsor+placement distributor pair so the wizard intent can be
 * stashed into the session (handleAccount() exits early if intent() is null).
 *
 * Returns [sponsor_id, placement_id].
 *
 * @return array{sponsor_id: int, placement_id: int}
 */
function drftSeedDistributorRoot(): array
{
    $userId = DB::table('users')->insertGetId([
        'email' => 'sponsor-'.uniqid().'@test.com',
        'phone_e164' => '+919'.rand(100000000, 999999999),
        'password_hash' => bcrypt('password'),
        'password_set_at' => now(),
        'full_name' => 'Sponsor User',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        $adn = (string) rand(100000000, 999999999);

        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => $adn,
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => random_bytes(32),
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
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
            'sponsor_id' => $id,
            'placement_parent_id' => $id,
        ]);
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id,
        'descendant_id' => $id,
        'depth' => 0,
    ]);

    return ['sponsor_id' => $id, 'placement_id' => $id];
}

/**
 * Create a pending (non-distributor) user with a known password and an
 * active registration draft.
 *
 * @return array{user: User, rawToken: string, draft: RegistrationDraft}
 */
function drftSeedUserWithDraft(int $sponsorId, int $placementId, string $password = 'Zr9!mQwXvL#2023Test'): array
{
    $user = User::create([
        'full_name' => 'Test Returner',
        'email' => 'returner-'.uniqid().'@example.com',
        'phone_e164' => '+919'.rand(100000000, 999999999),
        'password_hash' => Hash::make($password),
        'password_set_at' => now(),
        'status' => 'pending',
    ]);

    $drafts = app(DraftStateService::class);
    $draft = $drafts->create($user->id, $sponsorId, $placementId, 'L', []);
    $rawToken = $draft->raw_token;

    return compact('user', 'rawToken', 'draft');
}

/**
 * Stash a wizard intent into the session so handleAccount() does not
 * immediately redirect to /contact-us.
 */
function drftStashIntent(int $sponsorId, int $placementId): void
{
    $wizard = app(WizardStateService::class);
    $wizard->stashIntent(
        sponsorId: $sponsorId,
        placementId: $placementId,
        sideOpt: 'L',
        extras: [
            'sponsor_adn' => '111111111',
            'placement_adn' => '111111111',
        ],
    );
}

/**
 * Generate a unique 10-digit Indian mobile number not already in the DB.
 * The returning-user POST form requires a phone that passes `unique:users,phone_e164`
 * — the phone field belongs to new-user creation and is irrelevant when the
 * returning-user block short-circuits the flow, but it must survive
 * Laravel validation first.
 */
function drftUniquePhone(): string
{
    do {
        $phone = '7'.str_pad((string) rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);
    } while (DB::table('users')->where('phone_e164', '+91'.$phone)->exists());

    return $phone;
}

/**
 * Fake the HaveIBeenPwned range API so StrongPassword-compliant test
 * passwords are not rejected by the NotPwned rule during HTTP requests.
 * Returns an empty list (no breach matches) for every prefix.
 */
function drftFakeHibp(): void
{
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);
}

// ─── DRFT-001 ────────────────────────────────────────────────────────────────

it('DRFT-001: correct password with active draft → redirects to saved step, sets av_draft cookie, no duplicate user', function (): void {
    drftFakeHibp();

    $dist = drftSeedDistributorRoot();
    $data = drftSeedUserWithDraft($dist['sponsor_id'], $dist['placement_id']);
    $user = $data['user'];
    $draft = $data['draft'];

    // Stash the wizard intent so handleAccount() does not bail early.
    drftStashIntent($dist['sponsor_id'], $dist['placement_id']);

    $expectedRoute = WizardStateService::stepRoute($draft->current_step);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post(route('register.post'), [
        'full_name' => $user->full_name,
        // Use a NEW phone number — the existing user's phone is already in
        // the DB and would fail unique:users,phone_e164 if sent. The
        // returning-user path authenticates by email+password; phone is
        // only relevant for brand-new account creation.
        'phone_e164' => drftUniquePhone(),
        'email' => $user->email,
        'password' => 'Zr9!mQwXvL#2023Test',
        'password_confirmation' => 'Zr9!mQwXvL#2023Test',
    ]);

    // Should redirect to the step the draft is currently on.
    $response->assertRedirect(route($expectedRoute));

    // The av_draft cookie must be set on the response.
    $response->assertCookie('av_draft');

    // No second user row must have been created.
    expect(User::where('email', $user->email)->count())->toBe(1);
});

// ─── DRFT-002 ────────────────────────────────────────────────────────────────

it('DRFT-002: wrong password with active draft → redirects back with session error on password field', function (): void {
    drftFakeHibp();

    $dist = drftSeedDistributorRoot();
    $data = drftSeedUserWithDraft($dist['sponsor_id'], $dist['placement_id']);
    $user = $data['user'];

    drftStashIntent($dist['sponsor_id'], $dist['placement_id']);

    // A password that is strong (zxcvbn score >= 3) but is NOT the user's
    // actual password — should be rejected by Hash::check in the returning-user
    // block, not by StrongPassword / NotPwned validation rules.
    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post(route('register.post'), [
        'full_name' => $user->full_name,
        'phone_e164' => drftUniquePhone(),
        'email' => $user->email,
        'password' => 'Zr9!mQwXvL#2023Wrong',
        'password_confirmation' => 'Zr9!mQwXvL#2023Wrong',
    ]);

    // Must redirect back (not forward to the wizard).
    $response->assertRedirect();

    // Session must carry a validation/error for the password field.
    $response->assertSessionHasErrors('password');
});

// ─── DRFT-003 ────────────────────────────────────────────────────────────────

it('DRFT-003: fully registered user (no active draft) → email validation error', function (): void {
    drftFakeHibp();

    $dist = drftSeedDistributorRoot();

    // Create a user WITHOUT any draft (simulates a fully-registered or
    // expired-draft account — no active draft row in registration_drafts).
    $user = User::create([
        'full_name' => 'Registered Person',
        'email' => 'registered-'.uniqid().'@example.com',
        'phone_e164' => '+919'.rand(100000000, 999999999),
        'password_hash' => Hash::make('Zr9!mQwXvL#2023Test'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);

    drftStashIntent($dist['sponsor_id'], $dist['placement_id']);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post(route('register.post'), [
        'full_name' => 'Any Name',
        'email' => $user->email,
        'phone_e164' => drftUniquePhone(),
        'password' => 'Zr9!mQwXvL#2023Test',
        'password_confirmation' => 'Zr9!mQwXvL#2023Test',
    ]);

    // Must redirect back with an error on the email field.
    $response->assertSessionHasErrors('email');
});
