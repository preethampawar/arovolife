<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Create a logged-in distributor and return [userId, distributorId]. */
function crwDistributor(): array
{
    $user = User::create([
        'full_name' => 'CRW Dist',
        'email' => 'crw-'.uniqid().'@test.com',
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

/**
 * Create a cart with one variant priced at the given paise.
 * Default ₹5,000 (500_000 paise) so the free-shipping threshold (₹4,000) is
 * met and shipping = 0, keeping the test totals clean.
 */
function crwCart(int $pricePaise = 500000): Cart
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "CRW-{$n}",
        'slug' => "crw-{$n}",
        'name' => "CRW Product {$n}",
        'hsn_code' => '3004',
        'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'variant_sku' => "CRW-{$n}-V1",
        'name' => 'Default',
        'mrp_paise' => $pricePaise,
        'sale_price_paise' => $pricePaise,
        'gst_rate_bp' => 0,
        'inventory_policy' => 'track',
        'status' => 'active',
    ]);
    InventoryLevel::create([
        'product_variant_id' => $variant->id,
        'warehouse_code' => 'DEFAULT',
        'on_hand' => 50,
        'reserved' => 0,
    ]);
    $cart = Cart::create(['anonymous_key' => "crw-k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id,
        'product_variant_id' => $variant->id,
        'qty' => 1,
        'unit_price_paise' => $pricePaise,
        'bv_paise' => 0,
        'gst_rate_bp' => 0,
    ]);

    return $cart->load('items.variant.product');
}

function crwBuyer(): array
{
    return [
        'name' => 'CRW Buyer',
        'email' => 'crw-buyer-'.uniqid().'@test.com',
        'phone' => '+919800000001',
        'marketing_opt_in' => false,
    ];
}

function crwAddr(): array
{
    return [
        'name' => 'CRW Buyer',
        'phone' => '+919800000001',
        'line1' => '1 Test St',
        'line2' => null,
        'city' => 'Pune',
        'state' => 'MH',
        'pincode' => '411001',
    ];
}

/** Seed a repurchase_deduction credit entry for the given distributor. */
function crwSeedCredit(int $distributorId, int $amountPaise): void
{
    app(WalletService::class)->credit(
        distributorId: $distributorId,
        amountPaise: $amountPaise,
        type: 'repurchase_deduction',
        referenceId: null,
        referenceType: null,
        memo: 'Test seed credit',
    );
}

// ---------------------------------------------------------------------------
// Unit tests for repurchaseWalletBalancePaise
// ---------------------------------------------------------------------------

it('CRW-01: distributor with repurchase_deduction credits has a positive balance', function (): void {
    [, $distributorId] = crwDistributor();

    crwSeedCredit($distributorId, 5000);
    crwSeedCredit($distributorId, 3000);

    $balance = app(WalletService::class)->repurchaseWalletBalancePaise($distributorId);

    expect($balance)->toBe(8000);
});

it('CRW-02: distributor with matching credits and debits has a zero balance', function (): void {
    [, $distributorId] = crwDistributor();

    crwSeedCredit($distributorId, 5000);
    app(WalletService::class)->debit(
        distributorId: $distributorId,
        amountPaise: 5000,
        type: 'repurchase_wallet_used',
        referenceId: null,
        referenceType: null,
        memo: 'Test debit',
    );

    $balance = app(WalletService::class)->repurchaseWalletBalancePaise($distributorId);

    expect($balance)->toBe(0);
});

it('CRW-03: balance is floored at 0 when debits exceed credits', function (): void {
    [, $distributorId] = crwDistributor();

    crwSeedCredit($distributorId, 2000);
    app(WalletService::class)->debit(
        distributorId: $distributorId,
        amountPaise: 5000,
        type: 'repurchase_wallet_used',
        referenceId: null,
        referenceType: null,
        memo: 'Test over-debit',
    );

    $balance = app(WalletService::class)->repurchaseWalletBalancePaise($distributorId);

    expect($balance)->toBe(0);
});

