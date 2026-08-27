<?php

declare(strict_types=1);

/**
 * The structural IDOR backstop (T-6.1 finding M-6).
 *
 * The audit found no IDOR anywhere — but found it was correct by per-controller
 * discipline rather than by structure: every storefront query carried its own
 * `whereHas('customer', user_id)`, and one forgotten clause in a future
 * controller would reintroduce the hole silently, with nothing to catch it.
 * CLAUDE.md asks for one policy per model and never relying on middleware
 * alone; there was exactly one policy on the whole platform.
 *
 * POL-01: a policy is registered for the models that carry other people's data
 * POL-02: another customer's order is not viewable, and 404s rather than 403s
 * POL-03: their invoice is not downloadable
 * POL-04: their order is not cancellable
 * POL-05: the owner still reaches all three
 * POL-06: being paid BV on an order does not make it yours to read
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function polOrder(User $owner, ?int $attributedDistributorId = null): Order
{
    $customer = Customer::create(['display_name' => 'Buyer', 'user_id' => $owner->id]);

    return Order::create([
        'order_no' => 'ORD-POL-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attributed_distributor_id' => $attributedDistributorId,
        'attribution_source' => 'logged_in',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PAID,
        'subtotal_paise' => 118000, 'gst_paise' => 18000,
        'discount_paise' => 0, 'shipping_paise' => 0, 'total_paise' => 118000,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now(), 'paid_at' => now(),
        'idempotency_key' => 'pol-'.uniqid(),
    ]);
}

it('POL-01: a policy is registered for the models carrying other people\'s data', function () {
    expect(Gate::getPolicyFor(Order::class))->not->toBeNull()
        ->and(Gate::getPolicyFor(Distributor::class))->not->toBeNull()
        ->and(Gate::getPolicyFor(Ticket::class))->not->toBeNull();
});

it('POL-02: another customer\'s order is not viewable', function () {
    $order = polOrder(User::factory()->create());

    // 404, not 403: telling a stranger that an order number exists is itself a
    // disclosure.
    $this->actingAs(User::factory()->create())
        ->get(route('orders.show', $order->order_no))
        ->assertNotFound();
});

it('POL-03: another customer\'s invoice is not downloadable', function () {
    $order = polOrder(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->get(route('orders.invoice', $order->order_no))
        ->assertNotFound();
});

it('POL-04: another customer\'s order is not cancellable', function () {
    $order = polOrder(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->post(route('orders.cancel', $order->order_no))
        ->assertNotFound();

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
});

it('POL-05: the owner still reaches their own order', function () {
    $owner = User::factory()->create();
    $order = polOrder($owner);

    // A backstop that locks out the owner is an outage, not a control.
    $this->actingAs($owner)->get(route('orders.show', $order->order_no))->assertOk();
    $this->actingAs($owner)->get(route('orders.invoice', $order->order_no))->assertOk();
});

it('POL-06: being paid BV on an order does not make it yours to read', function () {
    $seller = Distributor::factory()->create();
    $buyer = User::factory()->create();

    // The distinction the policy turns on: a distributor is *attributed*
    // orders placed by the people they sell to, and those are somebody else's
    // purchases. Earning BV on an order is not a licence to read the buyer's
    // address.
    $order = polOrder($buyer, (int) $seller->id);

    $this->actingAs($seller->user)
        ->get(route('orders.show', $order->order_no))
        ->assertNotFound();
});
