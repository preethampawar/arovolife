<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Backlog #10: the Step-10 "Review & Finalise" page now shows the full review
 * INLINE (account / personal / KYC / bank / placement / documents) instead of
 * behind a popup. PII stays masked to last-4; the cooling-off copy and
 * "free of charge" wording are preserved (hard rules #5, #1, #8).
 */
function rriSetWizardAtComplete(User $user): void
{
    test()->actingAs($user);
    test()->withSession([
        'registration_wizard' => [
            'step' => 10,
            'user_id' => $user->id,
            'sponsor_id' => 1,
            'data' => [
                'account' => ['full_name' => 'Riya Reviewer', 'email' => $user->email, 'phone_e164' => $user->phone_e164],
                'pan' => ['pan_number' => 'RRIAB1234X'],
                'aadhaar' => ['ref' => 'STUB', 'last4' => '9012', 'aadhaar_number' => '000000009012'],
                'bank' => ['account_number' => '000123456789', 'ifsc' => 'HDFC0001234'],
                'personal' => ['date_of_birth' => '1990-01-01', 'state' => 'TG'],
                'consent' => ['accepted' => true],
                'orientation' => ['watched' => true],
                'documents' => ['documents' => []],
                'placement' => ['placement_id' => 1, 'side' => null],
            ],
        ],
    ]);
}

it('RRI-01: the review page renders all sections inline (no popup dialog)', function (): void {
    $user = User::create([
        'full_name' => 'Riya Reviewer',
        'email' => 'rri-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'status' => 'pending',
    ]);

    rriSetWizardAtComplete($user);

    $response = $this->get('/register/complete');

    $response->assertOk();
    $response->assertSee('Review & Finalise', false);

    // All review sections are present inline.
    $response->assertSee('Account');
    $response->assertSee('Personal');
    $response->assertSee('KYC');
    $response->assertSee('Bank');
    $response->assertSee('Sponsor');
    $response->assertSee('Riya Reviewer');

    // PII is masked to last-4 (hard rule #8).
    $response->assertSee('XXXXXX234X');             // PAN: XXXXXX + last-4 of RRIAB1234X
    $response->assertSee('XXXX-XXXX-9012');         // Aadhaar masked
    $response->assertSee('XXXX6789');               // bank account masked

    // Compliance copy preserved.
    $response->assertSee('30-Day Cooling-Off Period');
    $response->assertSee('Registration is free of charge', false);

    // The old popup is gone: no <dialog> review modal, no open-trigger button.
    $response->assertDontSee('finalise-preview-modal');
    $response->assertDontSee('open-finalise-preview');

    // The confirm button now submits the form directly.
    $response->assertSee('id="finalise-submit"', false);
});
