<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts distributor PII (pan/aadhaar/bank) onto the dedicated PII key
 * (ADR-0008). Reads each value via {@see PiiCrypter} — which falls back to
 * APP_KEY / APP_PREVIOUS_KEYS — and rewrites it under the PII primary key.
 *
 * Idempotent and safe to re-run. Values that cannot be decrypted with any
 * available key (e.g. ciphertext copied from an environment whose key we don't
 * hold) are left untouched and reported; recover those by admin re-entry. No
 * PII is ever printed.
 */
final class ReencryptPiiCommand extends Command
{
    protected $signature = 'pii:reencrypt {--force : Run even when PII_ENCRYPTION_KEY is not set (no-op rotation under APP_KEY)}';

    protected $description = 'Re-encrypt distributor PII (PAN/Aadhaar/bank) onto the dedicated PII key';

    /** @var list<string> */
    private const COLUMNS = ['pan_encrypted', 'aadhaar_encrypted', 'bank_account_enc'];

    public function handle(): int
    {
        if (config('app.pii_key') === null && ! $this->option('force')) {
            $this->error('PII_ENCRYPTION_KEY is not set — there is nothing to migrate onto. Set it first (see ADR-0008), or pass --force to rotate under APP_KEY anyway.');

            return self::FAILURE;
        }

        PiiCrypter::flush();

        $migrated = 0;
        $skipped = 0;
        $nullCount = 0;

        DB::table('distributors')
            ->select(array_merge(['id'], self::COLUMNS))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$migrated, &$skipped, &$nullCount): void {
                foreach ($rows as $row) {
                    $update = [];
                    foreach (self::COLUMNS as $col) {
                        $value = $row->{$col};
                        if ($value === null || $value === '') {
                            $nullCount++;

                            continue;
                        }
                        try {
                            $plain = PiiCrypter::decryptString((string) $value);
                        } catch (DecryptException) {
                            $skipped++;

                            continue;
                        }
                        $update[$col] = PiiCrypter::encryptString($plain);
                        $migrated++;
                    }
                    if ($update !== []) {
                        DB::table('distributors')->where('id', $row->id)->update($update);
                    }
                }
            });

        $this->info("PII re-encryption complete: {$migrated} value(s) rewritten, {$skipped} undecryptable (left as-is), {$nullCount} empty.");
        if ($skipped > 0) {
            $this->warn("{$skipped} value(s) could not be decrypted with any available key — recover those by admin re-entry on the distributor edit page.");
        }

        return self::SUCCESS;
    }
}
