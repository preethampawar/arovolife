<?php

declare(strict_types=1);

/**
 * Normalising a state written as a code or as a name (R-28, CGST Rule 46).
 *
 * Two representations coexist in the data: distributor addresses persist a
 * two-letter code, order and centre addresses persist the display name. The
 * tax invoice compares one against the other to choose CGST+SGST or IGST, so
 * a comparison that cannot bridge the two charges the wrong head of tax.
 */

use App\Modules\Shared\Support\IndianStates;

it('IST-001: resolves a two-letter code to the canonical name', function () {
    expect(IndianStates::canonical('TG'))->toBe('Telangana')
        ->and(IndianStates::canonical('KA'))->toBe('Karnataka')
        ->and(IndianStates::canonical('AP'))->toBe('Andhra Pradesh');
});

it('IST-002: resolves a display name to itself, whatever the case', function () {
    expect(IndianStates::canonical('Telangana'))->toBe('Telangana')
        ->and(IndianStates::canonical('TELANGANA'))->toBe('Telangana')
        ->and(IndianStates::canonical('  telangana  '))->toBe('Telangana');
});

it('IST-003: a code and a name for the same state resolve equal', function () {
    // The invoice bug in one line: these two were compared raw.
    expect(IndianStates::canonical('TG'))->toBe(IndianStates::canonical('Telangana'));
});

it('IST-004: an unrecognised state is null, never a match', function () {
    expect(IndianStates::canonical('Atlantis'))->toBeNull()
        ->and(IndianStates::canonical(''))->toBeNull()
        ->and(IndianStates::canonical(null))->toBeNull()
        ->and(IndianStates::canonical('XX'))->toBeNull();
});

it('IST-005: every code lands on a state the canonical list contains', function () {
    $names = IndianStates::all();
    $orphans = array_keys(array_filter(
        IndianStates::codes(),
        fn (string $name): bool => ! in_array($name, $names, true),
    ));

    expect($orphans)->toBe([]);
});

it('IST-006: the pre-merger territory spellings still resolve', function () {
    $merged = 'Dadra and Nagar Haveli and Daman and Diu';

    expect(IndianStates::canonical('DN'))->toBe($merged)
        ->and(IndianStates::canonical('DD'))->toBe($merged)
        ->and(IndianStates::canonical('Dadra and Nagar Haveli'))->toBe($merged)
        ->and(IndianStates::canonical('Daman and Diu'))->toBe($merged);
});

it('IST-007: the GST portal two-letter codes resolve as well as the ISO ones', function () {
    // Neither spelling is wrong. The statutory code is the numeric one (36 for
    // Telangana); the two-letter alpha is a convention, and the GST portal and
    // ISO 3166-2:IN disagree on four states. Real data carries both.
    expect(IndianStates::canonical('TS'))->toBe('Telangana')
        ->and(IndianStates::canonical('TG'))->toBe('Telangana')
        ->and(IndianStates::canonical('OD'))->toBe('Odisha')
        ->and(IndianStates::canonical('OR'))->toBe('Odisha')
        ->and(IndianStates::canonical('CG'))->toBe('Chhattisgarh')
        ->and(IndianStates::canonical('CT'))->toBe('Chhattisgarh')
        ->and(IndianStates::canonical('UK'))->toBe('Uttarakhand')
        ->and(IndianStates::canonical('UT'))->toBe('Uttarakhand');
});

it('IST-008: the older Uttaranchal code resolves too', function () {
    // `UA` predates the 2007 rename and still appears in imported records.
    expect(IndianStates::canonical('UA'))->toBe('Uttarakhand');
});

it('IST-009: an ISO code is never shadowed by an alias', function () {
    // The aliases exist to fill gaps, never to redefine a code that already
    // means something. If the two maps ever collide, `codes()` must win —
    // otherwise adding a convenience silently re-points a shipped code.
    $collisions = array_keys(array_intersect_key(IndianStates::aliases(), IndianStates::codes()));

    expect($collisions)->toBe([]);

    foreach (IndianStates::codes() as $code => $name) {
        expect(IndianStates::canonical($code))->toBe($name);
    }
});

it('IST-010: every state has a statutory numeric GST code, and they are unique', function () {
    // Rule 46(n) prints the place of supply with its state code, so a state
    // with no code cannot be put on an invoice at all.
    $names = IndianStates::all();
    $codes = IndianStates::gstCodes();

    $missing = array_values(array_diff($names, array_keys($codes)));
    $unknown = array_values(array_diff(array_keys($codes), $names));

    expect($missing)->toBe([])
        ->and($unknown)->toBe([])
        ->and(array_unique(array_values($codes)))->toHaveCount(count($codes));

    foreach ($codes as $name => $code) {
        expect($code)->toMatch('/^\d{2}$/', "{$name} must carry a two-digit GST state code");
    }
});
