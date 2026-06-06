<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    DB::table('settings')->updateOrInsert(['key' => 'commerce.self_purchase.earns_bv'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
});

/** @return array{0:int,1:int} [userId, distributorId] for a self-rooted distributor. */
function cscDistributor(): array
{
    $user = User::create([
        'full_name' => 'CSC Dist', 'email' => 'csc-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => 'ADN'.random_int(10000, 99999),
            'pan_hash' => random_bytes(32), 'pan_last4' => '0000',
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return [$user->id, $id];
}

function cscCart(): Cart
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CSC-{$n}", 'slug' => "csc-{$n}", 'name' => "SC {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CSC-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);
    $cart = Cart::create(['anonymous_key' => "k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
    ]);

    return $cart->load('items.variant.product');
}

function cscAddr(): array
{
    return ['name' => 'SC Buyer', 'phone' => '+919800000000', 'line1' => '1 Test St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'];
}

/** @param array{0:int,1:int} $dist */
function cscPlace(array $dist, string $email, ?int $attributedDistributorId, ?int $authUserId, ?int $buyerDistributorId): Order
{
    return app(CheckoutService::class)->place(
        cart: cscCart(),
        buyer: ['name' => 'SC Buyer', 'email' => $email, 'phone' => '+919800000000', 'marketing_opt_in' => false],
        shipping: cscAddr(),
        billing: cscAddr(),
        attributedDistributorId: $attributedDistributorId,
        attributionSource: $attributedDistributorId !== null ? 'logged_in' : 'direct',
        paymentMethod: Order::PAYMENT_COD,
        authUserId: $authUserId,
        buyerDistributorId: $buyerDistributorId,
    );
}

it('flags self_consumption + backfills distributor_id when the buyer is a logged-in distributor whose customer row was only partly linked', function (): void {
    [$userId, $distId] = cscDistributor();
    $email = 'partly-'.uniqid().'@test.com';

    // Customer claimed by this user earlier, but distributor_id never set
    // (claimed before they became a distributor). This is the gap the fix closes.
    $customer = Customer::create([
        'display_name' => 'SC Buyer',
        'email_hash' => hash('sha256', strtolower(trim($email))),
        'email_enc' => $email,
        'user_id' => $userId,
        'distributor_id' => null,
        'claimed_at' => now(),
    ]);

    $order = cscPlace([$userId, $distId], $email, attributedDistributorId: $distId, authUserId: $userId, buyerDistributorId: $distId);

    expect($order->self_consumption)->toBeTrue();
    expect($customer->fresh()->distributor_id)->toBe($distId); // backfilled
});

it('attaches a logged-in buyer\'s order to their OWN customer row, never a stranger\'s matched by email (KP NAIK regression)', function (): void {
    [$buyerUserId, $buyerDistId] = cscDistributor();

    // A different person ("KP NAIK") already owns a customer row for this email.
    $strangerUser = User::create([
        'full_name' => 'KP NAIK', 'email' => 'stranger-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $sharedEmail = 'shared-'.uniqid().'@test.com';
    $stranger = Customer::create([
        'display_name' => 'KP NAIK',
        'email_hash' => hash('sha256', strtolower(trim($sharedEmail))),
        'email_enc' => $sharedEmail,
        'user_id' => $strangerUser->id,
        'claimed_at' => now(),
    ]);

    // Shankar (logged in) checks out typing the SAME email as the stranger.
    $order = cscPlace([$buyerUserId, $buyerDistId], $sharedEmail, attributedDistributorId: $buyerDistId, authUserId: $buyerUserId, buyerDistributorId: $buyerDistId)->load('customer');

    expect($order->customer_id)->not->toBe($stranger->id);     // not the stranger's row
    expect($order->customer->user_id)->toBe($buyerUserId);     // the buyer's own row
    expect($order->customer->display_name)->toBe('SC Buyer');  // shows the buyer, not "KP NAIK"
    expect($stranger->fresh()->display_name)->toBe('KP NAIK'); // stranger's row untouched
});

it('flags self_consumption for a logged-in distributor buying for the first time', function (): void {
    [$userId, $distId] = cscDistributor();

    $order = cscPlace([$userId, $distId], 'first-'.uniqid().'@test.com', attributedDistributorId: $distId, authUserId: $userId, buyerDistributorId: $distId);

    expect($order->self_consumption)->toBeTrue();
});

it('does NOT flag self_consumption for a guest / house order', function (): void {
    $order = cscPlace([0, 0], 'guest-'.uniqid().'@test.com', attributedDistributorId: null, authUserId: null, buyerDistributorId: null);

    expect($order->self_consumption)->toBeFalse();
});

it('does NOT flag self_consumption when the sale is attributed to a different referrer', function (): void {
    [$buyerUserId, $buyerDistId] = cscDistributor();
    [, $referrerDistId] = cscDistributor();

    // Buyer is a distributor, but the sale is attributed to someone else (their
    // referrer) — it is not the buyer's own personal-BV purchase.
    $order = cscPlace([$buyerUserId, $buyerDistId], 'ref-'.uniqid().'@test.com', attributedDistributorId: $referrerDistId, authUserId: $buyerUserId, buyerDistributorId: $buyerDistId);

    expect($order->self_consumption)->toBeFalse();
});

it('accrues the buyer\'s personal BV on payment for the partly-linked case (end-to-end)', function (): void {
    [$userId, $distId] = cscDistributor();
    $email = 'e2e-'.uniqid().'@test.com';
    Customer::create([
        'display_name' => 'SC Buyer',
        'email_hash' => hash('sha256', strtolower(trim($email))),
        'email_enc' => $email,
        'user_id' => $userId,
        'distributor_id' => null,
        'claimed_at' => now(),
    ]);

    $order = cscPlace([$userId, $distId], $email, attributedDistributorId: $distId, authUserId: $userId, buyerDistributorId: $distId);
    app(OrderStateMachine::class)->markPaid($order->fresh()->load('items'));

    expect((int) BvLedgerEntry::where('distributor_id', $distId)->where('type', 'accrual')->sum('bv_paise'))->toBe(50000);
});
