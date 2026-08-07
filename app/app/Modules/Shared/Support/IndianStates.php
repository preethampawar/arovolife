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
}
