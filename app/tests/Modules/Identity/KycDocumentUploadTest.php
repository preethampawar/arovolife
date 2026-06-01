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

it('KYC-UP-01: step 7 accepts all required docs (+ optional cheque) and persists paths to wizard state', function () {
    $user = kycSeedSession();

    // Required: PAN, Aadhaar front, Aadhaar back, address-proof front + back.
    // Cheque is optional but supplied here too.
    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/documents', [
        'pan_doc' => UploadedFile::fake()->image('pan.jpg', 600, 400),
        'aadhaar_doc' => UploadedFile::fake()->image('aadhaar.jpg', 600, 400),
        'aadhaar_back_doc' => UploadedFile::fake()->image('aadhaar_back.jpg', 600, 400),
        'cheque_doc' => UploadedFile::fake()->image('cheque.jpg', 600, 400),
        'address_proof_front' => UploadedFile::fake()->image('addr_front.jpg', 600, 400),
        'address_proof_back' => UploadedFile::fake()->image('addr_back.jpg', 600, 400),
    ]);

    $response->assertRedirect('/register/complete');

    $wizard = app(WizardStateService::class);
    $docs = $wizard->getStepData(9)['documents'] ?? null;

    expect($docs)->not->toBeNull()
        ->and($docs)->toHaveKeys(['pan', 'aadhaar', 'aadhaar_back', 'cheque', 'address_proof_front', 'address_proof_back']);

    foreach (['pan', 'aadhaar', 'aadhaar_back', 'cheque', 'address_proof_front', 'address_proof_back'] as $type) {
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

it('KYC-UP-04: step 7 accepts the required docs without the optional cheque', function () {
    // The cancelled cheque is the only OPTIONAL document. PAN, Aadhaar
    // (front + back) and address-proof (front + back) are all mandatory.
    // Asserts the wizard advances when every required doc is attached but
    // the optional cheque is omitted, and that cheque is not a ghost key.
    $user = kycSeedSession();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/documents', [
        'pan_doc' => UploadedFile::fake()->image('pan.jpg', 600, 400),
        'aadhaar_doc' => UploadedFile::fake()->image('aadhaar.jpg', 600, 400),
        'aadhaar_back_doc' => UploadedFile::fake()->image('aadhaar_back.jpg', 600, 400),
        'address_proof_front' => UploadedFile::fake()->image('addr_front.jpg', 600, 400),
        'address_proof_back' => UploadedFile::fake()->image('addr_back.jpg', 600, 400),
    ]);

    $response->assertRedirect('/register/complete');
    $response->assertSessionHasNoErrors();

    $wizard = app(WizardStateService::class);
    $docs = $wizard->getStepData(9)['documents'] ?? null;
    expect($docs)->not->toBeNull()
        ->and($docs)->toHaveKeys(['pan', 'aadhaar', 'aadhaar_back', 'address_proof_front', 'address_proof_back'])
        // The omitted optional cheque must NOT appear as a ghost key.
        ->and($docs)->not->toHaveKey('cheque');
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

it('KYC-UP-07: step 7 rejects an incomplete set missing a required address-proof side', function () {
    // Address proof (front AND back) is mandatory alongside PAN + Aadhaar
    // (front + back). Omitting the address-proof back side must be rejected
    // and must not advance the wizard — complements KYC-UP-05/06.
    $user = kycSeedSession();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/documents', [
        'pan_doc' => UploadedFile::fake()->image('pan.jpg', 600, 400),
        'aadhaar_doc' => UploadedFile::fake()->image('aadhaar.jpg', 600, 400),
        'aadhaar_back_doc' => UploadedFile::fake()->image('aadhaar_back.jpg', 600, 400),
        'address_proof_front' => UploadedFile::fake()->image('addr_front.jpg', 600, 400),
        // address_proof_back omitted on purpose — it is required.
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('address_proof_back');
    expect(app(WizardStateService::class)->getStepData(9))->toBeNull();
});
