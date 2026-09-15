<?php

declare(strict_types=1);

use App\Modules\Inventory\Services\LandedCostAllocator;

/**
 * The exactness property is the whole point of this class.
 *
 * A paise dropped here is a paise of freight that never reaches a batch, and
 * it then never reaches the cost of any sale made from that batch. It would be
 * invisible — the GRN would still balance, the stock report would still
 * render, and the margin would just be quietly wrong forever. So the sum is
 * asserted over generated inputs, not over one hand-picked example.
 */
function alloc(): LandedCostAllocator
{
    return new LandedCostAllocator;
}

it('allocates the worked example exactly', function (): void {
    // 500 bottles at Rs 180, Rs 9,500 of charges -> Rs 199.00 landed.
    $result = alloc()->allocate(
        [['qty' => 500, 'taxable_value_paise' => 90_00_000]],
        9_50_000,
    );

    expect($result->lines[0]->landedUnitCostPaise)->toBe(19_900)
        ->and($result->lines[0]->allocatedChargesPaise)->toBe(9_50_000)
        ->and($result->allocatedTotalPaise())->toBe(9_50_000);
});

it('splits charges in proportion to line value', function (): void {
    $result = alloc()->allocate([
        ['qty' => 10, 'taxable_value_paise' => 30_000],
        ['qty' => 10, 'taxable_value_paise' => 10_000],
    ], 8_000);

    // 3:1 on value.
    expect($result->lines[0]->allocatedChargesPaise)->toBe(6_000)
        ->and($result->lines[1]->allocatedChargesPaise)->toBe(2_000);
});

it('splits by quantity when asked, ignoring value', function (): void {
    $result = alloc()->allocate([
        ['qty' => 30, 'taxable_value_paise' => 1_000],
        ['qty' => 10, 'taxable_value_paise' => 99_000],
    ], 8_000, LandedCostAllocator::BASIS_QTY);

    expect($result->lines[0]->allocatedChargesPaise)->toBe(6_000)
        ->and($result->lines[1]->allocatedChargesPaise)->toBe(2_000);
});

it('splits by total shipped weight, not unit weight', function (): void {
    // 2 units x 1000g against 1 unit x 1000g -> 2:1, not 1:1.
    $result = alloc()->allocate([
        ['qty' => 2, 'taxable_value_paise' => 100, 'weight_g' => 1_000],
        ['qty' => 1, 'taxable_value_paise' => 100, 'weight_g' => 1_000],
    ], 900, LandedCostAllocator::BASIS_WEIGHT);

    expect($result->lines[0]->allocatedChargesPaise)->toBe(600)
        ->and($result->lines[1]->allocatedChargesPaise)->toBe(300);
});

it('never loses a paise, across thousands of generated splits', function (): void {
    mt_srand(20260914);

    foreach (range(1, 3_000) as $_) {
        $lineCount = mt_rand(1, 12);
        $lines = [];

        foreach (range(1, $lineCount) as $__) {
            $lines[] = [
                'qty' => mt_rand(1, 500),
                'taxable_value_paise' => mt_rand(0, 5_000_000),
                'weight_g' => mt_rand(0, 5_000),
            ];
        }

        $charges = mt_rand(0, 2_000_000);
        $basis = LandedCostAllocator::BASES[array_rand(LandedCostAllocator::BASES)];

        $result = alloc()->allocate($lines, $charges, $basis);

        expect($result->allocatedTotalPaise())->toBe($charges);
    }
});

it('still allocates when the chosen basis carries no signal', function (): void {
    // Free samples: zero value, so a value split would drop every paise.
    $byValue = alloc()->allocate([
        ['qty' => 3, 'taxable_value_paise' => 0],
        ['qty' => 1, 'taxable_value_paise' => 0],
    ], 400);

    expect($byValue->allocatedTotalPaise())->toBe(400)
        ->and($byValue->lines[0]->allocatedChargesPaise)->toBe(300);

    // Weightless items with no quantity signal either: equal split, nothing lost.
    $byNothing = alloc()->allocate([
        ['qty' => 0, 'taxable_value_paise' => 0, 'weight_g' => 0],
        ['qty' => 0, 'taxable_value_paise' => 0, 'weight_g' => 0],
    ], 101, LandedCostAllocator::BASIS_WEIGHT);

    expect($byNothing->allocatedTotalPaise())->toBe(101);
});

it('reports the unitisation rounding rather than hiding it', function (): void {
    // 3 units, Rs 100 of charges: 10000/3 does not divide into whole paise.
    $result = alloc()->allocate([['qty' => 3, 'taxable_value_paise' => 0]], 10_000);

    expect($result->allocatedTotalPaise())->toBe(10_000)
        ->and($result->lines[0]->landedUnitCostPaise)->toBe(3_333)
        ->and($result->unitisationRoundingPaise())->toBe(-1);
});

it('gives a zero-quantity line no per-unit cost instead of dividing by zero', function (): void {
    $result = alloc()->allocate([
        ['qty' => 0, 'taxable_value_paise' => 0],
        ['qty' => 5, 'taxable_value_paise' => 5_000],
    ], 1_000);

    expect($result->lines[0]->landedUnitCostPaise)->toBe(0)
        ->and($result->allocatedTotalPaise())->toBe(1_000);
});

it('rejects a negative charge and an unknown basis', function (): void {
    expect(fn () => alloc()->allocate([['qty' => 1, 'taxable_value_paise' => 1]], -1))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => alloc()->allocate([['qty' => 1, 'taxable_value_paise' => 1]], 1, 'guesswork'))
        ->toThrow(InvalidArgumentException::class);
});

it('allocates the same way every time so re-saving a draft does not shuffle cost', function (): void {
    $lines = [
        ['qty' => 1, 'taxable_value_paise' => 1_000],
        ['qty' => 1, 'taxable_value_paise' => 1_000],
        ['qty' => 1, 'taxable_value_paise' => 1_000],
    ];

    $first = alloc()->allocate($lines, 100);
    $second = alloc()->allocate($lines, 100);

    expect(array_map(fn ($l) => $l->allocatedChargesPaise, $first->lines))
        ->toBe(array_map(fn ($l) => $l->allocatedChargesPaise, $second->lines));
});
