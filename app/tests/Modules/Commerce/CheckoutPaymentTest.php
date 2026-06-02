<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\CustomerAddress;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class); // ledger accounts the poster credits/debits
});

function cpoCart(): Cart
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CPO-{$n}", 'slug' => "cpo-{$n}", 'name' => "Pay {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CPO-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);
    $cart = Cart::create(['anonymous_key' => "k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
    ]);

    return $cart->load('items.variant.product');
}

function cpoBuyer(): array
{
    return ['name' => 'Pay Buyer', 'email' => 'pay-'.uniqid().'@test.com', 'phone' => '+919800000000', 'marketing_opt_in' => false];
}

function cpoAddr(string $city = 'Pune', string $line1 = '1 Test St'): array
{
    return ['name' => 'Pay Buyer', 'phone' => '+919800000000', 'line1' => $line1, 'line2' => null, 'city' => $city, 'state' => 'MH', 'pincode' => '411001'];
}

it('E4-01: a COD order is placed unpaid with NO cash ledger entry at placement', function (): void {
    $order = app(CheckoutService::class)->place(cpoCart(), cpoBuyer(), cpoAddr(), cpoAddr(), null, 'direct', Order::PAYMENT_COD);

    expect($order->payment_method)->toBe('cod');
    expect($order->status)->toBe('placed');
    expect(DB::table('ledger_tx')->where('source_type', 'order.placed')->where('source_id', $order->id)->count())->toBe(0);
});

it('E4-02: an online order posts the prepayment cash-in ledger entry at placement', function (): void {
    $order = app(CheckoutService::class)->place(cpoCart(), cpoBuyer(), cpoAddr(), cpoAddr(), null, 'direct', Order::PAYMENT_ONLINE);

    expect($order->payment_method)->toBe('online');
    expect(DB::table('ledger_tx')->where('source_type', 'order.placed')->where('source_id', $order->id)->count())->toBe(1);
});

it('E4-03: marking a COD order paid posts the cash-in ledger entry and flips to paid', function (): void {
    $order = app(CheckoutService::class)->place(cpoCart(), cpoBuyer(), cpoAddr(), cpoAddr(), null, 'direct', Order::PAYMENT_COD);

    app(OrderStateMachine::class)->markPaid($order->fresh());

    expect($order->fresh()->status)->toBe('paid');
    expect(DB::table('ledger_tx')->where('source_type', 'order.cod_collected')->where('source_id', $order->id)->count())->toBe(1);
});

it('E4-04: checkout saves shipping + billing addresses on file for the customer', function (): void {
    $order = app(CheckoutService::class)->place(
        cpoCart(), cpoBuyer(), cpoAddr('Pune', '1 Ship St'), cpoAddr('Mumbai', '9 Bill Rd'), null, 'direct', Order::PAYMENT_COD
    );
    $customer = Order::find($order->id)->customer;

    expect(CustomerAddress::where('customer_id', $customer->id)->where('kind', 'shipping')->exists())->toBeTrue();
    $billing = CustomerAddress::where('customer_id', $customer->id)->where('kind', 'billing')->first();
    expect($billing)->not->toBeNull();
    expect($billing->city)->toBe('Mumbai');
});

it('E4-05: shipping a DISCOUNTED order posts a BALANCED revenue-recognition entry (H-1 regression)', function (): void {
    $customer = Customer::create(['display_name' => 'Disc Buyer']);
    // subtotal 999.00, gst 152.39, discount 99.90 → total 899.10 (mirrors the
    // browser-verified coupon order). Before the contra-revenue fix this entry
    // was out of balance by the discount and the poster would reject it.
    $order = Order::create([
        'order_no' => 'ORD-DISC-'.random_int(1000, 9999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PAID,
        'subtotal_paise' => 99900, 'gst_paise' => 15239, 'discount_paise' => 9990,
        'shipping_paise' => 0, 'total_paise' => 89910,
        'ship_name' => 'Disc Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => now(), 'paid_at' => now(), 'idempotency_key' => 'idem-'.uniqid(),
    ]);

    // Must not throw — the LedgerPoster rejects unbalanced transactions.
    app(OrderStateMachine::class)->markShipped($order);
    expect($order->fresh()->status)->toBe('shipped');

    $tx = DB::table('ledger_tx')->where('source_type', 'order.shipped')->where('source_id', $order->id)->first();
    expect($tx)->not->toBeNull();

    // Net of debits − credits across the transaction's entries must be zero.
    $net = (int) DB::table('ledger_entries')->where('ledger_tx_id', $tx->id)
        ->selectRaw("SUM(CASE WHEN side='debit' THEN amount_paise ELSE -amount_paise END) AS net")->value('net');
    expect($net)->toBe(0);

    // The discount is booked to the contra-revenue account.
    $hasContra = DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_tx_id', $tx->id)
        ->where('ledger_accounts.code', 'revenue.discounts')
        ->where('ledger_entries.amount_paise', 9990)
        ->exists();
    expect($hasContra)->toBeTrue();
});
