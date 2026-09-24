<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\PayoutBankFile;
use App\Modules\Compensation\Models\PayoutBankFileRow;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only way payout bank files reach or leave their disk.
 *
 * A NEFT file carries every payee's full account number, IFSC and name, and a
 * bank's response file often echoes them, so every object is PiiCrypter
 * ciphertext on the PII key — the same treatment as KYC scans and
 * offline-payment proofs (hard rule 8). Reads go through the audited admin
 * route only; the disk never issues a URL. Retention is the admin-owned
 * setting `payout.bank_file_retention_days`, enforced by
 * `payout:purge-expired-bank-files`.
 *
 * Fail-closed: when the object cannot be written, {@see keep()} throws and the
 * export or import is refused. The stored file is the evidence of what the
 * bank was told and what it answered; a bank file must not exist without it.
 *
 * Order of writes: the object first, then the database row. An object whose
 * row failed is deleted on a best-effort basis; the object is never written
 * while a row lock is held.
 */
final class PayoutBankFileVault
{
    public const string RETENTION_SETTING = 'payout.bank_file_retention_days';

    /** Eight years — the books-of-account period the payment records support. */
    public const int DEFAULT_RETENTION_DAYS = 2920;

    public const int MIN_RETENTION_DAYS = 365;

    /** Rows are written in slices so a 10,000-line batch is not one giant insert. */
    private const ROW_CHUNK = 500;

    public function disk(): Filesystem
    {
        return Storage::disk(PayoutBankFile::DISK);
    }

    /**
     * Encrypt and store a bank file, and record it against the batch.
     *
     * Every object gets its own key (a ULID in the name), so two identical
     * uploads never share an object: deleting or purging one can never take
     * the other with it.
     */
    public function keep(PayoutBatch $batch, string $direction, string $bytes, string $originalName, ?int $actorId): PayoutBankFile
    {
        $sha256 = hash('sha256', $bytes);
        $key = 'batch_'.$batch->id.'/'.$direction.'_'.substr($sha256, 0, 16).'_'.Str::lower((string) Str::ulid()).'.csv';

        $this->disk()->put($key, PiiCrypter::encryptString($bytes));

        try {
            return DB::transaction(function () use ($batch, $direction, $bytes, $originalName, $actorId, $sha256, $key): PayoutBankFile {
                $identicalTo = PayoutBankFile::query()
                    ->where('payout_batch_id', $batch->id)
                    ->where('direction', $direction)
                    ->where('sha256', $sha256)
                    ->orderBy('id')
                    ->value('id');

                return PayoutBankFile::create([
                    'payout_batch_id' => (int) $batch->id,
                    'direction' => $direction,
                    'storage_key' => $key,
                    'original_name' => mb_substr($originalName !== '' ? $originalName : 'bank-file.csv', 0, 255),
                    'size_bytes' => strlen($bytes),
                    'sha256' => $sha256,
                    'row_count' => 0,
                    'outcome' => PayoutBankFile::OUTCOME_APPLIED,
                    'identical_to_id' => $identicalTo !== null ? (int) $identicalTo : null,
                    'actor_id' => $actorId,
                ]);
            });
        } catch (Throwable $e) {
            try {
                $this->disk()->delete($key);
            } catch (Throwable $deleteFailure) {
                Log::error('Payout bank file: object left behind after its row failed to save', [
                    'payout_batch_id' => $batch->id,
                    'storage_key' => $key,
                    'error' => $deleteFailure->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Record what the file contained and what was done with it.
     *
     * @param  array<string, mixed>  $summary
     * @param  list<array{row_no: int, adn: string, payout_line_item_id: int|null, attempt?: int|null, bank_status?: string|null, verdict?: string|null, utr?: string|null, amount_paise?: int|null, reason?: string|null, result: string}>  $rows
     */
    public function finish(PayoutBankFile $file, string $outcome, array $summary, array $rows): PayoutBankFile
    {
        DB::transaction(function () use ($file, $outcome, $summary, $rows): void {
            foreach (array_chunk($rows, self::ROW_CHUNK) as $chunk) {
                PayoutBankFileRow::insert(array_map(static fn (array $row): array => [
                    'payout_bank_file_id' => (int) $file->id,
                    'row_no' => $row['row_no'],
                    'adn' => mb_substr($row['adn'], 0, 32),
                    'payout_line_item_id' => $row['payout_line_item_id'],
                    'attempt' => $row['attempt'] ?? null,
                    'bank_status' => isset($row['bank_status']) ? mb_substr($row['bank_status'], 0, 50) : null,
                    'verdict' => $row['verdict'] ?? null,
                    'utr' => isset($row['utr']) && $row['utr'] !== '' ? mb_substr($row['utr'], 0, 64) : null,
                    'amount_paise' => $row['amount_paise'] ?? null,
                    'reason' => isset($row['reason']) && $row['reason'] !== '' ? mb_substr($row['reason'], 0, 500) : null,
                    'result' => $row['result'],
                ], $chunk));
            }

            $file->forceFill([
                'outcome' => $outcome,
                'summary' => $summary,
                'row_count' => count($rows),
            ])->save();
        });

        return $file;
    }

    /** The plaintext bytes of a bank file, or null once purged or when the object is gone. */
    public function read(PayoutBankFile $file): ?string
    {
        if ($file->storage_key === null) {
            return null;
        }

        $disk = $this->disk();

        if (! $disk->exists($file->storage_key)) {
            return null;
        }

        $contents = $disk->get($file->storage_key);

        return $contents === null ? null : PiiCrypter::decryptString($contents);
    }

    /**
     * Undo a {@see keep()} whose file was never handed over or applied: the
     * row (and its rows) and the object both go. Best effort on the object.
     */
    public function discard(PayoutBankFile $file): void
    {
        $key = $file->storage_key;
        $file->delete();

        if ($key !== null) {
            try {
                $this->disk()->delete($key);
            } catch (Throwable $e) {
                Log::error('Payout bank file: object left behind after its record was discarded', [
                    'storage_key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    /**
     * How long a bank file is kept, from the download or upload. The settings
     * registry's floor is applied again here: a value written outside the
     * settings page must never erase a file the books still depend on.
     */
    public function retentionDays(): int
    {
        $value = DB::table('settings')->where('key', self::RETENTION_SETTING)->value('value');

        return max(self::MIN_RETENTION_DAYS, is_numeric($value) ? (int) $value : self::DEFAULT_RETENTION_DAYS);
    }
}
