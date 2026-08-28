<?php

declare(strict_types=1);

use App\Modules\Genealogy\Services\DTOs\PlacementResult;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RegistrationService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * RN-01 .. RN-09 — Nominee step (step 7) of the registration wizard.
 *
 * Tests guard behaviour, skip path, validation rules, session persistence,
 * and handleComplete() nominee row creation.
 */

/** Create a pending user and seed wizard state at step 7 (nominee). */
function rnSeedWizardAt7(): User
{
    $user = User::create([
        'email' => 'rn-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Nominee Subject',
        'status' => 'pending',
    ]);

    test()->actingAs($user);
    test()->withSession([
        'registration_wizard' => [
            'step' => 7,
            'sponsor_id' => 1,
            'placement_id' => 1,
            'side_opt' => null,
            'data' => [
                'account' => [
                    'full_name' => 'Nominee Subject',
                    'email' => $user->email,
                    'phone_e164' => $user->phone_e164,
                    'password_hash' => 'x',
                ],
                'orientation' => ['quiz_passed' => true],
                'consent' => ['accepted' => true],
                'identity_documents' => [
                    'pan_number' => 'ABCDE1234F',
                    'aadhaar_number' => '123456789012',
                    'last4' => '9012',
                    'ref' => 'STUB',
                ],
                'demographics' => [
                    'gender' => 'male',
                    'marital_status' => 'single',
                    'highest_education' => 'graduate',
                    'occupation' => null,
                    'mother_tongue' => 'Telugu',
                    'additional_language_1' => null,
                    'additional_language_2' => null,
                ],
            ],
        ],
    ]);

    return $user;
}

/** Seed a root distributor row for handleComplete() integration tests. */
function rnSeedDistributor(int $userId): int
{
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '1234',
            'bank_account_enc' => null,
            'bank_ifsc' => null,
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
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id,
        'descendant_id' => $id,
        'depth' => 0,
    ]);

    return $id;
}

/** Return a valid nominee form payload. */
function rnValidPayload(): array
{
    return [
        'full_name' => 'Jane Doe',
        'relationship' => 'spouse',
        'date_of_birth' => '1990-06-15',
        'pan_number' => '',
        'aadhaar' => '',
        'mobile' => '9876543210',
        'email' => '',
        'address' => '',
        'consent' => '1',
    ];
}

it('RN-01: guest POST to /register/nominee is rejected — redirected away from nominee', function (): void {
    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.nominee.submit'), rnValidPayload());

    // Guest has no wizard session — EnsureRegistrationProgress redirects to
    // /join (not /login) since the guest may not have an account yet.
    $response->assertRedirect();
    $target = $response->headers->get('location', '');
    expect($target)->not->toContain('nominee');
});

it('RN-02: authenticated user at wrong wizard step redirects to correct step', function (): void {
    $user = User::create([
        'email' => 'rn-wrong-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Wrong Step',
        'status' => 'pending',
    ]);

    // Wizard at step 4 (consent), not step 7
    $this->actingAs($user)->withSession([
        'registration_wizard' => [
            'step' => 4,
            'sponsor_id' => 1,
            'placement_id' => 1,
            'side_opt' => null,
            'data' => [
                'account' => ['full_name' => 'x', 'email' => 'x@x.com', 'phone_e164' => '+919876543210', 'password_hash' => 'x'],
                'orientation' => ['quiz_passed' => true],
            ],
        ],
    ]);

    $response = $this->get(route('register.nominee'));

    // wizard.progress middleware redirects to the correct step (consent here)
    $response->assertRedirect();
    expect($response->headers->get('location', ''))->not->toContain('nominee');
});

it('RN-03: skip button advances wizard step and redirects to register.bank', function (): void {
    rnSeedWizardAt7();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.nominee.submit'), ['skip' => '1']);

    $response->assertRedirect(route('register.bank'));

    $wizard = app(WizardStateService::class);
    $nomineeData = $wizard->getStepData(7);
    expect($nomineeData)->not->toBeNull();
    expect($nomineeData['skipped'])->toBeTrue();
    // Step must have advanced beyond 7
    expect($wizard->currentStep())->toBeGreaterThanOrEqual(8);
});

it('RN-04: valid nominee submission persists session data and redirects to register.bank', function (): void {
    rnSeedWizardAt7();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.nominee.submit'), rnValidPayload());

    $response->assertRedirect(route('register.bank'));

    $wizard = app(WizardStateService::class);
    $saved = $wizard->getStepData(7);
    expect($saved)->not->toBeNull();
    expect($saved['full_name'])->toBe('Jane Doe');
    expect($saved['relationship'])->toBe('spouse');
    expect($saved['date_of_birth'])->toBe('1990-06-15');
    expect($saved)->toHaveKey('consent_given_at');
    expect(isset($saved['skipped']))->toBeFalse();
});

it('RN-05: missing required fields return validation errors', function (): void {
    rnSeedWizardAt7();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.nominee.submit'), []);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['full_name', 'relationship', 'date_of_birth', 'consent']);
});

it('RN-06: invalid PAN format returns a validation error', function (): void {
    rnSeedWizardAt7();

    $payload = array_merge(rnValidPayload(), ['pan_number' => 'INVALID']);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.nominee.submit'), $payload);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['pan_number']);
});

