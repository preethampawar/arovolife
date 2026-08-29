<?php

declare(strict_types=1);

use App\Modules\Shared\Support\Money;

it('floors a per-unit value to the whole rupee and leaves the remainder', function () {
    // ₹3,000 ÷ 60 = ₹50 exactly.
    expect(Money::floorRupee(300_000, 60))->toBe(5_000);
    // ₹4,44,000 ÷ 2011 = ₹220.79… → ₹220.
    expect(Money::floorRupee(44_400_000, 2011))->toBe(22_000);
    // Single unit: ₹14,000.99 → ₹14,000.
    expect(Money::floorRupee(1_400_099))->toBe(1_400_000);
});

it('pays nothing for negative amounts or when there is nobody to pay', function () {
    expect(Money::floorRupee(-300_000, 60))->toBe(0);
    expect(Money::floorRupee(300_000, 0))->toBe(0);
    expect(Money::floorRupee(300_000, -3))->toBe(0);
    expect(Money::floorRupee(99, 1))->toBe(0);
});
