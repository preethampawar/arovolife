<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Services;

use App\Modules\Fulfilment\Contracts\CourierGateway;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use App\Modules\Shared\Features\ShiprocketFulfilmentFeature;
use Laravel\Pennant\Feature;

/**
 * Which couriers an operator may hand a parcel to, and which one is offered
 * first.
 *
 * **This resolver falls back. `PaymentGatewayResolver` deliberately does not,
 * and the difference is not an inconsistency.** There, falling back from a
 * misconfigured Razorpay to the stub would mark orders paid without collecting
 * money — so a misconfigured gateway closes the shop instead (R-56, hard rule
 * 2). Here, falling back means an operator types the carrier and AWB by hand
 * instead of the system booking it. Nothing is asserted that is not true, no
 * money moves, and the parcel still goes. Refusing to dispatch because an
 * integration is down would strand real parcels for no safety gain.
 *
 * The operator always chooses per order (plan AD-8). `preferred()` only decides
 * what the dispatch form highlights. The fallback in `route()` is for a caller
 * that expressed no real preference; `DispatchService` refuses outright when an
 * operator explicitly asked for a courier that is not available, so an order is
 * never hand-dispatched while the operator believes a courier booked it.
 */
final class CourierGatewayResolver
{
    public function __construct(
        private readonly ManualCourier $manual,
        private readonly ShiprocketGateway $shiprocket,
        private readonly FulfilmentSettings $settings,
    ) {}

    /**
     * The routes an operator may pick from right now, keyed by gateway name.
     *
     * Manual is always present. A courier integration appears only when its
     * flag is on AND it is actually usable — an enabled-but-uncredentialled
     * gateway is not offered, because offering a button that cannot work is
     * how an operator loses a morning.
     *
     * @return array<string, CourierGateway>
     */
    public function available(): array
    {
        $routes = [Shipment::GATEWAY_MANUAL => $this->manual];

        // Three separate gates, each owned by someone different: the flag
        // (developer: does this code path exist at all), the setting (the
        // business: do we use it), and permitted() (the environment: are
        // there credentials for the right host and a pickup to book from).
        if (Feature::for(null)->active(ShiprocketFulfilmentFeature::class)
            && $this->settings->shiprocketEnabled()
            && $this->shiprocket->permitted()) {
            $routes[Shipment::GATEWAY_SHIPROCKET] = $this->shiprocket;
        }

        return $routes;
    }

    /** Is this route usable right now? */
    public function supports(string $gateway): bool
    {
        return array_key_exists($gateway, $this->available());
    }

    /**
     * The gateway to dispatch through.
     *
     * An unavailable or unknown request resolves to manual rather than
     * throwing. Callers that must not swap routes silently compare the
     * result's name with what they asked for — DispatchService refuses the
     * dispatch when they differ.
     */
    public function route(?string $requested = null): CourierGateway
    {
        $available = $this->available();

        return $available[$requested] ?? $this->manual;
    }

    /** Which route the dispatch form should highlight. Never what it does automatically. */
    public function preferred(): CourierGateway
    {
        return $this->route($this->settings->defaultRoute());
    }
}