// ---------------------------------------------------------------------------
// Integration tests — checkout with repurchase credit
// ---------------------------------------------------------------------------

it('CRW-04: checkout reduces order total by available repurchase credit and records ledger entry', function (): void {
    [$userId, $distributorId] = crwDistributor();

    // Seed ₹200 (20000 paise) credit; cart is ₹5000 (500000 paise), free shipping.
    crwSeedCredit($distributorId, 20000);

    $order = app(CheckoutService::class)->place(
        cart: crwCart(),
        buyer: crwBuyer(),
        shipping: crwAddr(),
        billing: crwAddr(),
        attributedDistributorId: $distributorId,
        attributionSource: 'direct',
        paymentMethod: Order::PAYMENT_ONLINE,
        authUserId: $userId,
        buyerDistributorId: $distributorId,
    );

    // Order total should be 500000 - 20000 = 480000 paise.
    expect($order->total_paise)->toBe(480000);

    // A repurchase_wallet_used debit entry should exist for this order.
    $debit = WalletLedgerEntry::where('type', 'repurchase_wallet_used')
        ->where('reference_id', $order->id)
        ->where('reference_type', 'order')
        ->first();

    expect($debit)->not->toBeNull();
    expect($debit->amount_paise)->toBe(-20000); // stored as negative
});

it('CRW-05: checkout caps repurchase credit at the order total (credit exceeds order)', function (): void {
    [$userId, $distributorId] = crwDistributor();

    // Seed ₹10000 (1000000 paise) credit; cart is ₹5000 (500000 paise), free shipping.
    crwSeedCredit($distributorId, 1000000);

    $order = app(CheckoutService::class)->place(
        cart: crwCart(),
        buyer: crwBuyer(),
        shipping: crwAddr(),
        billing: crwAddr(),
        attributedDistributorId: $distributorId,
        attributionSource: 'direct',
        paymentMethod: Order::PAYMENT_ONLINE,
        authUserId: $userId,
        buyerDistributorId: $distributorId,
    );

    // Order total capped at zero — credit is capped at 500000 paise.
    expect($order->total_paise)->toBe(0);

    $debit = WalletLedgerEntry::where('type', 'repurchase_wallet_used')
        ->where('reference_id', $order->id)
        ->first();

    expect($debit)->not->toBeNull();
    expect($debit->amount_paise)->toBe(-500000);
});

it('CRW-06: checkout proceeds normally with no credit when distributor has no repurchase credits', function (): void {
    [$userId, $distributorId] = crwDistributor();

    // No credits seeded.
    $order = app(CheckoutService::class)->place(
        cart: crwCart(),
        buyer: crwBuyer(),
        shipping: crwAddr(),
        billing: crwAddr(),
        attributedDistributorId: $distributorId,
        attributionSource: 'direct',
        paymentMethod: Order::PAYMENT_ONLINE,
        authUserId: $userId,
        buyerDistributorId: $distributorId,
    );

    // Full total unchanged (no shipping on ₹5000 cart).
    expect($order->total_paise)->toBe(500000);

    // No repurchase_wallet_used entry created.
    expect(WalletLedgerEntry::where('type', 'repurchase_wallet_used')->count())->toBe(0);
});

it('CRW-07: repurchaseCreditAppliedToOrder returns the absolute debit amount for an order', function (): void {
    [$userId, $distributorId] = crwDistributor();

    crwSeedCredit($distributorId, 15000);

    $order = app(CheckoutService::class)->place(
        cart: crwCart(),
        buyer: crwBuyer(),
        shipping: crwAddr(),
        billing: crwAddr(),
        attributedDistributorId: $distributorId,
        attributionSource: 'direct',
        paymentMethod: Order::PAYMENT_ONLINE,
        authUserId: $userId,
        buyerDistributorId: $distributorId,
    );

    $applied = app(WalletService::class)->repurchaseCreditAppliedToOrder($order->id);

    expect($applied)->toBe(15000);
});
