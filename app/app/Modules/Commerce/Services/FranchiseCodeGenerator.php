<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\Franchise;
use RuntimeException;

/**
 * Issues franchise codes.
 *
 * Format: `FR-XXXXX`, e.g. `FR-7KQ2M`.
 *
 * Deliberately unlike an ADN, which is nine digits. The compliance review made
 * the separation of the two identifiers a design constraint: a franchise is a
 * place in the fulfilment network, an ADN is a person's position in the Genos,
 * and a code that could be mistaken for the other invites exactly the
 * conflation the constraint exists to prevent. The `FR-` prefix and the
 * letters make that impossible at a glance.
 *
 * Ambiguous glyphs are excluded — these codes get read out over the phone and
 * printed on collection slips.
 */
final class FranchiseCodeGenerator
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const MAX_ATTEMPTS = 8;

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = 'FR-'.$this->randomSuffix();

            if (! Franchise::where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Could not allocate a unique franchise code after '.self::MAX_ATTEMPTS.' attempts.'
        );
    }

    private function randomSuffix(): string
    {
        $suffix = '';

        for ($i = 0; $i < 5; $i++) {
            $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $suffix;
    }
}
