<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates *enforcement* of stock availability, not the recording of it.
 *
 * The ledger always writes: receipts, sales, transfers and adjustments are
 * recorded whatever this flag says, because a record that appears only when a
 * flag is on is a record with holes in it.
 *
 * What the flag controls is the two places where a number can stop something
 * happening — the checkout availability check and the daily alert mail. It
 * exists so that the module can deploy into a live staging/UAT flow without
 * the day-one side effect of rejecting checkouts against on-hand figures that
 * have not been opened yet.
 *
 * Turn it on only after:
 *
 *  1. `php artisan inventory:backfill-opening` has converted the existing
 *     `on_hand` numbers into opening movements;
 *  2. `php artisan inventory:verify` reports no drift; and
 *  3. ops has entered at least one real goods receipt.
 *
 * Resolved via:
 *     Feature::for(null)->active(InventoryFeature::class)
 */
final class InventoryFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
