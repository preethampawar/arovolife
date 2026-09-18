<?php

declare(strict_types=1);

/**
 * `reserved` is a counter, not a projection of the movement ledger, so the
 * ledger invariant in `inventory:verify` cannot see it drift. These pin the
 * three halves of that: the invariant that notices, the command that repairs
 * it, and the shipping path that used to strand one in the first place.
 *
 * They also pin the rule underneath all of it — stock only comes back if it
 * actually left.
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Returns\Models\ReturnRequest;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    DB::table('settings')->updateOrInsert(
        ['key' => 'commerce.self_purchase.earns_bv'],
        ['value' => 'false', 'version' => 1, 'updated_at' => now()],
    );
});

/**
 * A paid, unpacked order of 3 units against a batch of 10 — the state in which
 * a reservation is legitimately held.
 *
 * @return array{0: Order, 1: ProductVariant, 2: StockBatch}
 */
function riPaidOrder(): array
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "RI-{$n}", 'slug' => "ri-{$n}", 'name' => "RI {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "RI-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => "RI-{$n}",
        'expiry_date' => now()->addDays(90)->toDateString(), 'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id, 'qty' => 10, 'unit_cost_paise' => 60000,
        'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
    ]);

    $user = User::create([
        'full_name' => 'RI Buyer', 'email' => 'ri-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => 'ri'.uniqid(), 'expires_at' => now()->addDay()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 3, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800]);

    $order = app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => 'RI', 'email' => $user->email, 'phone' => '+919800000000', 'marketing_opt_in' => false],
        ['name' => 'RI', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'],
        [], null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null,
    );
    app(OrderStateMachine::class)->markPaid($order->fresh());

    return [$order->fresh(), $variant, $batch->fresh()];
}

function riReserved(ProductVariant $variant): int
{
    return (int) InventoryLevel::query()->where('product_variant_id', $variant->id)->sum('reserved');
}

it('holds a reservation for a paid unpacked order and the verifier is happy with it', function (): void {
    [, $variant] = riPaidOrder();

    expect(riReserved($variant))->toBe(3);

    $this->artisan('inventory:verify')->assertExitCode(0);
});

it('sees a reservation no open order accounts for, and reconciles it away', function (): void {
    [$order, $variant] = riPaidOrder();

    // The legacy shape this exists for: the order left the building without
    // ever being packed, so nothing ever released what checkout reserved.
    $order->update(['status' => Order::STATUS_SHIPPED]);

    expect(riReserved($variant))->toBe(3);

    $this->artisan('inventory:verify')->assertExitCode(1);

    // Reporting must not write anything.
    $this->artisan('inventory:reconcile-reservations')->assertExitCode(0);
    expect(riReserved($variant))->toBe(3);

    $this->artisan('inventory:reconcile-reservations --apply')->assertExitCode(0);

    expect(riReserved($variant))->toBe(0);
    $this->artisan('inventory:verify')->assertExitCode(0);
});

it('does not strand the reservation when an order ships without being packed', function (): void {
    [$order, $variant] = riPaidOrder();
    expect(riReserved($variant))->toBe(3);

    // The default warehouse cannot fulfil, so the H3 auto-pack bails out and
    // the order ships unpacked. The reservation must not survive it.
    Warehouse::query()->where('code', Warehouse::DEFAULT_CODE)->update(['fulfils_orders' => false]);

    app(OrderFulfilmentService::class)->packForShipment($order->fresh(), null);

    expect($order->fresh()->packed_at)->toBeNull()
        ->and(riReserved($variant))->toBe(0);
});

