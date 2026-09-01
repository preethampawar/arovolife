<?php

declare(strict_types=1);

/**
 * EH-M4: an illegal order-state transition posted from the admin UI must
 * surface as a validation error on the order page, not as a 500.
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

function aotAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'Order Admin',
        'email' => 'aot-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

/** An order sitting in `placed` — neither shippable nor deliverable. */
function aotPlacedOrder(): Order
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "AOT-{$n}", 'slug' => "aot-{$n}", 'name' => "AOT {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create(['product_id' => $product->id, 'variant_sku' => "AOT-{$n}-V1", 'name' => 'Default', 'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active']);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);
    $cart = Cart::create(['anonymous_key' => "aot{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800]);

    return app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => 'AOT', 'email' => "aot-{$n}@test.com", 'phone' => '+919800000000', 'marketing_opt_in' => false],
        ['name' => 'AOT', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'],
        [], null, 'direct', Order::PAYMENT_ONLINE, null, null, null,
    );
}

it('EH-M4-01: refusing to ship an unpaid order redirects with an error instead of a 500', function (): void {
    $order = aotPlacedOrder();

    $this->actingAs(aotAdmin())
        ->post(route('admin.commerce.orders.ship', $order), ['ship_carrier' => 'Delhivery'])
        ->assertRedirect(route('admin.commerce.orders.show', $order))
        ->assertSessionHasErrors('ship');

    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED);
});

it('EH-M4-02: refusing to deliver an unshipped order redirects with an error instead of a 500', function (): void {
    $order = aotPlacedOrder();

    $this->actingAs(aotAdmin())
        ->post(route('admin.commerce.orders.deliver', $order))
        ->assertRedirect(route('admin.commerce.orders.show', $order))
        ->assertSessionHasErrors('deliver');

    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED);
});
