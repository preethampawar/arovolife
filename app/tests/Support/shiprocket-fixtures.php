<?php

declare(strict_types=1);

/**
 * Shiprocket fixtures shared by the fulfilment tests. Loaded with
 * require_once, so the constants and functions are declared exactly once
 * however many test files use them.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterDeclaration;
use App\Modules\Compensation\Support\AreteCenterDeclarations;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Services\CourierGatewayResolver;
use App\Modules\Fulfilment\Services\DispatchService;
use App\Modules\Fulfilment\Services\ShiprocketGateway;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const SR_SANDBOX = 'https://api-sandbox.shiprocket.in/v1/external';
const SR_TOKEN = 'tok-secret-never-stored';
const SR_BUYER_PHONE = '9812345678';
const SR_BUYER_NAME = 'Ravi Kumarswamy';
const SR_BUYER_LINE1 = '42 Lake View Road';

function srSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'version' => 1, 'updated_at' => now()]);
    app()->forgetInstance(FulfilmentSettings::class);
    app()->forgetInstance(ShiprocketGateway::class);
    app()->forgetInstance(CourierGatewayResolver::class);
    app()->forgetInstance(DispatchService::class);
}

/** @param  array<string, mixed>  $overrides */
function srVariant(array $overrides = []): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "SR-{$n}", 'slug' => "sr-{$n}", 'name' => "Aloe Juice {$n}", 'hsn_code' => '2202', 'status' => 'active']);

    return ProductVariant::create(array_merge([
        'product_id' => $product->id, 'variant_sku' => "SR-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
        'weight_g' => 1200, 'length_mm' => 250, 'breadth_mm' => 100, 'height_mm' => 80,
    ], $overrides));
}

function srCentre(): AreteCenter
{
    $n = random_int(100000, 999999);
    $centre = AreteCenter::create([
        'name' => "Centre {$n}", 'type' => AreteCenter::TYPE_COMPANY, 'status' => AreteCenter::STATUS_ACTIVE,
        'address_line_1' => '5 Market Street', 'city' => 'Warangal', 'state' => 'TELANGANA',
        'pincode' => '506002', 'contact_number' => '+918888888888', 'is_company_default' => true,
    ]);
    foreach (array_keys(AreteCenterDeclarations::all()) as $key) {
        AreteCenterDeclaration::create([
            'center_id' => $centre->id, 'declaration_key' => $key,
            'version' => AreteCenterDeclarations::VERSION, 'accepted_at' => now(), 'ip' => '127.0.0.1',
        ]);
    }

    return $centre;
}

function srPaidOrder(?ProductVariant $variant = null, int $qty = 2, ?AreteCenter $centre = null): Order
{
    $variant ??= srVariant();
    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_no' => 'B'.random_int(1000, 9999), 'expiry_date' => now()->addYear()->toDateString(),
        'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_id' => $batch->id, 'qty' => 10,
        'unit_cost_paise' => 60000, 'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
    ]);
    $user = User::create([
        'full_name' => SR_BUYER_NAME, 'email' => 'sr-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => 'sr'.uniqid(), 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => $qty,
        'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
    ]);

    $shipping = $centre === null
        ? ['name' => SR_BUYER_NAME, 'phone' => '+91'.SR_BUYER_PHONE, 'line1' => SR_BUYER_LINE1, 'line2' => null, 'city' => 'Pune', 'state' => 'MAHARASHTRA', 'pincode' => '411001']
        : ['name' => SR_BUYER_NAME, 'phone' => '+91'.SR_BUYER_PHONE, 'line1' => null, 'line2' => null, 'city' => null, 'state' => null, 'pincode' => null];

    $order = app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => SR_BUYER_NAME, 'email' => $user->email, 'phone' => '+91'.SR_BUYER_PHONE, 'marketing_opt_in' => false],
        $shipping,
        [], null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null,
        false, null, $centre?->id,
    );

    app(OrderStateMachine::class)->markPaid($order->fresh());

    return $order->fresh();
}

/** @param  array<string, mixed>  $overrides  URL pattern => response, replacing the happy-path default */
function srFake(array $overrides = []): void
{
    Http::fake(array_merge([
        SR_SANDBOX.'/auth/login' => Http::response(['token' => SR_TOKEN]),
        SR_SANDBOX.'/orders/create/adhoc' => Http::response(['order_id' => 9001, 'shipment_id' => 7001, 'status' => 'NEW', 'status_code' => 1]),
        SR_SANDBOX.'/courier/assign/awb' => Http::response(['awb_assign_status' => 1, 'response' => ['data' => ['awb_code' => 'AWB778899', 'courier_name' => 'Delhivery Surface', 'shipment_id' => 7001]]]),
        SR_SANDBOX.'/courier/generate/pickup' => Http::response(['pickup_status' => 1, 'response' => ['pickup_scheduled_date' => '2026-09-25 10:00:00']]),
        SR_SANDBOX.'/courier/generate/label' => Http::response(['label_created' => 1, 'label_url' => 'https://labels.example.test/7001.pdf']),
    ], $overrides));
}

function srAvailable(): array
{
    app()->forgetInstance(CourierGatewayResolver::class);

    return array_keys(app(CourierGatewayResolver::class)->available());
}

function srDispatch(Order $order, ?string $route = Shipment::GATEWAY_SHIPROCKET, ?string $carrier = null, bool $confirmed = false): Shipment
{
    return app(DispatchService::class)->dispatch($order, $route, $carrier, null, null, null, $confirmed);
}
