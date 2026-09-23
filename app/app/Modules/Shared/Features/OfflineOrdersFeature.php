<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates offline orders: staff creating an order for a distributor who paid
 * outside the gateway (cash at reception, bank deposit, UPI, NEFT, cheque),
 * and finance confirming or rejecting that payment.
 *
 * Default: `false`. While off the feature leaves no trace: no "New offline
 * order" button, no payment filter, no Payments callout, and every offline
 * route 404s. Offline orders created while it was on still show their payment
 * card — history is never hidden.
 *
 * Resolved via:
 *     Feature::for(null)->active(OfflineOrdersFeature::class)
 */
final class OfflineOrdersFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
