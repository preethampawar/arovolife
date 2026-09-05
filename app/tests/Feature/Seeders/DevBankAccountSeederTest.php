<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Crypto\PiiCrypter;
use Database\Seeders\DevBankAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('encrypts plaintext, stub and undecryptable bank accounts and keeps valid ciphertext', function (): void {
    $plain = Distributor::factory()->create();
    $stub = Distributor::factory()->create();
    $healthy = Distributor::factory()->create();

    DB::table('distributors')->where('id', $plain->id)->update(['bank_account_enc' => '0000000010000000', 'bank_ifsc' => null]);
    DB::table('distributors')->where('id', $stub->id)->update(['bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000001']);
    $healthyCiphertext = PiiCrypter::encryptString('123456789012');
    DB::table('distributors')->where('id', $healthy->id)->update(['bank_account_enc' => $healthyCiphertext]);

    $this->seed(DevBankAccountSeeder::class);

    $expectedPlain = str_pad((string) (10_000_000 + $plain->id), 16, '0', STR_PAD_LEFT);
    $plainRow = DB::table('distributors')->find($plain->id);
    expect(PiiCrypter::decryptString($plainRow->bank_account_enc))->toBe($expectedPlain)
        ->and($plainRow->bank_ifsc)->not->toBeNull();

    $stubRow = DB::table('distributors')->find($stub->id);
    expect(PiiCrypter::decryptString($stubRow->bank_account_enc))->toBe(str_pad((string) (10_000_000 + $stub->id), 16, '0', STR_PAD_LEFT))
        ->and($stubRow->bank_ifsc)->toBe('SBIN0000001');

    expect(DB::table('distributors')->find($healthy->id)->bank_account_enc)->toBe($healthyCiphertext);
});

it('refuses to run outside local and testing environments', function (): void {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DevBankAccountSeeder)->run())->toThrow(RuntimeException::class);
});
