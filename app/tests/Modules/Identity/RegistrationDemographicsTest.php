<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * RD-01 .. RD-04 — Demographics step (step 6) of the registration wizard.
 *
 * Tests validation rules and happy-path session persistence. Happy-path
 * redirect goes to register.nominee (step 7 stub) which forwards to bank.
 */

/** Seed a pending user with wizard state at step 6 (demographics). */
function rdSeedWizardAt6(): User
{
    $user = User::create([
        'email' => 'rd-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Demo Subject',
        'status' => 'pending',
    ]);

    test()->actingAs($user);
    test()->withSession([
        'registration_wizard' => [
            'step' => 6,
            'sponsor_id' => 1,
            'placement_id' => 1,
            'side_opt' => null,
            'data' => [
                'account' => ['full_name' => 'Demo Subject', 'email' => $user->email, 'phone_e164' => $user->phone_e164, 'password_hash' => 'x'],
                'orientation' => ['quiz_passed' => true],
                'consent' => ['accepted' => true],
                'identity_documents' => ['pan_number' => 'ABCDE1234F', 'aadhaar_number' => '123456789012', 'last4' => '9012', 'ref' => 'STUB'],
            ],
        ],
    ]);

    return $user;
}

/** @return array<string, string> A complete valid demographics payload. */
function rdValidPayload(): array
{
    return [
        'gender' => 'male',
        'marital_status' => 'single',
        'highest_education' => 'graduate',
        'occupation' => 'Software Engineer',
        'mother_tongue' => 'Telugu',
        'additional_language_1' => 'English',
        'additional_language_2' => '',
    ];
}

it('RD-01: valid demographics submission advances wizard to step 7 and redirects to nominee', function (): void {
    rdSeedWizardAt6();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.demographics'), rdValidPayload());

    // Redirects to nominee stub (which itself forwards to bank — not followed here).
    $response->assertRedirect(route('register.nominee'));

    // Session step advanced to 7.
    $wizard = app(WizardStateService::class);
    expect($wizard->currentStep())->toBe(7);

    // Step 6 data was persisted.
    $saved = $wizard->getStepData(6);
    expect($saved['gender'])->toBe('male');
    expect($saved['mother_tongue'])->toBe('Telugu');
    expect($saved['additional_language_1'])->toBe('English');
});

it('RD-02: missing required fields return validation errors', function (): void {
    rdSeedWizardAt6();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.demographics'), []);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['gender', 'marital_status', 'highest_education', 'mother_tongue']);
});

it('RD-03: invalid enum values for gender and marital_status are rejected', function (): void {
    rdSeedWizardAt6();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('register.demographics'), [
            'gender' => 'other_invalid',
            'marital_status' => 'complicated',
            'highest_education' => 'graduate',
            'mother_tongue' => 'Telugu',
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['gender', 'marital_status']);
});

it('RD-04: GET /register/demographics renders the step 6 form', function (): void {
    rdSeedWizardAt6();

    $response = $this->get(route('register.demographics'));

    $response->assertOk();
    $response->assertSee('Demographics');
    $response->assertSee('Gender');
    $response->assertSee('Marital Status');
    $response->assertSee('Mother Tongue');
    $response->assertSee('This information personalises your experience', false);
});
