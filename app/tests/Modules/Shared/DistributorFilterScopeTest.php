<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\ListFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * A distributor's list filters may narrow their own data and nothing else.
 *
 * The filter rollout (docs/plans/list-page-filters-2026-09-13.md) put a GET
 * toolbar on seven distributor-facing pages. Each of those pages is scoped to
 * the signed-in distributor, and CLAUDE.md hard rule 3 makes that scope
 * absolute: earnings, orders and wallet data are own-only. A filter is
 * therefore only ever allowed to *narrow* what the viewer could already see.
 *
 * These assertions live here rather than in the Playwright suite on purpose.
 * The browser suite can only check that no control named `adn` is rendered;
 * it cannot prove that a hand-typed `?user_id=<someone else>` fails to widen
 * the query, because the dev database has no second distributor whose rows
 * the test is allowed to look at. Here both distributors are fixtures, so the
 * test can assert the exact thing that matters: the other distributor's row
 * never appears, whatever the URL says.
 *
 * @see ListFilters
 */
uses(RefreshDatabase::class);

/** @return array{0: User, 1: int} */
function dfsUserWithDistributor(string $tag): array
{
    $user = User::create([
        'full_name' => 'DFS '.$tag,
        'email' => 'dfs-'.$tag.'-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    disableTestForeignKeys();

    try {
        $distId = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'ADN'.random_int(10000, 99999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $distId)
            ->update(['sponsor_id' => $distId, 'placement_parent_id' => $distId]);
    } finally {
        enableTestForeignKeys();
    }

    return [$user->fresh(), $distId];
}

function dfsOrder(int $userId, ?int $distId, string $orderNo): Order
{
    $customer = Customer::firstOrCreate(['user_id' => $userId], ['display_name' => 'DFS', 'distributor_id' => $distId]);

    $order = Order::create([
        'order_no' => $orderNo,
        'customer_id' => $customer->id,
        'attributed_distributor_id' => $distId,
        'attribution_source' => 'logged_in',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_DELIVERED,
        'self_consumption' => false,
        'subtotal_paise' => 100000, 'gst_paise' => 0, 'discount_paise' => 0,
        'shipping_paise' => 0, 'total_paise' => 100000,
        'ship_name' => 'DFS', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => now()->subDays(5),
        'delivered_at' => now()->subDays(2),
        'idempotency_key' => 'idem-'.uniqid(),
    ]);

    disableTestForeignKeys();

    try {
        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => 1,
            'product_name_snapshot' => 'Test product',
            'variant_sku_snapshot' => 'SKU-DFS',
            'hsn_code_snapshot' => '0000',
            'qty' => 1,
            'unit_price_paise' => 100000,
            'bv_paise' => 0,
            'gst_rate_bp' => 0,
            'taxable_value_paise' => 100000,
            'gst_paise' => 0,
            'line_total_paise' => 100000,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    return $order;
}

it('DFS-01: the orders list shows only the signed-in distributor\'s own orders', function (): void {
    [$mine] = dfsUserWithDistributor('mine');
    [$theirs, $theirDist] = dfsUserWithDistributor('theirs');

    dfsOrder($mine->id, null, 'ORD-MINE-1');
    dfsOrder($theirs->id, $theirDist, 'ORD-THEIRS-1');

    $this->actingAs($mine)->get(route('orders.index'))
        ->assertOk()
        ->assertSee('ORD-MINE-1')
        ->assertDontSee('ORD-THEIRS-1');
});

it('DFS-02: an injected identity parameter cannot widen the orders list', function (): void {
    [$mine] = dfsUserWithDistributor('mine');
    [$theirs, $theirDist] = dfsUserWithDistributor('theirs');

    dfsOrder($mine->id, null, 'ORD-MINE-2');
    dfsOrder($theirs->id, $theirDist, 'ORD-THEIRS-2');

    // Every identity key a hand-edited URL might try, at once.
    $injected = http_build_query([
        'user_id' => $theirs->id,
        'distributor_id' => $theirDist,
        'adn' => 'ADN00000',
        'sponsor_id' => $theirDist,
        'customer_id' => $theirs->id,
    ]);

    $this->actingAs($mine)->get(route('orders.index').'?'.$injected)
        ->assertOk()
        ->assertSee('ORD-MINE-2')
        ->assertDontSee('ORD-THEIRS-2');
});

it('DFS-03: an injected identity parameter cannot widen the sales list', function (): void {
    [$mine, $myDist] = dfsUserWithDistributor('mine');
    [$theirs, $theirDist] = dfsUserWithDistributor('theirs');

    // A sale attributed to each distributor, bought by a third party.
    [$buyerA] = dfsUserWithDistributor('buyer-a');
    [$buyerB] = dfsUserWithDistributor('buyer-b');
    dfsOrder($buyerA->id, $myDist, 'ORD-MYSALE');
    dfsOrder($buyerB->id, $theirDist, 'ORD-THEIRSALE');

    $injected = http_build_query([
        'distributor_id' => $theirDist,
        'attributed_distributor_id' => $theirDist,
        'user_id' => $theirs->id,
    ]);

    $this->actingAs($mine)->get(route('orders.sales').'?'.$injected)
        ->assertOk()
        ->assertSee('ORD-MYSALE')
        ->assertDontSee('ORD-THEIRSALE');
});

it('DFS-04: a self-consumption order never appears in the sales list, filtered or not', function (): void {
    [$mine, $myDist] = dfsUserWithDistributor('mine');

    $selfOrder = dfsOrder($mine->id, $myDist, 'ORD-SELF');
    $selfOrder->update(['self_consumption' => true]);

    // Bare, and with every spelling of a filter that might try to reach it.
    foreach (['', '?self_consumption=1', '?self_consumption=true', '?status=delivered&self_consumption=1'] as $query) {
        $this->actingAs($mine)->get(route('orders.sales').$query)
            ->assertOk()
            ->assertDontSee('ORD-SELF');
    }
});

it('DFS-05: a filter narrows the viewer\'s own rows without reaching anyone else\'s', function (): void {
    [$mine] = dfsUserWithDistributor('mine');
    [$theirs, $theirDist] = dfsUserWithDistributor('theirs');

    $keep = dfsOrder($mine->id, null, 'ORD-KEEP');
    $drop = dfsOrder($mine->id, null, 'ORD-DROP');
    dfsOrder($theirs->id, $theirDist, 'ORD-THEIRS-5');

    $drop->update(['status' => Order::STATUS_CANCELLED]);
    $keep->update(['status' => Order::STATUS_DELIVERED]);

    $this->actingAs($mine)->get(route('orders.index').'?status='.Order::STATUS_DELIVERED)
        ->assertOk()
        ->assertSee('ORD-KEEP')
        ->assertDontSee('ORD-DROP')
        ->assertDontSee('ORD-THEIRS-5');
});

it('DFS-06: a nonsense filter value is discarded and the list stays own-scoped', function (): void {
    [$mine] = dfsUserWithDistributor('mine');
    [$theirs, $theirDist] = dfsUserWithDistributor('theirs');

    dfsOrder($mine->id, null, 'ORD-MINE-6');
    dfsOrder($theirs->id, $theirDist, 'ORD-THEIRS-6');

    $this->actingAs($mine)->get(route('orders.index').'?status=__not_a_status__&placed_from=not-a-date')
        ->assertOk()
        ->assertSee('ORD-MINE-6')
        ->assertDontSee('ORD-THEIRS-6');
});

it('DFS-07: no distributor-facing page renders a control keyed to another identity', function (): void {
    [$mine] = dfsUserWithDistributor('mine');

    $routes = [
        route('orders.index'),
        route('orders.sales'),
        route('my.grievances.index'),
        route('notifications.index'),
    ];

    foreach ($routes as $url) {
        $html = $this->actingAs($mine)->get($url)->assertOk()->getContent();

        foreach (['adn', 'user_id', 'distributor_id', 'sponsor_id'] as $key) {
            expect($html)->not->toContain('name="'.$key.'"');
        }
    }
});
