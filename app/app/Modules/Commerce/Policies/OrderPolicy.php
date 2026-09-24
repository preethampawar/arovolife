<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Policies;

use App\Modules\Commerce\Models\Order;
use App\Modules\Identity\Models\User;

/**
 * Who may see an order (T-6.1 finding M-6).
 *
 * The storefront controllers already scope every query by
 * `whereHas('customer', user_id)`, and the T-6.1 pass confirmed there is no
 * IDOR on any of them. This policy is not fixing a hole — it is the structural
 * backstop, because that correctness currently rests on each controller author
 * remembering the `where` clause. One forgotten clause in a future controller
 * reintroduces the hole silently, and nothing would catch it.
 *
 * `Gate::before` gives `developer` and `admin` everything; the scoped admin
 * roles reach orders through the admin routes, which carry their own
 * permission. This governs the distributor-facing side.
 */
final class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    /**
     * Cancelling inside the cooling-off window is the buyer's statutory right
     * (T&C §4), so the same ownership test governs it. Staff cancellation is a
     * different route with `commerce.order.manage` on it.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    /** Downloading the invoice reveals what was bought and for how much. */
    public function downloadInvoice(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    /**
     * The centre a collection order was sent to may confirm its arrival and
     * hand it over. Ownership comes from `orders.arete_center_id`, not the
     * shipment row, which is empty for parcels shipped before dispatch went
     * through the courier routes.
     *
     * R-24: the number of centres one distributor may hold is uncapped, so an
     * owner of several centres acts for all of them. What keeps the handover
     * honest is the buyer's collection code, which the centre never sees.
     *
     * Refused while impersonating (the R-98 precedent from the declarations):
     * a receipt or handover recorded by staff wearing the owner's session
     * would read as the owner's own act.
     */
    public function actAtCentre(User $user, Order $order): bool
    {
        if (session()->has('impersonator_id')) {
            return false;
        }

        $distributorId = $user->distributor?->id;

        return $distributorId !== null
            && $order->isCollection()
            && $order->areteCenter !== null
            && $order->areteCenter->assigned_distributor_id === $distributorId;
    }

    /**
     * Ownership is via the customer record, not the attributed distributor.
     *
     * The distinction matters: a distributor is *attributed* orders placed by
     * the people they sell to, and those are somebody else's purchases. Being
     * paid BV on an order does not entitle you to read the buyer's address.
     */
    private function owns(User $user, Order $order): bool
    {
        return $order->customer !== null
            && $order->customer->user_id !== null
            && (int) $order->customer->user_id === (int) $user->id;
    }
}
