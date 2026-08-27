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
