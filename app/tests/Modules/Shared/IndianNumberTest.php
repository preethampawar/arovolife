<?php

declare(strict_types=1);

use App\Modules\Shared\Support\IndianNumber;

/**
 * Pins lakh/crore digit grouping. This used to come free from
 * Number::useLocale('en_IN'), until CLDR removed lakh grouping from the
 * Indian locales' default data (en_IN in CLDR 42, the rest by ICU 78) and
 * every amount on the platform silently went western (2,430,000). The
 * explicit pattern in IndianNumber is immune to ICU upgrades — this test
 * fails loudly if that ever stops being true.
 */
it('formats with Indian digit grouping regardless of ICU locale data', function () {
    expect(IndianNumber::format(2_430_000))->toBe('24,30,000')
        ->and(IndianNumber::format(28_390_777))->toBe('2,83,90,777')
        ->and(IndianNumber::format(343_536.4, 2))->toBe('3,43,536.40')
        ->and(IndianNumber::format(1_419_538.85, 2))->toBe('14,19,538.85')
        // Below a lakh, grouping matches western style — the formats agree.
        ->and(IndianNumber::format(2_945, 2))->toBe('2,945.00')
        ->and(IndianNumber::format(600))->toBe('600')
        ->and(IndianNumber::format(0, 2))->toBe('0.00')
        // maxPrecision trims without padding, same as Number::format.
        ->and(IndianNumber::format(1_234.56, null, 1))->toBe('1,234.6');
});
