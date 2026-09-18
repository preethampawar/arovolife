<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * Canonical list of Indian states and union territories, stored by full name.
 *
 * Single source of truth wherever a state is persisted as its display name
 * (e.g. the Arete Development Center address). Distributor addresses persist a
 * two-letter code instead and keep their own code → name map.
 */
final class IndianStates
{
    /**
     * 28 states followed by the 8 union territories, alphabetical within each.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'Andhra Pradesh',
            'Arunachal Pradesh',
            'Assam',
            'Bihar',
            'Chhattisgarh',
            'Goa',
            'Gujarat',
            'Haryana',
            'Himachal Pradesh',
            'Jharkhand',
            'Karnataka',
            'Kerala',
            'Madhya Pradesh',
            'Maharashtra',
            'Manipur',
            'Meghalaya',
            'Mizoram',
            'Nagaland',
            'Odisha',
            'Punjab',
            'Rajasthan',
            'Sikkim',
            'Tamil Nadu',
            'Telangana',
            'Tripura',
            'Uttar Pradesh',
            'Uttarakhand',
            'West Bengal',
            'Andaman and Nicobar Islands',
            'Chandigarh',
            'Dadra and Nagar Haveli and Daman and Diu',
            'Delhi',
            'Jammu and Kashmir',
            'Ladakh',
            'Lakshadweep',
            'Puducherry',
        ];
    }

    /**
     * Two-letter code → canonical name.
     *
     * The codes distributor addresses persist. `DN` and `DD` predate the 2020
     * merger and both resolve to the single territory that replaced them, so a
     * code always lands on a name {@see all()} actually contains.
     *
     * @return array<string, string>
     */
    public static function codes(): array
    {
        return [
            'AN' => 'Andaman and Nicobar Islands',
            'AP' => 'Andhra Pradesh',
            'AR' => 'Arunachal Pradesh',
            'AS' => 'Assam',
            'BR' => 'Bihar',
            'CH' => 'Chandigarh',
            'CT' => 'Chhattisgarh',
            'DD' => 'Dadra and Nagar Haveli and Daman and Diu',
            'DL' => 'Delhi',
            'DN' => 'Dadra and Nagar Haveli and Daman and Diu',
            'GA' => 'Goa',
            'GJ' => 'Gujarat',
            'HP' => 'Himachal Pradesh',
            'HR' => 'Haryana',
            'JH' => 'Jharkhand',
            'JK' => 'Jammu and Kashmir',
            'KA' => 'Karnataka',
            'KL' => 'Kerala',
            'LA' => 'Ladakh',
            'LD' => 'Lakshadweep',
            'MH' => 'Maharashtra',
            'ML' => 'Meghalaya',
            'MN' => 'Manipur',
            'MP' => 'Madhya Pradesh',
            'MZ' => 'Mizoram',
            'NL' => 'Nagaland',
            'OR' => 'Odisha',
            'PB' => 'Punjab',
            'PY' => 'Puducherry',
            'RJ' => 'Rajasthan',
            'SK' => 'Sikkim',
            'TG' => 'Telangana',
            'TN' => 'Tamil Nadu',
            'TR' => 'Tripura',
            'UP' => 'Uttar Pradesh',
            'UT' => 'Uttarakhand',
            'WB' => 'West Bengal',
        ];
    }

    /**
     * Resolve a state written either way to its canonical name, or null when
     * it is not a state we recognise.
     *
     * Two representations coexist in the data and neither is going away:
     * distributor addresses persist a two-letter code, while order and centre
     * addresses persist the display name. Any comparison across that boundary
     * must normalise first — comparing the raw strings answers "different" for
     * `TG` against `Telangana`, which on the tax invoice charged IGST on every
     * intra-state supply.
     */
    public static function canonical(?string $state): ?string
    {
        $value = strtoupper(trim((string) $state));

        if ($value === '') {
            return null;
        }

        static $byName = null;

        if ($byName === null) {
            $byName = [];

            foreach (self::all() as $name) {
                $byName[strtoupper($name)] = $name;
            }

            // The pre-merger spellings, which the shipped address forms still
            // offer as two separate territories.
            $byName['DADRA AND NAGAR HAVELI'] = 'Dadra and Nagar Haveli and Daman and Diu';
            $byName['DAMAN AND DIU'] = 'Dadra and Nagar Haveli and Daman and Diu';
        }

        return self::codes()[$value] ?? $byName[$value] ?? null;
    }
}
