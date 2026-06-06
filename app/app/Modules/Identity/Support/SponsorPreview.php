<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resolve a sponsor ADN into what the registration UI needs to let a new
 * joiner confirm "yes, that's the person who referred me": the ADN itself
 * and the sponsor's full name.
 *
 * The sponsor's email is deliberately NOT read or returned — it is the
 * sponsor's personal data and must never be surfaced to a prospective
 * registrant (DPDP / data minimisation). Name + ADN identify the sponsor.
 *
 * Used by:
 *  - GET /register/account  → step1-account banner
 *  - GET /register/adn-lookup → live JSON lookup on /join
 *
 * Returns null when the ADN doesn't resolve.
 */
final class SponsorPreview
{
    /**
     * @return array{adn: string, name: string}|null
     */
    public static function resolve(string $adn): ?array
    {
        $adn = strtoupper(trim($adn));
        if ($adn === '' || ! preg_match('/^[0-9]{9}(-S)?$/i', $adn)) {
            return null;
        }

        $row = DB::table('distributors as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.adn', $adn)
            ->select('u.full_name')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'adn' => $adn,
            'name' => (string) $row->full_name,
        ];
    }
}
