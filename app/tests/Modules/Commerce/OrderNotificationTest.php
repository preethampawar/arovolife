<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Events\OrderPlaced;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Commerce\Notifications\AdminNewOrderNotification;
use App\Modules\Commerce\Notifications\OrderPlacedNotification;
use App\Modules\Commerce\Notifications\OrderStatusChangedNotification;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

function onSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'version' => 1, 'updated_at' => now()]);
}

function onBuyerUser(): User
{
    return User::create([
        'full_name' => 'Notify Buyer', 'email' => 'notify-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
}

function onCart(): Cart
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "ON-{$n}", 'slug' => "on-{$n}", 'name' => "On {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create(['product_id' => $product->id, 'variant_sku' => "ON-{$n}-V1", 'name' => 'Default', 'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active']);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);
    $cart = Cart::create(['anonymous_key' => "k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800]);

    return $cart->load('items.variant.product');
}

/** A PAID order owned by $user, ready to ship. */
function onPaidOrder(User $user): Order
{
    $customer = Customer::firstOrCreate(['user_id' => $user->id], ['display_name' => 'On']);
    $order = Order::create([
        'order_no' => 'ORD-ON-'.random_int(10000, 99999),
        'customer_id' => $customer->id, 'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE, 'status' => Order::STATUS_PAID,
        'subtotal_paise' => 100000, 'gst_paise' => 15254, 'discount_paise' => 0,
        'shipping_paise' => 0, 'total_paise' => 100000,
        'ship_name' => 'Notify Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => now(), 'paid_at' => now(), 'idempotency_key' => 'idem-'.uniqid(),
    ]);
    disableTestForeignKeys();
    try {
        OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => 1,
            'product_name_snapshot' => 'X', 'variant_sku_snapshot' => 'X-1', 'hsn_code_snapshot' => '3004',
            'qty' => 1, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
            'taxable_value_paise' => 84746, 'gst_paise' => 15254, 'line_total_paise' => 100000,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    return $order;
}

function onBuyer(User $user): array
{
    return ['name' => 'Notify Buyer', 'email' => $user->email, 'phone' => '+919800000000', 'marketing_opt_in' => false];
}

function onAddr(): array
{
    return ['name' => 'Notify Buyer', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'];
}

it('dispatches OrderPlaced when an order is placed', function (): void {
    Event::fake([OrderPlaced::class]);
    $user = onBuyerUser();

    $order = app(CheckoutService::class)->place(onCart(), onBuyer($user), onAddr(), onAddr(), null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null);

    Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $e) => $e->orderId === $order->id);
});

it('emails the buyer their order-received confirmation on placement', function (): void {
    Notification::fake();
    $user = onBuyerUser();

    app(CheckoutService::class)->place(onCart(), onBuyer($user), onAddr(), onAddr(), null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null);

    Notification::assertSentTo($user, OrderPlacedNotification::class);
});

it('emails the admin a new-order alert when the recipient setting is configured', function (): void {
    onSetting('notifications.admin_order_email', 'ops@arovolife.com');
    Notification::fake();
    $user = onBuyerUser();

    app(CheckoutService::class)->place(onCart(), onBuyer($user), onAddr(), onAddr(), null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null);

    Notification::assertSentOnDemand(AdminNewOrderNotification::class);
});

it('sends NO admin alert when the recipient setting is blank', function (): void {
    onSetting('notifications.admin_order_email', '');
    Notification::fake();
    $user = onBuyerUser();

    app(CheckoutService::class)->place(onCart(), onBuyer($user), onAddr(), onAddr(), null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null);

    Notification::assertNotSentTo(new AnonymousNotifiable, AdminNewOrderNotification::class);
});

it('emails the buyer on a status change when the toggle is ON', function (): void {
    onSetting('notifications.email_on_status_change', 'true');
    Notification::fake();
    $user = onBuyerUser();
    $order = onPaidOrder($user);

    app(OrderStateMachine::class)->markShipped($order);

    Notification::assertSentTo($user, OrderStatusChangedNotification::class);
});

it('does NOT email on a status change when the toggle is OFF', function (): void {
    onSetting('notifications.email_on_status_change', 'false');
    Notification::fake();
    $user = onBuyerUser();
    $order = onPaidOrder($user);

    app(OrderStateMachine::class)->markShipped($order);

    Notification::assertNotSentTo($user, OrderStatusChangedNotification::class);
});
