<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Enums;

/**
 * The two purchase offers for distributors who hold no rank (KP 2026-06-26).
 *
 * Both hang off BV the distributor personally purchased. Neither has, or can
 * have, a joining trigger — that was dropped by the Product Owner on
 * 2026-08-16 because an offer earned by joining would break hard rule 1
 * (joining is free of cost) and hard rule 2 (value only from product sales).
 */
enum PurchaseOfferType: string
{
    case HalfPriceProduct = 'half_price_product';
    case RedeemPoints = 'redeem_points';

    public function label(): string
    {
        return match ($this) {
            self::HalfPriceProduct => 'Half-price monthly product',
            self::RedeemPoints => 'Redeem points',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::HalfPriceProduct => 'One company-announced product at half the distributor price, for a month in which you repurchased the qualifying volume.',
            self::RedeemPoints => 'Points earned by holding the qualifying volume for six consecutive months. One point is one rupee off a future purchase.',
        };
    }
}
