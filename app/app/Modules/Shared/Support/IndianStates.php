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
     * The statutory numeric GST state code, canonical name → code.
     *
     * This is the code the law actually names (Telangana is 36); the
     * two-letter alphas above are conveniences that two standards disagree
     * about {@see aliases()}. Printed on the invoice beside the state, and the
     * vocabulary behind the `tax.seller_state_code` setting.
     *
     * @return array<string, string>
     */
    public static function gstCodes(): array
    {
        return [
            'Jammu and Kashmir' => '01',
            'Himachal Pradesh' => '02',
            'Punjab' => '03',
            'Chandigarh' => '04',
            'Uttarakhand' => '05',
            'Haryana' => '06',
            'Delhi' => '07',
            'Rajasthan' => '08',
            'Uttar Pradesh' => '09',
            'Bihar' => '10',
            'Sikkim' => '11',
            'Arunachal Pradesh' => '12',
            'Nagaland' => '13',
            'Manipur' => '14',
            'Mizoram' => '15',
            'Tripura' => '16',
            'Meghalaya' => '17',
            'Assam' => '18',
            'West Bengal' => '19',
            'Jharkhand' => '20',
            'Odisha' => '21',
            'Chhattisgarh' => '22',
            'Madhya Pradesh' => '23',
            'Gujarat' => '24',
            'Dadra and Nagar Haveli and Daman and Diu' => '26',
            'Maharashtra' => '27',
            'Karnataka' => '29',
            'Goa' => '30',
            'Lakshadweep' => '31',
            'Kerala' => '32',
            'Tamil Nadu' => '33',
            'Puducherry' => '34',
            'Andaman and Nicobar Islands' => '35',
            'Telangana' => '36',
            'Andhra Pradesh' => '37',
            'Ladakh' => '38',
        ];
    }

    /**
     * Spellings that are not ISO 3166-2:IN but turn up in real data anyway.
     *
     * Four states are coded differently by the GST portal than by ISO, and
     * neither is wrong — the two-letter alpha is not the statutory code (that
     * is the numeric one {@see gstCodes()}), so a GST-portal code is a
     * legitimate value, not a typo, and rejecting it would strand real rows.
     * The two pre-merger territory names are here for the same reason: the
     * shipped address forms offered them separately until 2020.
     *
     * Kept apart from {@see codes()} so that map stays exactly one standard.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'TS' => 'Telangana',        // ISO says TG
            'OD' => 'Odisha',           // ISO says OR
            'CG' => 'Chhattisgarh',     // ISO says CT
            'UK' => 'Uttarakhand',      // ISO says UT
            'UA' => 'Uttarakhand',      // and the older Uttaranchal code
            'DADRA AND NAGAR HAVELI' => 'Dadra and Nagar Haveli and Daman and Diu',
            'DAMAN AND DIU' => 'Dadra and Nagar Haveli and Daman and Diu',
        ];
    }

    /**
     * Resolve a state written any of the ways we accept to its canonical name,
     * or null when it is not a state we recognise.
     *
     * Two representations coexist in the data and neither is going away:
     * distributor addresses persist a two-letter code, while order and centre
     * addresses persist the display name. Any comparison across that boundary
     * must normalise first — comparing the raw strings answers "different" for
     * `TG` against `Telangana`, which on the tax invoice charged IGST on every
     * intra-state supply.
     *
     * ISO codes resolve first, so {@see aliases()} can only ever fill a gap and
     * never shadow a code that means something else under the other standard.
     */
    public static function canonical(?string $state): ?string
    {
        $value = strtoupper(trim((string) $state));

        if ($value === '') {
            return null;
        }

        static $lookup = null;

        if ($lookup === null) {
            $lookup = self::aliases();

            foreach (self::all() as $name) {
                $lookup[strtoupper($name)] = $name;
            }

            // Last, so an ISO code wins any future overlap with an alias.
            $lookup = self::codes() + $lookup;
        }

        return $lookup[$value] ?? null;
    }
}
