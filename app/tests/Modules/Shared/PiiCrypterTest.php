<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * ADR-0008: PII is encrypted with a dedicated, stable key (PII_ENCRYPTION_KEY)
 * that is independent of APP_KEY, so rotating APP_KEY never makes PII
 * unreadable. PiiCrypter falls back to APP_KEY / APP_PREVIOUS_KEYS so data
 * written before the dedicated key existed still decrypts during migration.
 */
function piiKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function setPiiKey(?string $key): void
{
    config(['app.pii_key' => $key]);
    PiiCrypter::flush();
}

beforeEach(function (): void {
    PiiCrypter::flush();
});

afterEach(function (): void {
    config(['app.pii_key' => null]);
    PiiCrypter::flush();
});

it('PII-01: round-trips a value under the dedicated PII key', function (): void {
    setPiiKey(piiKey());

    $cipher = PiiCrypter::encryptString('ABCDE1234F');

    expect($cipher)->not->toBe('ABCDE1234F');
    expect(PiiCrypter::decryptString($cipher))->toBe('ABCDE1234F');
});

it('PII-02: data written under APP_KEY still decrypts after a dedicated key is introduced (fallback)', function (): void {
    // No PII key yet → encrypts under APP_KEY.
    setPiiKey(null);
    $legacy = PiiCrypter::encryptString('LEGACY-SECRET');

    // Introduce a dedicated PII key. The legacy value must still read via the
    // APP_KEY fallback.
    setPiiKey(piiKey());
    expect(PiiCrypter::decryptString($legacy))->toBe('LEGACY-SECRET');
});

it('PII-03: PII survives APP_KEY rotation (the whole point)', function (): void {
    $key = piiKey();
    setPiiKey($key);

    $cipher = PiiCrypter::encryptString('BCDEF2345G');

    // Simulate `php artisan key:generate` — APP_KEY is replaced entirely.
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    config(['app.previous_keys' => []]);
    PiiCrypter::flush();

    // The dedicated PII key is unchanged, so the value still decrypts.
    expect(PiiCrypter::decryptString($cipher))->toBe('BCDEF2345G');
});

it('PII-04: the PiiEncrypted cast encrypts on set and decrypts on get', function (): void {
    setPiiKey(piiKey());

    $d = new Distributor;
    $d->pan_encrypted = 'ABCDE1234F';

    // Stored form is ciphertext...
    expect($d->getAttributes()['pan_encrypted'])->not->toBe('ABCDE1234F');
    // ...but reads back as plaintext.
    expect($d->pan_encrypted)->toBe('ABCDE1234F');
});

it('PII-05: pii:reencrypt moves APP_KEY data onto the PII key (decryptable by the PII key alone)', function (): void {
    // Seed a distributor with PAN encrypted under APP_KEY (no PII key yet).
    setPiiKey(null);
    $appCipher = PiiCrypter::encryptString('ABCDE1234F');

    $uid = DB::table('users')->insertGetId([
        'email' => 'pii-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $uid, 'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => hash('sha256', 'ABCDE1234F', true), 'pan_last4' => '234F',
            'pan_encrypted' => $appCipher,
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    // Introduce a dedicated PII key and run the backfill.
    $key = piiKey();
    setPiiKey($key);
    $this->artisan('pii:reencrypt')->assertSuccessful();

    // The stored value now decrypts with the PII key ALONE — no APP_KEY fallback.
    $stored = DB::table('distributors')->where('id', $id)->value('pan_encrypted');
    $piiOnly = new Encrypter(base64_decode(substr($key, 7)), (string) config('app.cipher'));
    expect($piiOnly->decryptString($stored))->toBe('ABCDE1234F');
});

it('PII-06: an undecryptable value (foreign key) is left untouched by pii:reencrypt', function (): void {
    // Ciphertext from a key we do not hold.
    $foreign = new Encrypter(random_bytes(32), (string) config('app.cipher'));
    $foreignCipher = $foreign->encryptString('OLDPAN1234X');

    $uid = DB::table('users')->insertGetId([
        'email' => 'pii2-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $uid, 'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32), 'pan_last4' => '234X',
            'pan_encrypted' => $foreignCipher,
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    setPiiKey(piiKey());
    $this->artisan('pii:reencrypt')->assertSuccessful();

    // Untouched — still the foreign ciphertext, recoverable only by re-entry.
    expect(DB::table('distributors')->where('id', $id)->value('pan_encrypted'))->toBe($foreignCipher);
});
