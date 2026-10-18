<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Storage::fake('kyc');
});

function abandonedUpload(string $path, int $hoursAgo): void
{
    Storage::disk('kyc')->put($path, 'ciphertext');
    touch(Storage::disk('kyc')->path($path), now()->subHours($hoursAgo)->getTimestamp());
}

it('deletes only unclaimed reg_ folders past the window and audits counts, not session ids', function (): void {
    abandonedUpload('reg_0YSxMu8izzS0kvVx/pan_aaa.pdf', 30);
    abandonedUpload('reg_0YSxMu8izzS0kvVx/aadhaar_bbb.pdf', 30);
    abandonedUpload('reg_Completed123/pan_ccc.pdf', 30);
    abandonedUpload('reg_StillTyping9/pan_ddd.pdf', 1);
    abandonedUpload('reg_OneFreshFile/pan_eee.pdf', 30);
    abandonedUpload('reg_OneFreshFile/aadhaar_fff.pdf', 1);
    abandonedUpload('user_42/pan_ggg.pdf', 30);
    abandonedUpload('not-kyc/keep.txt', 30);

    KycDocument::create([
        'distributor_id' => Distributor::factory()->create()->id,
        'type' => 'pan',
        'object_storage_key' => 'reg_Completed123/pan_ccc.pdf',
        'checksum_sha256' => str_repeat("\xAA", 32),
    ]);

    $this->artisan('kyc:purge-abandoned-uploads')->assertExitCode(0);

    expect(Storage::disk('kyc')->directories())
        ->toEqualCanonicalizing(['reg_Completed123', 'reg_StillTyping9', 'reg_OneFreshFile', 'user_42', 'not-kyc']);

    $audit = AuditLog::query()->where('action', 'kyc.abandoned_uploads.purged')->sole();
    expect($audit->details)->toEqual(['folders' => 1, 'files' => 2, 'abandoned_after_hours' => 24])
        ->and(json_encode($audit->details))->not->toContain('0YSxMu8izzS0kvVx');
});

it('a dry run deletes nothing and writes no audit row', function (): void {
    abandonedUpload('reg_Abandoned1/pan_aaa.pdf', 30);

    $this->artisan('kyc:purge-abandoned-uploads', ['--dry-run' => true])->assertExitCode(0);

    expect(Storage::disk('kyc')->directories())->toBe(['reg_Abandoned1'])
        ->and(AuditLog::query()->where('action', 'kyc.abandoned_uploads.purged')->exists())->toBeFalse();
});

it('never lets --hours drop below the session lifetime', function (): void {
    config(['session.lifetime' => 120]);
    abandonedUpload('reg_MidWizard1/pan_aaa.pdf', 2);

    $this->artisan('kyc:purge-abandoned-uploads', ['--hours' => 0])->assertExitCode(0);

    expect(Storage::disk('kyc')->directories())->toBe(['reg_MidWizard1']);
});
