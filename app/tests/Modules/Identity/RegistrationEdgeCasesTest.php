<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * REC-01 .. REC-05 — registration-flow edge cases that don't fit in
 * the dedicated test files (RegistrationFlowTest covers happy/sad
 * sponsor-resolution paths, PasswordPolicyTest covers password rules,
 * StateAgeRuleTest covers DOB checks, KycDocumentUploadTest covers
 * the docs step).
 *
 * Focus here: format/dedup gaps surfaced by manual production testing.
 */
function recSeedExistingDistributor(string $rawPan = 'TAKEN1234X'): int
{
    $user = User::create([
        'email' => 'taken-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Already Registered',
        'status' => 'active',
        'activated_at' => now(),
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => hash('sha256', $rawPan, true),
            'pan_last4' => substr($rawPan, -4),
            'pan_encrypted' => Crypt::encryptString($rawPan),
            'aadhaar_ref' => 'STUB_REC_'.uniqid(),
            'aadhaar_last4' => '0000',
            'aadhaar_encrypted' => Crypt::encryptString('123456789012'),
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

/** Seed a pending user with wizard state at the given step. */
function recSeedWizardAt(int $step, array $payload = []): User
{
    $user = User::create([
        'email' => 'rec-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Wizard Subject',
        'status' => 'pending',
    ]);
    test()->actingAs($user);
    test()->withSession([
        'registration_wizard' => array_replace_recursive([
            'step' => $step,
            'user_id' => $user->id,
            'sponsor_id' => 1,
            'data' => [
                'pan' => ['pan_number' => 'ABCDE1234F'],
                'aadhaar' => ['ref' => 'STUB', 'last4' => '9012'],
                'bank' => ['account_number' => null, 'ifsc' => null],
                'personal' => ['date_of_birth' => '1990-01-01', 'state' => 'TG'],
            ],
        ], $payload),
    ]);

    return $user;
}

// ── Edge cases ──────────────────────────────────────────────────────

it('REC-01: POST /register/account without prior intent redirects to /contact-us (no orphan user)', function (): void {
    $countBefore = DB::table('users')->count();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.post'), [
            'full_name' => 'No Intent User',
            'email' => 'no-intent@test.com',
            'phone_e164' => '9876543210',
            'password' => 'Mango-Lotus-Forest-92a7Q',
            'password_confirmation' => 'Mango-Lotus-Forest-92a7Q',
        ]);

    $response->assertRedirect('/contact-us?reason=referral_link_required');
    expect(DB::table('users')->count())->toBe($countBefore);
});

it('REC-02: duplicate PAN at step 5 rejected with friendly error (Hard rule #6)', function (): void {
    $rawPan = 'COMMN1234X';
    recSeedExistingDistributor($rawPan);
    recSeedWizardAt(5);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.pan'), ['pan_number' => $rawPan]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('pan_number');
    $err = session('errors')->get('pan_number')[0] ?? '';
    expect($err)->toContain('already exists');

    // PAN step data was NOT advanced.
    $wizard = app(WizardStateService::class);
    expect($wizard->getStepData(5)['pan_number'] ?? null)->not->toBe($rawPan);
});

it('REC-03: malformed PAN at step 5 returns 422 with format hint', function (): void {
    recSeedWizardAt(5);

    foreach (['ABCD1234X', 'ABCDE12345', '123456789A', 'ABCDEFGHIJ'] as $bad) {
        $response = $this->withoutMiddleware(PreventRequestForgery::class)
            ->post(route('register.pan'), ['pan_number' => $bad]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('pan_number');
        expect(session('errors')->get('pan_number')[0])->toContain('5 letters');
    }
});

it('REC-04: malformed Aadhaar at step 6 returns 422 — only exactly 12 digits accepted', function (): void {
    recSeedWizardAt(6);

    foreach (['12345678901', '1234567890123', '12345abc9012'] as $bad) {
        $response = $this->withoutMiddleware(PreventRequestForgery::class)
            ->post(route('register.aadhaar'), ['aadhaar_number' => $bad]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('aadhaar_number');
    }
});

it('REC-05: partial bank fill (account number but no IFSC) rejected at step 7', function (): void {
    recSeedWizardAt(7);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.bank'), [
            'account_number' => '123456789012',
            'ifsc' => '',
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('ifsc');
    $err = session('errors')->get('ifsc')[0] ?? '';
    expect($err)->toContain('IFSC');
});

it('REC-06: BOTH bank fields blank at step 7 is accepted (bank is optional)', function (): void {
    recSeedWizardAt(7);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.bank'), [
            'account_number' => '',
            'ifsc' => '',
        ]);

    $response->assertRedirect(route('register.personal'));
    $response->assertSessionHasNoErrors();

    // Wizard advances to step 7 (bank) with null values stored.
    $wizard = app(WizardStateService::class);
    $bank = $wizard->getStepData(7);
    expect($bank)->toMatchArray(['account_number' => null, 'ifsc' => null]);
});
