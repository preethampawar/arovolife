<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services\DTOs;

use App\Modules\Commerce\Models\Coupon;

/**
 * Outcome of validating a promo code against a cart.
 */
final readonly class CouponResult
{
    private function __construct(
        public bool $ok,
        public ?Coupon $coupon,
        public int $discountPaise,
        public ?string $error,
    ) {}

    public static function ok(Coupon $coupon, int $discountPaise): self
    {
        return new self(true, $coupon, $discountPaise, null);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, 0, $error);
    }
}
