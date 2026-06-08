<?php

declare(strict_types=1);

namespace App\Modules\Shared\Casts;

use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast for a PII string column encrypted with the dedicated PII key
 * ({@see PiiCrypter}) rather than Laravel's APP_KEY-bound `encrypted` cast.
 *
 * Unlike the built-in `encrypted` cast, this never decrypts the previous value
 * to compute "dirty" state, so a row whose ciphertext predates the current key
 * (or was copied from another environment) cannot 500 a save.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class PiiEncrypted implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : PiiCrypter::decryptString((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => $value === null ? null : PiiCrypter::encryptString((string) $value)];
    }
}
