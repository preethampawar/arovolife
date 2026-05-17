<?php

declare(strict_types=1);

use App\Modules\Identity\Models\RegistrationDraft;
use App\Modules\Identity\Services\DraftStateService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function draftSeedUser(): int
{
    return (int) DB::table('users')->insertGetId([
        'email'           => 'draft-test-'.uniqid().'@example.com',
        'phone_e164'      => '+919'.rand(100000000, 999999999),
        'password_hash'   => bcrypt('secret'),
        'password_set_at' => now(),
        'full_name'       => 'Draft User',
        'status'          => 'pending',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

function makeDraftService(): DraftStateService
{
    return app(DraftStateService::class);
}

it('creates a draft and returns a raw token', function (): void {
    $userId = draftSeedUser();
    $service = makeDraftService();

    $rawToken = $service->create($userId, 1, 2, 'L', []);

    expect($rawToken)->toBeString()->toHaveLength(64);
    expect(RegistrationDraft::where('user_id', $userId)->exists())->toBeTrue();
});

it('replaces an existing draft when create is called again', function (): void {
    $userId = draftSeedUser();
    $service = makeDraftService();

    $service->create($userId, 1, 2, null, []);
    $service->create($userId, 3, 4, 'R', ['pan' => ['pan_number' => 'ABCDE1234F']]);

    expect(RegistrationDraft::where('user_id', $userId)->count())->toBe(1);
    $draft = RegistrationDraft::where('user_id', $userId)->first();
    expect($draft->sponsor_id)->toBe(3);
});

it('resolves a draft from a valid raw token', function (): void {
    $userId = draftSeedUser();
    $service = makeDraftService();

    $rawToken = $service->create($userId, 1, 2, null, []);
    $draft = $service->resolveFromToken($rawToken);

    expect($draft)->not->toBeNull();
    expect($draft->user_id)->toBe($userId);
});

it('returns null for an expired draft token', function (): void {
    $userId = draftSeedUser();
    $service = makeDraftService();

    $rawToken = $service->create($userId, 1, 2, null, []);
    RegistrationDraft::where('user_id', $userId)->update(['expires_at' => now()->subDay()]);

    expect($service->resolveFromToken($rawToken))->toBeNull();
});

it('returns null for an unknown token', function (): void {
    $service = makeDraftService();
    expect($service->resolveFromToken(bin2hex(random_bytes(32))))->toBeNull();
});

it('syncs step and payload on existing draft', function (): void {
    $userId = draftSeedUser();
    $service = makeDraftService();

    $service->create($userId, 1, 2, null, []);
    $service->sync($userId, 5, ['pan' => ['pan_number' => 'ABCDE1234F']]);

    $draft = RegistrationDraft::where('user_id', $userId)->first();
    expect($draft->current_step)->toBe(5);
    $payload = json_decode(Crypt::decryptString($draft->payload_enc), true);
    expect($payload['pan']['pan_number'])->toBe('ABCDE1234F');
});

it('findActiveByUserId returns null when no draft exists', function (): void {
    $userId = draftSeedUser();
    expect(makeDraftService()->findActiveByUserId($userId))->toBeNull();
});

it('findActiveByUserId returns the active draft', function (): void {
    $userId = draftSeedUser();
    $service = makeDraftService();
    $service->create($userId, 1, 2, null, []);

    $draft = $service->findActiveByUserId($userId);
    expect($draft)->not->toBeNull();
    expect($draft->user_id)->toBe($userId);
});

it('deletes the draft for a user', function (): void {
    $userId = draftSeedUser();
    $service = makeDraftService();
    $service->create($userId, 1, 2, null, []);

    $service->delete($userId);

    expect(RegistrationDraft::where('user_id', $userId)->exists())->toBeFalse();
});

it('purges expired drafts and returns the count', function (): void {
    $u1 = draftSeedUser();
    $u2 = draftSeedUser();
    $service = makeDraftService();

    $service->create($u1, 1, 2, null, []);
    $service->create($u2, 1, 2, null, []);

    RegistrationDraft::where('user_id', $u1)->update(['expires_at' => now()->subDay()]);

    $count = $service->purgeExpired();
    expect($count)->toBe(1);
    expect(RegistrationDraft::where('user_id', $u2)->exists())->toBeTrue();
});

it('restores wizard session from a draft', function (): void {
    $userId = draftSeedUser();
    $service = makeDraftService();

    $data = ['pan' => ['pan_number' => 'ABCDE1234F']];
    $service->create($userId, 10, 20, 'R', $data);
    $draft = RegistrationDraft::where('user_id', $userId)->first();

    $session = app(Session::class);
    $wizard  = new WizardStateService($session);
    $service->restoreToWizard($draft, $wizard);

    expect($wizard->sponsorId())->toBe(10);
    expect($wizard->placementId())->toBe(20);
    expect($wizard->getStepData(5))->toBe($data['pan'] ?? null);
});
