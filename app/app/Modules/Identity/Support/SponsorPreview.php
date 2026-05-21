<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resolve a sponsor ADN into the trio the registration UI needs:
 * the ADN itself, the sponsor's full name, and a masked email so
 * the new joiner can confirm "yes, that's the person who referred
 * me" without us leaking the full address.
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
     * @return array{adn: string, name: string, email_masked: string}|null
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
            ->select('u.full_name', 'u.email')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'adn' => $adn,
            'name' => (string) $row->full_name,
            'email_masked' => self::maskEmail((string) $row->email),
        ];
    }

    /**
     * Mask the local part of an email, keeping the first and last
     * character visible and the domain intact. Safe to render publicly
     * — only first/last char of the local part is revealed.
     *
     * Examples:
     *   ravi.kumar@gmail.com  → r••••••••r@gmail.com
     *   jo@arovo.in           → j•@arovo.in
     *   ab@x.com              → ••@x.com
     */
    public static function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '';
        }

        [$local, $domain] = explode('@', $email, 2);
        $len = strlen($local);

        if ($len <= 2) {
            $masked = str_repeat('•', $len);
        } else {
            $masked = $local[0].str_repeat('•', $len - 2).$local[$len - 1];
        }

        return $masked.'@'.$domain;
    }
}
