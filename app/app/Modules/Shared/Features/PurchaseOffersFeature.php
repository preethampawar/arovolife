<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the two purchase offers (KP 2026-06-26) — the half-price monthly
 * product and the redeem-points streak — across every surface.
 *
 * Default: `false`. While off there is no My Offers page, no points field at
 * checkout, no admin screens and no settings keys.
 *
 * Gates before switching it on in production:
 *
 *  1. the DSA §6.2 thirty-day written notice — the offers change what a
 *     distributor gets for their purchases and so form part of the plan;
 *  2. `/p/compensation` §11.2 carries the effective date from that notice; and
 *  3. KP confirms the two readings recorded in R-48 — what "hold no rank"
 *     means, and what "20% of total BV" is 20% of.
 *
 * Resolved via:
 *     Feature::active(PurchaseOffersFeature::class)
 */
final class PurchaseOffersFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