it('restocks nothing for a return on an order that never shipped', function (): void {
    [$order, $variant, $batch] = riPaidOrder();

    // Refunded without ever shipping: `shipped_at` is null, so the goods are
    // still here and there is nothing to put back. Restocking would invent it.
    $order->update(['status' => Order::STATUS_REFUNDED]);
    $item = $order->items()->firstOrFail();

    $rr = ReturnRequest::create([
        'rma_no' => 'RMA-RI-'.random_int(10000, 99999), 'order_id' => $order->id, 'order_item_id' => $item->id,
        'qty' => 3, 'reason' => ReturnRequest::REASON_DAMAGE,
        'opened_by_customer_id' => $order->customer_id, 'status' => ReturnRequest::STATUS_OPENED,
    ]);

    app(OrderFulfilmentService::class)->restockReturn($rr, null);

    expect(StockMovement::query()->where('type', StockMovement::TYPE_RETURN_IN)->exists())->toBeFalse()
        ->and($batch->fresh()->qty_on_hand)->toBe(10)
        ->and(app(StockLedger::class)->onHand($variant->id, Warehouse::DEFAULT_CODE))->toBe(10);

    $audit = AuditLog::query()->where('action', 'return.restocked')->sole();
    expect($audit->details['lines'][0]['basis'])->toBe(OrderFulfilmentService::RESTOCK_NEVER_SHIPPED)
        ->and($audit->details['lines'][0]['restocked'])->toBe(0);
});

it('trusts the inspector when the order shipped but no pack was ever recorded', function (): void {
    [$order, $variant, $batch] = riPaidOrder();

    // The live shape this has to get right: packForShipment bails out when the
    // warehouse cannot fulfil, so the order ships with no sale_out behind it.
    // The goods did leave, and an inspector is holding them.
    $order->update(['status' => Order::STATUS_DELIVERED, 'shipped_at' => now()]);
    $item = $order->items()->firstOrFail();

    $rr = ReturnRequest::create([
        'rma_no' => 'RMA-RI-'.random_int(10000, 99999), 'order_id' => $order->id, 'order_item_id' => $item->id,
        'qty' => 3, 'reason' => ReturnRequest::REASON_DAMAGE,
        'opened_by_customer_id' => $order->customer_id, 'status' => ReturnRequest::STATUS_OPENED,
    ]);

    app(OrderFulfilmentService::class)->restockReturn($rr, null);

    $move = StockMovement::query()->where('type', StockMovement::TYPE_RETURN_IN)->sole();
    expect($move->qty)->toBe(3)
        ->and($move->reason)->toContain('no pack was recorded')
        ->and(app(StockLedger::class)->onHand($variant->id, Warehouse::DEFAULT_CODE))->toBe(13);

    $audit = AuditLog::query()->where('action', 'return.restocked')->sole();
    expect($audit->details['lines'][0]['basis'])->toBe(OrderFulfilmentService::RESTOCK_UNRECORDED_PACK);
});

it('restocks only the units a partly shipped line actually sent out', function (): void {
    [$order, $variant, $batch] = riPaidOrder();
    $item = $order->items()->firstOrFail();

    // One of the three units was picked; the other two never left.
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_SALE_OUT, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id, 'qty' => -1, 'unit_cost_paise' => 60000,
        'reference_type' => OrderFulfilmentService::REFERENCE_ORDER_ITEM, 'reference_id' => $item->id,
    ]);
    $order->update(['status' => Order::STATUS_DELIVERED, 'shipped_at' => now()]);

    $rr = ReturnRequest::create([
        'rma_no' => 'RMA-RI-'.random_int(10000, 99999), 'order_id' => $order->id, 'order_item_id' => $item->id,
        'qty' => 3, 'reason' => ReturnRequest::REASON_DAMAGE,
        'opened_by_customer_id' => $order->customer_id, 'status' => ReturnRequest::STATUS_OPENED,
    ]);

    app(OrderFulfilmentService::class)->restockReturn($rr, null);

    expect(StockMovement::query()->where('type', StockMovement::TYPE_RETURN_IN)->sole()->qty)->toBe(1)
        ->and($batch->fresh()->qty_on_hand)->toBe(10);
});
