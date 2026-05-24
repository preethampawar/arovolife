<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * RUQ-01 .. RUQ-04 — phone + email uniqueness on the public
 * registration wizard's step-2 (Account) submission. Catches the
 * format-mismatch bug we shipped: the form posts phone as a
 * 10-digit string ("9876543210") but the DB stores E.164
 * ("+919876543210"), so the old `unique:users,phone_e164` rule
 * compared against the wrong format and never matched anything.
 * Duplicates fell through to a DB-level constraint violation
 * (500 error), not a friendly 422.
 */
function ruqStartIntent(int $sponsorDistributorId, int $placementDistributorId): void
{
    // Drop an intent into the wizard state so handleAccount doesn't
    // bounce us back to /contact-us before validation runs.
    app(WizardStateService::class)->stashIntent(
        sponsorId: $sponsorDistributorId,
        placementId: $placementDistributorId,
        sideOpt: null,
    );
}

function ruqSeedReservedTreeRoot(): int
{
    // Minimal reserved root so the wizard's intent stash + placement
    // lookups succeed.
    $user = User::create([
        'email' => 'root-'.rand(10000, 99999).'@arovolife.local',
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
            'aadhaar_ref' => 'RESERVED_ROOT',
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

function ruqSeedExistingUser(string $email, string $e164Phone): User
{
    return User::create([
        'email' => $email,
        'phone_e164' => $e164Phone,
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Existing User',
        'date_of_birth' => '1985-01-01',
        'status' => 'active',
        'activated_at' => now(),
    ]);
}

function ruqValidPayload(string $email, string $phone10, string $name = 'Test User'): array
{
    return [
        'full_name' => $name,
        'email' => $email,
        'phone_e164' => $phone10,
        'password' => 'Mango-Lotus-Forest-92a7Q',
        'password_confirmation' => 'Mango-Lotus-Forest-92a7Q',
    ];
}

it('RUQ-01: 10-digit duplicate phone is rejected with friendly error (not 500)', function (): void {
    $rootId = ruqSeedReservedTreeRoot();
    ruqSeedExistingUser('first@test.com', '+919876543210');

    ruqStartIntent($rootId, $rootId);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.post'), ruqValidPayload('newperson@test.com', '9876543210'));

    $response->assertRedirect();
    $response->assertSessionHasErrors('phone_e164');
    $errors = session('errors')->get('phone_e164');
    expect($errors[0] ?? '')->toContain('already exists');

    // Importantly: only the original user exists, no duplicate inserted.
    expect(DB::table('users')->where('phone_e164', '+919876543210')->count())->toBe(1);
});

it('RUQ-02: duplicate email with NO active draft is rejected (not 500)', function (): void {
    $rootId = ruqSeedReservedTreeRoot();
    ruqSeedExistingUser('claimed@test.com', '+919999911111');

    ruqStartIntent($rootId, $rootId);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.post'), ruqValidPayload('claimed@test.com', '9888877777'));

    $response->assertRedirect();
    $response->assertSessionHasErrors('email');
    expect(DB::table('users')->where('email', 'claimed@test.com')->count())->toBe(1);
});

it('RUQ-03: email comparison is case-insensitive — "Ravi@x.com" matches "ravi@x.com"', function (): void {
    $rootId = ruqSeedReservedTreeRoot();
    ruqSeedExistingUser('ravi@x.com', '+919876512345');

    ruqStartIntent($rootId, $rootId);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.post'), ruqValidPayload('Ravi@X.COM', '9777766666'));

    // The mixed-case input should be detected as the same email and
    // fall through to the "email taken" path (no active draft case).
    $response->assertRedirect();
    $response->assertSessionHasErrors('email');
    expect(DB::table('users')->whereRaw('LOWER(email) = ?', ['ravi@x.com'])->count())->toBe(1);
});

it('RUQ-04: new email + new phone is accepted and saved to session (user not created until step 10)', function (): void {
    $rootId = ruqSeedReservedTreeRoot();

    ruqStartIntent($rootId, $rootId);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.post'), ruqValidPayload('fresh@test.com', '9876509876'));

    $response->assertRedirect(route('register.orientation'));
    // In pure session-based flow, user is created at step 10, not step 2
    // Verify no user row was created yet
    $u = DB::table('users')->where('email', 'fresh@test.com')->first();
    expect($u)->toBeNull();
    // Verify session data was saved
    $response->assertSessionHas('registration_wizard');
});
