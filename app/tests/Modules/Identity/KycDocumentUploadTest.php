<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Step 7 of the registration wizard receives the actual identity & bank
 * documents. The runtime contract these tests lock:
 *  - All five doc types must be uploaded (PAN, Aadhaar, cheque, address front+back).
 *  - Bad MIME or oversize must be rejected and nothing written.
 *  - On success the wizard state holds disk paths + sha256 checksums for
 *    each doc, ready for RegistrationService::finalise to insert kyc_documents rows.
 */
function kycSeedSession(): User
{
    Storage::fake('kyc');

    $user = User::create([
        'email' => 'kyc-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'pending',
    ]);

    test()->actingAs($user);
    test()->withSession([
        'registration_wizard' => [
            // Documents is step 9 in the canonical 2026-05 order.
            'step' => 9,
            'user_id' => $user->id,
            'sponsor_id' => 1,
            'data' => [
                'pan' => ['pan_number' => 'ABCDE1234F'],
                'aadhaar' => ['ref' => 'STUB', 'last4' => '1234'],
                'bank' => ['account_number' => '912345678012', 'ifsc' => 'HDFC0001234'],
                'personal' => ['date_of_birth' => '1990-01-01', 'state' => 'TG', 'address' => '12 MG Road'],
            ],
        ],
    ]);

    return $user;
}

it('KYC-UP-01: step 7 accepts five required docs and persists paths to wizard state', function () {
    $user = kycSeedSession();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/documents', [
        'pan_doc' => UploadedFile::fake()->image('pan.jpg', 600, 400),
        'aadhaar_doc' => UploadedFile::fake()->image('aadhaar.jpg', 600, 400),
        'cheque_doc' => UploadedFile::fake()->image('cheque.jpg', 600, 400),
        'address_proof_front' => UploadedFile::fake()->image('addr_front.jpg', 600, 400),
        'address_proof_back' => UploadedFile::fake()->image('addr_back.jpg', 600, 400),
    ]);

    $response->assertRedirect('/register/complete');

    $wizard = app(WizardStateService::class);
    $docs = $wizard->getStepData(9)['documents'] ?? null;

    expect($docs)->not->toBeNull()
        ->and($docs)->toHaveKeys(['pan', 'aadhaar', 'cheque', 'address_proof_front', 'address_proof_back']);

    foreach (['pan', 'aadhaar', 'cheque', 'address_proof_front', 'address_proof_back'] as $type) {
        expect($docs[$type])->toHaveKeys(['path', 'sha256'])
            ->and(strlen($docs[$type]['sha256']))->toBe(64);
        Storage::disk('kyc')->assertExists($docs[$type]['path']);
    }
});

it('KYC-UP-02: rejects an executable file even if extension says image', function () {
    $user = kycSeedSession();

    // 'create' (not 'image') so the file body is plain text not a real JPG.
    // Note: Laravel's `mimetypes:` rule trusts the fake's declared mime in
    // tests, so the rejection here is driven by ValidUploadedDocumentBytes
    // (the magic-byte rule). In production, `mimetypes:` is also active and
    // catches this via finfo. Both layers protect against the same attack.
    $bogus = UploadedFile::fake()->create('pan.jpg', 50, 'image/jpeg');

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/documents', [
        'pan_doc' => $bogus,
        'aadhaar_doc' => UploadedFile::fake()->image('aadhaar.jpg', 600, 400),
        'cheque_doc' => UploadedFile::fake()->image('cheque.jpg', 600, 400),
        'address_proof_front' => UploadedFile::fake()->image('addr_front.jpg', 600, 400),
        'address_proof_back' => UploadedFile::fake()->image('addr_back.jpg', 600, 400),
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('pan_doc');

    // Documents step (9) must not have advanced.
    $wizard = app(WizardStateService::class);
    expect($wizard->getStepData(9))->toBeNull();
});

it('KYC-UP-03: rejects oversize file (>5 MB)', function () {
    $user = kycSeedSession();

    // Laravel's UploadedFile::fake()->image accepts width/height; for size we
    // use ->create + a JPG body via the dimensions trick. Here we use a fake
    // 6 MB stream typed image/jpeg.
    $oversize = UploadedFile::fake()->create('big.jpg', 6 * 1024, 'image/jpeg');

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/documents', [
        'pan_doc' => $oversize,
        'aadhaar_doc' => UploadedFile::fake()->image('aadhaar.jpg', 600, 400),
        'cheque_doc' => UploadedFile::fake()->image('cheque.jpg', 600, 400),
        'address_proof_front' => UploadedFile::fake()->image('addr_front.jpg', 600, 400),
        'address_proof_back' => UploadedFile::fake()->image('addr_back.jpg', 600, 400),
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('pan_doc');
});

it('KYC-UP-04: step 7 accepts ONLY PAN + Aadhaar (cheque + address-proof are optional)', function () {
    // Cancelled cheque + address-proof front/back were made optional —
    // the customer can supply them later via the dashboard or by the
    // admin via the pending-registration tool. Asserts the wizard now
    // advances when only the two required docs are attached.
    $user = kycSeedSession();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/documents', [
        'pan_doc' => UploadedFile::fake()->image('pan.jpg', 600, 400),
        'aadhaar_doc' => UploadedFile::fake()->image('aadhaar.jpg', 600, 400),
    ]);

    $response->assertRedirect('/register/complete');
    $response->assertSessionHasNoErrors();

    $wizard = app(WizardStateService::class);
    $docs = $wizard->getStepData(9)['documents'] ?? null;
    expect($docs)->not->toBeNull()
        ->and($docs)->toHaveKeys(['pan', 'aadhaar'])
        // The omitted optional fields must NOT appear as ghost keys.
        ->and($docs)->not->toHaveKey('cheque')
        ->and($docs)->not->toHaveKey('address_proof_front')
        ->and($docs)->not->toHaveKey('address_proof_back');
});

it('KYC-UP-05: step 7 still rejects when PAN is missing (PAN remains mandatory)', function () {
    $user = kycSeedSession();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/documents', [
        // pan_doc omitted on purpose
        'aadhaar_doc' => UploadedFile::fake()->image('aadhaar.jpg', 600, 400),
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('pan_doc');
});

it('KYC-UP-06: step 7 still rejects when Aadhaar is missing (Aadhaar remains mandatory)', function () {
    $user = kycSeedSession();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/documents', [
        'pan_doc' => UploadedFile::fake()->image('pan.jpg', 600, 400),
        // aadhaar_doc omitted on purpose
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('aadhaar_doc');
});

it('KYC-UP-07: a partial mix (PAN + Aadhaar + address-proof-front only) is accepted', function () {
    // Realistic case: customer has their address proof handy but not
    // a cancelled cheque. The wizard must let them progress with the
    // subset they have.
    $user = kycSeedSession();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/documents', [
        'pan_doc' => UploadedFile::fake()->image('pan.jpg', 600, 400),
        'aadhaar_doc' => UploadedFile::fake()->image('aadhaar.jpg', 600, 400),
        'address_proof_front' => UploadedFile::fake()->image('addr.jpg', 600, 400),
    ]);

    $response->assertRedirect('/register/complete');
    $docs = app(WizardStateService::class)->getStepData(9)['documents'] ?? [];
    expect($docs)->toHaveKeys(['pan', 'aadhaar', 'address_proof_front'])
        ->and($docs)->not->toHaveKey('cheque')
        ->and($docs)->not->toHaveKey('address_proof_back');
});
