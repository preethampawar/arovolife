<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Services;

use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Shared\Crypto\PiiCrypter;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The only way KYC scans reach or leave the `kyc` disk.
 *
 * Every object is written as PiiCrypter ciphertext (AES-256-CBC on the
 * dedicated PII key, ADR-0008), so the bucket holds nothing readable even if
 * its credentials leak. Reads go through the audited admin route only; the
 * disk never issues a URL. Retention is a published, admin-owned setting
 * (`kyc.document_retention_days`) enforced by `kyc:purge-expired-documents`
 * — client decision 2026-09-11, risk register R-31.
 */
final class KycDocumentVault
{
    public const string RETENTION_SETTING = 'kyc.document_retention_days';

    /** Eight years — the period `terms.md` §15 and `privacy.md` publish (R-54). */
    public const int DEFAULT_RETENTION_DAYS = 2920;

    public function disk(): Filesystem
    {
        return Storage::disk('kyc');
    }

    /** Encrypt an upload and store it under $key. */
    public function store(UploadedFile $file, string $key): void
    {
        $this->disk()->put($key, PiiCrypter::encryptString((string) file_get_contents($file->getRealPath())));
    }

    /**
     * The plaintext bytes of a document, or null when the object is gone.
     * A row without `encrypted_at` predates the vault and is served as-is
     * until `kyc:encrypt-documents` has converted it.
     */
    public function read(KycDocument $document): ?string
    {
        $disk = $this->disk();

        if (! $disk->exists($document->object_storage_key)) {
            return null;
        }

        $contents = $disk->get($document->object_storage_key);

        if ($contents === null) {
            return null;
        }

        return $document->encrypted_at === null ? $contents : PiiCrypter::decryptString($contents);
    }

    /** Content type sniffed from the plaintext — the stored object is ciphertext. */
    public function mimeType(string $bytes): string
    {
        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($type) && $type !== '' ? $type : 'application/octet-stream';
    }

    /**
     * Encrypt an object uploaded before the vault existed. Returns false when
     * the object is missing or already encrypted.
     */
    public function encryptLegacy(KycDocument $document): bool
    {
        if ($document->encrypted_at !== null) {
            return false;
        }

        $disk = $this->disk();

        if (! $disk->exists($document->object_storage_key)) {
            return false;
        }

        $plain = $disk->get($document->object_storage_key);

        if ($plain === null) {
            return false;
        }

        $disk->put($document->object_storage_key, PiiCrypter::encryptString($plain));
        $document->forceFill(['encrypted_at' => Carbon::now()])->save();

        return true;
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    /** How long a scan is kept after the review it served — from the settings page. */
    public function retentionDays(): int
    {
        $value = DB::table('settings')->where('key', self::RETENTION_SETTING)->value('value');

        $days = is_numeric($value) ? (int) $value : self::DEFAULT_RETENTION_DAYS;

        return max(1, $days);
    }
}
