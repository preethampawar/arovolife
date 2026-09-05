<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Local-only: give every distributor a decryptable test bank account.
 *
 * `distributors.bank_account_enc` holds PiiCrypter ciphertext, never a raw
 * account number — PayoutService decrypts it to derive the NEFT last-4 and
 * holds the line as `bank_decrypt_failed` when that fails. Seeding the column
 * with plain digits by hand therefore blocks every payout on the dev box.
 *
 * Idempotent: rows whose ciphertext already decrypts are left alone; NULL,
 * factory `stub`, plaintext and undecryptable rows are (re)written with a
 * deterministic account number (10000000 + id, left-padded to 16 digits) and,
 * when missing, a rotating test IFSC.
 *
 *   docker exec arovolife-app php artisan db:seed --class=DevBankAccountSeeder
 */
final class DevBankAccountSeeder extends Seeder
{
    private const TEST_IFSCS = ['HDFC0001234', 'ICIC0001234', 'AXIS0001234', 'PUNB0001234', 'SBIN0001234'];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevBankAccountSeeder writes test bank accounts and only runs on local/testing.');
        }

        $rows = DB::table('distributors')->orderBy('id')->get(['id', 'bank_account_enc', 'bank_ifsc']);

        $written = 0;
        $kept = 0;

        foreach ($rows as $row) {
            if ($this->decrypts($row->bank_account_enc)) {
                $kept++;

                continue;
            }

            $accountNumber = str_pad((string) (10_000_000 + (int) $row->id), 16, '0', STR_PAD_LEFT);

            DB::table('distributors')->where('id', $row->id)->update([
                'bank_account_enc' => PiiCrypter::encryptString($accountNumber),
                'bank_ifsc' => $row->bank_ifsc ?: self::TEST_IFSCS[(int) $row->id % count(self::TEST_IFSCS)],
            ]);
            $written++;
        }

        $this->command?->info("Dev bank accounts: {$written} encrypted, {$kept} already decryptable.");
    }

    private function decrypts(mixed $ciphertext): bool
    {
        if (! is_string($ciphertext) || $ciphertext === '' || $ciphertext === 'stub') {
            return false;
        }

        try {
            PiiCrypter::decryptString($ciphertext);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
