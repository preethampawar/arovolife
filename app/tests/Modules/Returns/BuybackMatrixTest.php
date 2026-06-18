<?php

declare(strict_types=1);

use App\Modules\Returns\Services\BuybackMatrix;

/**
 * T&C §8 buyback / cooling-off refund matrix (ADR-0009, compliance-approved).
 * Asserts every (reason, saleable) row, including the ineligible ones.
 */
function bm(): BuybackMatrix
{
    return new BuybackMatrix;
}

it('BM-policy: encodes every T&C §8 row', function (string $reason, bool $saleable, bool $eligible, bool $refundGst, ?int $window): void {
    $p = bm()->policy($reason, $saleable);

    expect($p['eligible'])->toBe($eligible);
    expect($p['refund_gst'])->toBe($refundGst);
    expect($p['invoice'])->toBe($refundGst);          // credit note iff GST refunded
    expect($p['window_days'])->toBe($window);
})->with([
    // reason, saleable, eligible, refundGst, window
    'cooling-off saleable' => ['cooling_off', true, true, true, 30],
    'cooling-off non-saleable' => ['cooling_off', false, false, false, null],
    'damage saleable' => ['damage', true, true, true, 10],
    'damage non-saleable' => ['damage', false, true, false, 10],
    'dissatisfaction saleable' => ['dissatisfaction', true, true, true, 30],
    'dissatisfaction non-saleable' => ['dissatisfaction', false, true, false, 30],
    'general buyback saleable' => ['general_buyback', true, true, false, null],
    'general buyback non-saleable' => ['general_buyback', false, false, false, null],
    'termination buyback saleable' => ['termination_buyback', true, true, false, null],
    'termination buyback non-sale.' => ['termination_buyback', false, false, false, null],
]);

it('BM-refund: cooling-off saleable refunds the FULL amount incl. GST', function (): void {
    // ₹1000 taxable + ₹180 GST → full ₹1180.
    expect(bm()->refundPaise('cooling_off', true, 100000, 18000))->toBe(118000);
});

it('BM-refund: non-saleable dissatisfaction refunds DS Price less GST', function (): void {
    expect(bm()->refundPaise('dissatisfaction', false, 100000, 18000))->toBe(100000);
});

it('BM-refund: ineligible rows refund nothing', function (): void {
    expect(bm()->refundPaise('cooling_off', false, 100000, 18000))->toBe(0);
    expect(bm()->refundPaise('general_buyback', false, 100000, 18000))->toBe(0);
});

it('BM-window: enforces each reason window', function (): void {
    expect(bm()->withinWindow('cooling_off', true, 30))->toBeTrue();
    expect(bm()->withinWindow('cooling_off', true, 31))->toBeFalse();
    expect(bm()->withinWindow('damage', true, 10))->toBeTrue();
    expect(bm()->withinWindow('damage', true, 11))->toBeFalse();
    // No window (general/termination buyback) → always within.
    expect(bm()->withinWindow('general_buyback', true, 9999))->toBeTrue();
});

it('BM: rejects an unknown reason', function (): void {
    expect(fn () => bm()->policy('refund_everything', true))->toThrow(InvalidArgumentException::class);
});
