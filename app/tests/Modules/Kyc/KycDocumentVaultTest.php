<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\KycDocumentVault;
use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Storage::fake('kyc');
});

/** @param array<string, mixed> $extra */
function vaultDocument(int $distributorId, string $type, string $key, array $extra = []): KycDocument
{
    return KycDocument::create([
        'distributor_id' => $distributorId,
        'type' => $type,
        'object_storage_key' => $key,
        'checksum_sha256' => str_repeat("\xAA", 32),
    ] + $extra);
}

it('stores ciphertext and reads the plaintext back', function (): void {
    $dist = Distributor::factory()->create();
    $vault = app(KycDocumentVault::class);
    $bytes = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    $vault->store(UploadedFile::fake()->createWithContent('pan.pdf', $bytes), "user_{$dist->user_id}/pan.pdf");

    $stored = Storage::disk('kyc')->get("user_{$dist->user_id}/pan.pdf");
    expect($stored)->not->toBe($bytes)
        ->and(PiiCrypter::decryptString($stored))->toBe($bytes);

    $doc = vaultDocument($dist->id, 'pan', "user_{$dist->user_id}/pan.pdf", ['encrypted_at' => Carbon::now()]);

    expect($vault->read($doc))->toBe($bytes)
        ->and($vault->mimeType($bytes))->toBe('application/pdf');
});

it('serves an object uploaded before the vault as-is and converts it on demand', function (): void {
    $dist = Distributor::factory()->create();
    $vault = app(KycDocumentVault::class);
    Storage::disk('kyc')->put('legacy/cheque.jpg', 'plain-legacy-bytes');
    $doc = vaultDocument($dist->id, 'cheque', 'legacy/cheque.jpg');

    expect($vault->read($doc))->toBe('plain-legacy-bytes');

    expect(Artisan::call('kyc:encrypt-documents'))->toBe(0);

    $doc->refresh();
    expect($doc->encrypted_at)->not->toBeNull()
        ->and(Storage::disk('kyc')->get('legacy/cheque.jpg'))->not->toBe('plain-legacy-bytes')
        ->and($vault->read($doc))->toBe('plain-legacy-bytes');
});

it('returns null for a missing object instead of throwing', function (): void {
    $dist = Distributor::factory()->create();
    $doc = vaultDocument($dist->id, 'photo', 'gone/photo.jpg', ['encrypted_at' => Carbon::now()]);

    expect(app(KycDocumentVault::class)->read($doc))->toBeNull();
});

it('purges scans past the retention period from the settings and audits each distributor', function (): void {
    DB::table('settings')->insert([
        'key' => KycDocumentVault::RETENTION_SETTING,
        'value' => '30',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $dist = Distributor::factory()->create();
    $other = Distributor::factory()->create();

    foreach (['old_pan', 'old_pending', 'fresh_pan', 'other_old'] as $key) {
        Storage::disk('kyc')->put("k/{$key}.jpg", 'x');
    }
    $oldApproved = vaultDocument($dist->id, 'pan', 'k/old_pan.jpg', ['verified_at' => Carbon::now()->subDays(31)]);
    $oldPending = vaultDocument($dist->id, 'aadhaar', 'k/old_pending.jpg');
    KycDocument::query()->whereKey($oldPending->id)->update(['created_at' => Carbon::now()->subDays(40)]);
    $fresh = vaultDocument($dist->id, 'cheque', 'k/fresh_pan.jpg', ['verified_at' => Carbon::now()->subDays(29)]);
    $otherOld = vaultDocument($other->id, 'photo', 'k/other_old.jpg', ['verified_at' => Carbon::now()->subDays(400)]);

    expect(Artisan::call('kyc:purge-expired-documents', ['--dry-run' => true]))->toBe(0);
    expect(KycDocument::count())->toBe(4);

    expect(Artisan::call('kyc:purge-expired-documents'))->toBe(0);

    expect(KycDocument::pluck('id')->all())->toBe([$fresh->id]);
    Storage::disk('kyc')->assertMissing('k/old_pan.jpg');
    Storage::disk('kyc')->assertMissing('k/old_pending.jpg');
    Storage::disk('kyc')->assertMissing('k/other_old.jpg');
    Storage::disk('kyc')->assertExists('k/fresh_pan.jpg');

    $audits = AuditLog::where('action', 'kyc.documents.retention_purged')->orderBy('subject_id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('subject_id')->all())->toBe([$dist->id, $other->id])
        ->and($audits->first()->details['retention_days'])->toBe(30)
        ->and(array_column($audits->first()->details['documents'], 'id'))->toBe([$oldApproved->id, $oldPending->id])
        ->and($audits->last()->details['documents'][0]['id'])->toBe($otherOld->id);
});

it('falls back to the published eight-year period when the setting is absent', function (): void {
    expect(app(KycDocumentVault::class)->retentionDays())->toBe(KycDocumentVault::DEFAULT_RETENTION_DAYS);
});
