<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\OfflinePayment;
use App\Modules\Shared\Crypto\PiiCrypter;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The only way offline-payment proof files reach or leave their disk.
 *
 * A deposit slip carries the payer's account number, a UPI screenshot their
 * VPA, and a cash-deposit slip of ₹50,000 or more their full PAN (Rule 114B),
 * so every object is PiiCrypter ciphertext on the PII key — the same
 * treatment as KYC scans (KycDocumentVault, hard rule 8). Reads go through
 * the audited admin route only; the disk never issues a URL. Retention is two
 * admin-owned settings enforced by `commerce:purge-offline-payment-proofs`.
 */
final class OfflinePaymentProofVault
{
    public const string RETENTION_SETTING = 'commerce.offline_payment_proof_retention_days';

    public const string REJECTED_RETENTION_SETTING = 'commerce.offline_payment_rejected_proof_retention_days';

    /** Eight years — the books-of-account period the proof supports. */
    public const int DEFAULT_RETENTION_DAYS = 2920;

    /** A payment that was never confirmed backs no sale; keep its proof briefly. */
    public const int DEFAULT_REJECTED_RETENTION_DAYS = 90;

    public const int MIN_RETENTION_DAYS = 365;

    public const int MIN_REJECTED_RETENTION_DAYS = 30;

    /** @var array<string, string> */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function disk(): Filesystem
    {
        return Storage::disk(OfflinePayment::DISK);
    }

    /**
     * Encrypt and store an upload; returns the metadata to keep on the row.
     *
     * @return array{proof_storage_key: string, proof_original_name: string, proof_mime: string, proof_size_bytes: int, proof_sha256: string}
     */
    public function store(UploadedFile $file, string $idempotencyKey): array
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $sha256 = hash('sha256', $bytes);
        $mime = $this->mimeType($bytes);
        $key = 'proof_'.$idempotencyKey.'_'.substr($sha256, 0, 12).'.'.(self::EXTENSIONS[$mime] ?? 'bin');

        $this->disk()->put($key, PiiCrypter::encryptString($bytes));

        return [
            'proof_storage_key' => $key,
            'proof_original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'proof_mime' => $mime,
            'proof_size_bytes' => strlen($bytes),
            'proof_sha256' => $sha256,
        ];
    }

    /** The plaintext bytes of a proof, or null when there is none or the object is gone. */
    public function read(OfflinePayment $payment): ?string
    {
        if ($payment->proof_storage_key === null) {
            return null;
        }

        $disk = $this->disk();

        if (! $disk->exists($payment->proof_storage_key)) {
            return null;
        }

        $contents = $disk->get($payment->proof_storage_key);

        return $contents === null ? null : PiiCrypter::decryptString($contents);
    }

    /** Content type sniffed from the plaintext — the stored object is ciphertext. */
    public function mimeType(string $bytes): string
    {
        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($type) && $type !== '' ? $type : 'application/octet-stream';
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    /** How long the proof of a confirmed payment is kept, from confirmation. */
    public function retentionDays(): int
    {
        return $this->setting(self::RETENTION_SETTING, self::DEFAULT_RETENTION_DAYS, self::MIN_RETENTION_DAYS);
    }

    /** How long the proof of a rejected (or never-confirmed, cancelled) payment is kept. */
    public function rejectedRetentionDays(): int
    {
        return $this->setting(self::REJECTED_RETENTION_SETTING, self::DEFAULT_REJECTED_RETENTION_DAYS, self::MIN_REJECTED_RETENTION_DAYS);
    }

    /**
     * The same floors the settings registry enforces, applied again here: a
     * value written outside the settings page must never be able to erase a
     * proof the books still depend on.
     */
    private function setting(string $key, int $default, int $floor): int
    {
        $value = DB::table('settings')->where('key', $key)->value('value');

        return max($floor, is_numeric($value) ? (int) $value : $default);
    }
}
