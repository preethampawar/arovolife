<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

/**
 * Client 2026-10-09: an Easy Purchase (a customer buying through a
 * distributor's ?ref= link) is paid in full by the customer. The referring
 * distributor's repurchase wallet is never spent on someone else's order —
 * only the signed-in buyer's own wallet can be.
 */
beforeEach(function (): void {
    seed(LedgerAccountSeeder::class);
    DB::table('settings')->updateOrInsert(
        ['key' => 'commerce.checkout.enabled'],
        ['value' => 'true', 'version' => 1, 'updated_at' => now()],
    );
});

/** A signed-in shopper with no distributor record, holding a one-item cart. */
function epwCustomerWithCart(int $pricePaise): User
{
    $user = User::create([
        'full_name' => 'Easy Purchase Customer',
        'email' => 'epw-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "EPW-{$n}", 'slug' => "epw-{$n}", 'name' => "EPW {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "EPW-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => $pricePaise, 'sale_price_paise' => $pricePaise, 'bv_paise' => 50_000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);

    $customer = Customer::create(['display_name' => $user->full_name, 'user_id' => $user->id]);
    $cart = Cart::create(['customer_id' => $customer->id, 'anonymous_key' => 'epw'.$n, 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => $pricePaise, 'bv_paise' => 50_000, 'gst_rate_bp' => 1800,
    ]);

    return $user;
}

it('an Easy Purchase order never debits the referring distributor\'s repurchase wallet', function (): void {
    $sponsor = uiDistributor();
    // resolveForCheckout() only credits an active/pending distributor by ADN.
    DB::table('distributors')->where('id', $sponsor['id'])->update(['status' => 'active']);
    $adn = (string) DB::table('distributors')->where('id', $sponsor['id'])->value('adn');
    uiRepurchaseWallet($sponsor['id'], 50_000);

    $customer = epwCustomerWithCart(400_000);

    // The av_ref cookie is what following the sponsor's ?ref=<ADN> link sets.
    actingAs($customer)
        ->withCookie(AttributionService::COOKIE_NAME, $adn)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.checkout.place'), [
            'buyer_name' => 'Easy Purchase Customer',
            'buyer_email' => $customer->email,
            'buyer_phone' => '9800000000',
            'ship_line1' => '1 Test St',
            'ship_city' => 'Pune',
            'ship_state' => 'Maharashtra',
            'ship_pincode' => '411001',
            'delivery_type' => 'ship',
            'payment_method' => 'online',
            'billing_same' => '1',
            'accept_terms' => '1',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $order = Order::latest('id')->firstOrFail();
    $wallet = app(WalletService::class);

    // The link was honoured: the sale is the sponsor's, not self-consumption.
    expect($order->attributed_distributor_id)->toBe($sponsor['id']);
    expect($order->getAttribute('self_consumption'))->toBeFalse();

    // ...and the sponsor paid nothing towards it.
    expect($wallet->repurchaseCreditAppliedToOrder($order->id))->toBe(0)
        ->and($wallet->repurchaseWalletBalancePaise($sponsor['id']))->toBe(50_000)
        ->and(DB::table('wallet_ledger_entries')
            ->where('distributor_id', $sponsor['id'])
            ->where('type', 'repurchase_wallet_used')
            ->exists())->toBeFalse();
});
