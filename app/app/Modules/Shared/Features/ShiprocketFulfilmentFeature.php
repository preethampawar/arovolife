<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the Shiprocket courier integration: the per-order dispatch route
 * picker, the label fetch, and the inbound tracking webhook.
 *
 * Default: `false`, and it stays false until there is a Shiprocket account.
 * There is none as of 2026-09-18, so nothing in the codebase may make a live
 * call; `ShiprocketGateway::permitted()` independently returns false while the
 * credentials in `config('arovolife.fulfilment.shiprocket')` are blank, which
 * means the flag being switched on by mistake still cannot send a request.
 *
 * While off the feature leaves no trace: the admin order page offers manual
 * dispatch only with no route picker, the `fulfilment` settings group is
 * hidden, and `POST /webhooks/shiprocket` 404s rather than 403s.
 *
 * Deliberately NOT gated by this flag: collection-from-centre handover. A
 * parcel can be consigned to an Arete Development Centre, acknowledged and
 * handed over entirely on manual dispatch, and tying that to a courier
 * integration we do not yet have an account for would leave R-47 open for no
 * reason.
 *
 * Resolved via:
 *     Feature::active(ShiprocketFulfilmentFeature::class)
 */
final class ShiprocketFulfilmentFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