it('RN-07: Aadhaar with 11 digits fails validation', function (): void {
    rnSeedWizardAt7();

    $payload = array_merge(rnValidPayload(), ['aadhaar' => '12345678901']); // 11 digits

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.nominee.submit'), $payload);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['aadhaar']);
});

it('RN-08: handleComplete() with nominee data creates a distributor_nominees row', function (): void {
    $user = User::create([
        'email' => 'rn-complete-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Complete Subject',
        'status' => 'pending',
    ]);

    $distributorId = rnSeedDistributor($user->id);

    // Mock RegistrationService to avoid full tree placement
    $mock = Mockery::mock(RegistrationService::class);
    $mock->shouldReceive('finalise')->andReturn(new PlacementResult(
        distributorId: $distributorId,
        userId: $user->id,
        parentId: $distributorId,
        side: 'L',
        depth: 1,
        sideChosenBy: 'test',
    ));
    $this->app->instance(RegistrationService::class, $mock);

    $this->actingAs($user)->withSession([
        'registration_wizard' => [
            'step' => 12,
            'sponsor_id' => $distributorId,
            'placement_id' => $distributorId,
            'side_opt' => null,
            'data' => [
                'account' => [
                    'full_name' => 'Complete Subject',
                    'email' => $user->email,
                    'phone_e164' => $user->phone_e164,
                    'password_hash' => bcrypt('placeholder'),
                ],
                'orientation' => ['quiz_passed' => true],
                'consent' => ['accepted' => true],
                'identity_documents' => [
                    'pan_number' => 'ABCDE1234F',
                    'aadhaar_number' => '123456789012',
                    'last4' => '9012',
                    'ref' => 'STUB',
                ],
                'demographics' => [
                    'gender' => 'male',
                    'marital_status' => 'single',
                    'highest_education' => 'graduate',
                    'occupation' => null,
                    'mother_tongue' => 'Telugu',
                    'additional_language_1' => null,
                    'additional_language_2' => null,
                ],
                'nominee' => [
                    'full_name' => 'Jane Doe',
                    'relationship' => 'spouse',
                    'date_of_birth' => '1990-06-15',
                    'pan_number' => null,
                    'aadhaar_last4' => '4321',
                    'aadhaar_raw' => '123456784321',
                    'mobile' => null,
                    'email' => null,
                    'address' => null,
                    'consent_given_at' => now()->toDateTimeString(),
                ],
                'bank' => ['account_number' => null, 'ifsc' => null],
                'personal' => [
                    'date_of_birth' => '1990-01-01',
                    'state' => 'TG',
                    'address' => '123 Main Street',
                    'couple_enabled' => false,
                    'spouse' => null,
                ],
                'documents' => ['documents' => []],
            ],
        ],
    ]);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.complete'));

    expect(DB::table('distributor_nominees')
        ->where('distributor_id', $distributorId)
        ->exists()
    )->toBeTrue();

    $nominee = DB::table('distributor_nominees')
        ->where('distributor_id', $distributorId)
        ->first();

    expect($nominee->full_name)->toBe('Jane Doe');
    expect($nominee->relationship)->toBe('spouse');
    expect($nominee->aadhaar_last4)->toBe('4321');
});

it('RN-09: handleComplete() with skipped nominee creates no distributor_nominees row', function (): void {
    $user = User::create([
        'email' => 'rn-skip-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Skip Subject',
        'status' => 'pending',
    ]);

    $distributorId = rnSeedDistributor($user->id);

    $mock = Mockery::mock(RegistrationService::class);
    $mock->shouldReceive('finalise')->andReturn(new PlacementResult(
        distributorId: $distributorId,
        userId: $user->id,
        parentId: $distributorId,
        side: 'L',
        depth: 1,
        sideChosenBy: 'test',
    ));
    $this->app->instance(RegistrationService::class, $mock);

    $this->actingAs($user)->withSession([
        'registration_wizard' => [
            'step' => 12,
            'sponsor_id' => $distributorId,
            'placement_id' => $distributorId,
            'side_opt' => null,
            'data' => [
                'account' => [
                    'full_name' => 'Skip Subject',
                    'email' => $user->email,
                    'phone_e164' => $user->phone_e164,
                    'password_hash' => bcrypt('placeholder'),
                ],
                'orientation' => ['quiz_passed' => true],
                'consent' => ['accepted' => true],
                'identity_documents' => [
                    'pan_number' => 'ABCDE1234F',
                    'aadhaar_number' => '123456789012',
                    'last4' => '9012',
                    'ref' => 'STUB',
                ],
                'demographics' => [
                    'gender' => 'male',
                    'marital_status' => 'single',
                    'highest_education' => 'graduate',
                    'occupation' => null,
                    'mother_tongue' => 'Telugu',
                    'additional_language_1' => null,
                    'additional_language_2' => null,
                ],
                'nominee' => ['skipped' => true],
                'bank' => ['account_number' => null, 'ifsc' => null],
                'personal' => [
                    'date_of_birth' => '1990-01-01',
                    'state' => 'TG',
                    'address' => '123 Main Street',
                    'couple_enabled' => false,
                    'spouse' => null,
                ],
                'documents' => ['documents' => []],
            ],
        ],
    ]);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.complete'));

    expect(DB::table('distributor_nominees')
        ->where('distributor_id', $distributorId)
        ->exists()
    )->toBeFalse();
});
