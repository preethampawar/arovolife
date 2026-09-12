<?php

declare(strict_types=1);

/**
 * H6: only goods inspected as saleable go back on the shelf, into the batch
 * they left from. Damaged and non-saleable returns never re-enter stock
 * (food supplements, plan §10). Restock never changes the refund.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Returns\Models\BuybackDecision;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\InspectReturn;
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

/** @return array{0: ReturnRequest, 1: StockBatch, 2: StockBatch} a delivered order of 3 units, packed from one batch, with an open item return of 2 */
function rsrReturn(string $reason = ReturnRequest::REASON_DAMAGE): array
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "RSR-{$n}", 'slug' => "rsr-{$n}", 'name' => "RSR {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "RSR-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
    $batches = [];
    foreach (['EARLY' => 10, 'LATE' => 200] as $batchNo => $days) {
        $batch = StockBatch::create([
            'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => $batchNo,
            'expiry_date' => now()->addDays($days)->toDateString(), 'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
        ]);
        app(StockLedger::class)->post([
            'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
            'batch_id' => $batch->id, 'qty' => 10, 'unit_cost_paise' => 60000,
            'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
        ]);
        $batches[] = $batch;
    }

    $user = User::create([
        'full_name' => 'RSR Buyer', 'email' => 'rsr-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => 'rsr'.uniqid(), 'expires_at' => now()->addDay()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 3, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800]);
    $order = app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => 'RSR', 'email' => $user->email, 'phone' => '+919800000000', 'marketing_opt_in' => false],
        ['name' => 'RSR', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'],
        [], null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null,
    );
    $sm = app(OrderStateMachine::class);
    $sm->markPaid($order->fresh());
    $sm->markShipped($order->fresh(), null, 'Delhivery', 'AWB-1');
    $sm->markDelivered($order->fresh());

    $order->refresh();
    $order->update(['status' => Order::STATUS_REFUND_REQUESTED]);

    $rr = ReturnRequest::create([
        'rma_no' => 'RMA-RSR-'.$n, 'order_id' => $order->id, 'order_item_id' => $order->items()->firstOrFail()->id,
        'qty' => 2, 'reason' => $reason, 'opened_by_customer_id' => $order->customer_id, 'status' => ReturnRequest::STATUS_OPENED,
    ]);

    return [$rr, $batches[0]->fresh(), $batches[1]->fresh()];
}

it("inspection 'saleable' writes return_in into the original batch", function (): void {
    [$rr, $early, $late] = rsrReturn();
    expect($early->qty_on_hand)->toBe(7);

    app(InspectReturn::class)->record($rr, 'saleable', 'Sealed', null);

    $move = StockMovement::where('type', StockMovement::TYPE_RETURN_IN)->sole();
    expect($move->stock_batch_id)->toBe($early->id)
        ->and($move->qty)->toBe(2)
        ->and($move->reference_type)->toBe('return_request')
        ->and($move->reference_id)->toBe($rr->id)
        ->and($early->fresh()->qty_on_hand)->toBe(9)
        ->and($late->fresh()->qty_on_hand)->toBe(10)
        // The refund computation is untouched by the restock.
        ->and(BuybackDecision::where('return_request_id', $rr->id)->sole()->net_refund_paise)->toBe(300000);
});

it("inspection 'damaged' and 'non_saleable' write nothing", function (string $condition): void {
    [$rr, $early] = rsrReturn();

    app(InspectReturn::class)->record($rr, $condition, null, null);

    expect(StockMovement::where('type', StockMovement::TYPE_RETURN_IN)->exists())->toBeFalse()
        ->and($early->fresh()->qty_on_hand)->toBe(7);
})->with(['damaged', 'non_saleable']);

it('restock is idempotent per return request', function (): void {
    [$rr, $early] = rsrReturn();

    app(OrderFulfilmentService::class)->restockReturn($rr, null);
    app(OrderFulfilmentService::class)->restockReturn($rr, null);

    expect(StockMovement::where('type', StockMovement::TYPE_RETURN_IN)->count())->toBe(1)
        ->and($early->fresh()->qty_on_hand)->toBe(9);
});

it('a return whose item left from several batches goes into its own RET batch with the earliest expiry', function (): void {
    [$rr, $early, $late] = rsrReturn();
    // Make the item's pick span both batches.
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_SALE_OUT, 'variant_id' => $late->product_variant_id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $late->id, 'qty' => -1, 'unit_cost_paise' => 60000,
        'reference_type' => 'order_item', 'reference_id' => $rr->order_item_id,
    ]);

    app(OrderFulfilmentService::class)->restockReturn($rr, null);

    $batch = StockMovement::where('type', StockMovement::TYPE_RETURN_IN)->sole()->batch;
    expect($batch->batch_no)->toBe('RET-'.$rr->rma_no)
        ->and($batch->qty_on_hand)->toBe(2)
        ->and($batch->expiry_date->toDateString())->toBe($early->expiry_date->toDateString());
});
