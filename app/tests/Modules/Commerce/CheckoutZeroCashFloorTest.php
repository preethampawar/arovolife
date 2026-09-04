<?php

declare(strict_types=1);

/**
 * Orders settled without cash, and the ₹1 floor for those nearly so.
 *
 * CZC-01: floorPayable leaves ≥ ₹1 and exactly ₹0 alone
 * CZC-02: a 1–99 paise payable is taken to ₹1 by applying less repurchase credit; the residue stays in the wallet
 * CZC-03: with no credit to give, the coupon gives way instead
 * CZC-04: a fully credit-settled order places with total 0 and no ledger prepayment, and can be shipped once paid
 * CZC-05: the checkout summary explains the ₹1 minimum when it applies
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\User;
use App\Modules\Ledger\Models\LedgerTx;
use App\Modules\Payments\Services\PaymentConfirmationService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

/** @return array{0: int, 1: int} */
function czcDistributor(): array
{
    $user = User::create([
        'full_name' => 'CZC Dist',
        'email' => 'czc-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
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
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return [$user->id, $id];
}

function czcCart(int $pricePaise): Cart
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CZC-{$n}", 'slug' => "czc-{$n}", 'name' => "CZC {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CZC-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => $pricePaise, 'sale_price_paise' => $pricePaise, 'gst_rate_bp' => 0,
        'inventory_policy' => 'track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);
    $cart = Cart::create(['anonymous_key' => "czc-k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => $pricePaise, 'bv_paise' => 0, 'gst_rate_bp' => 0,
    ]);

    return $cart->load('items.variant.product');
}

/** @return array<string, mixed> */
function czcAddr(): array
{
    return ['name' => 'CZC Buyer', 'phone' => '+919800000001', 'line1' => '1 Test St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'];
}

function czcPlace(Cart $cart, int $userId, int $distributorId): Order
{
    return app(CheckoutService::class)->place(
        cart: $cart,
        buyer: ['name' => 'CZC Buyer', 'email' => 'czc-buyer-'.uniqid().'@test.com', 'phone' => '+919800000001', 'marketing_opt_in' => false],
        shipping: czcAddr(),
        billing: czcAddr(),
        attributedDistributorId: $distributorId,
        attributionSource: 'direct',
        paymentMethod: Order::PAYMENT_ONLINE,
        authUserId: $userId,
        buyerDistributorId: $distributorId,
    );
}

function czcCredit(int $distributorId, int $amountPaise): void
{
    app(WalletService::class)->credit(
        distributorId: $distributorId, amountPaise: $amountPaise, type: 'repurchase_deduction',
        referenceId: null, referenceType: null, memo: 'Test seed credit',
    );
}

it('CZC-01: floorPayable leaves ₹1 and above, and exactly ₹0, alone', function () {
    expect(CheckoutService::floorPayable(0, 5000, 0)['adjustment'])->toBe(0)
        ->and(CheckoutService::floorPayable(100, 5000, 0)['adjustment'])->toBe(0)
        ->and(CheckoutService::floorPayable(118000, 0, 0)['adjustment'])->toBe(0);

    $r = CheckoutService::floorPayable(40, 5000, 0);
    expect($r)->toBe(['total' => 100, 'credit' => 4940, 'discount' => 0, 'adjustment' => 60]);

    $r = CheckoutService::floorPayable(40, 20, 300);
    expect($r)->toBe(['total' => 100, 'credit' => 0, 'discount' => 260, 'adjustment' => 60]);

    expect(fn () => CheckoutService::floorPayable(40, 0, 0))->toThrow(RuntimeException::class, '₹1 minimum');
});

it('CZC-02: a sub-₹1 payable is taken to ₹1 by applying less credit; the residue stays in the wallet', function () {
    [$userId, $distributorId] = czcDistributor();
    // ₹5,000 cart (free shipping), ₹4,999.60 of credit → 40 paise would be payable.
    czcCredit($distributorId, 499960);

    $order = czcPlace(czcCart(500000), $userId, $distributorId);

    expect($order->total_paise)->toBe(100)
        ->and(app(WalletService::class)->repurchaseCreditAppliedToOrder($order->id))->toBe(499900)
        ->and(app(WalletService::class)->repurchaseWalletBalancePaise($distributorId))->toBe(60);

    // The prepayment posted matches the ₹1 actually payable.
    $tx = LedgerTx::where('idempotency_key', 'order.placed:'.$order->id)->sole();
    expect(DB::table('ledger_entries')->where('ledger_tx_id', $tx->id)->where('side', 'debit')->sum('amount_paise'))->toBe(100);
});

it('CZC-04: a fully credit-settled order places at ₹0 with no prepayment, confirms without a gateway, and ships', function () {
    [$userId, $distributorId] = czcDistributor();
    czcCredit($distributorId, 500000);

    $order = czcPlace(czcCart(500000), $userId, $distributorId);

    expect($order->total_paise)->toBe(0)
        ->and(LedgerTx::where('idempotency_key', 'order.placed:'.$order->id)->exists())->toBeFalse()
        ->and(app(WalletService::class)->repurchaseCreditAppliedToOrder($order->id))->toBe(500000);

    app(PaymentConfirmationService::class)->confirmZeroCash($order, $userId);
    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);

    // Revenue recognition must balance without a prepayment line.
    app(OrderStateMachine::class)->markShipped($order->fresh(), $userId, 'Test Courier', 'TRK1');
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});
