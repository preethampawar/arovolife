<?php

declare(strict_types=1);

/**
 * Slice 2: what a buyer who collects is actually charged, and what the invoice
 * says about where the supply happened.
 *
 * Two defects are pinned here. R-94: a collection order paid the ₹60 delivery
 * fee for a delivery that never happened. And the tax defect that the obvious
 * fix introduces — once a collection order stops storing an address,
 * InvoiceGenerator's `$order->ship_state ?? $sellerState` silently treats every
 * inter-state collection as intra-state, charging CGST/SGST where IGST is due
 * and freezing that onto the invoice at checkout.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\ShippingService;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Identity\Models\User;
use App\Modules\Tax\Services\InvoiceGenerator;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    // Collection ships OFF (R-97). The checkout-rendering test below asserts
    // what a buyer sees when the option IS offered, so turn it on explicitly.
    DB::table('settings')->updateOrInsert(
        ['key' => AreteCenter::COLLECTION_ENABLED_SETTING],
        ['value' => 'true', 'version' => 1, 'updated_at' => now()],
    );
});

function feeSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $value, 'version' => 1, 'updated_at' => now()],
    );
}

function sellableVariant(int $pricePaise = 100000): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "COL-{$n}", 'slug' => "col-{$n}", 'name' => "COL {$n}", 'hsn_code' => '3004', 'status' => 'active']);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "COL-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => $pricePaise, 'sale_price_paise' => $pricePaise, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

function collectionCentre(string $state = 'TELANGANA'): AreteCenter
{
    $n = random_int(100000, 999999);

    return AreteCenter::create([
        'name' => "Centre {$n}", 'type' => AreteCenter::TYPE_COMPANY, 'status' => AreteCenter::STATUS_ACTIVE,
        'address_line_1' => '5 Market Street', 'city' => 'Warangal', 'state' => $state,
        'pincode' => '506002', 'contact_number' => '+918888888888', 'is_company_default' => true,
    ]);
}

function placeOrder(ProductVariant $variant, ?AreteCenter $centre, int $qty = 1): Order
{
    $user = User::create([
        'full_name' => 'Col Buyer', 'email' => 'col-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => 'col'.uniqid(), 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => $qty,
        'unit_price_paise' => $variant->sale_price_paise, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
    ]);

    // A collection passes no address, exactly as CheckoutController now does.
    $shipping = $centre === null
        ? ['name' => 'Col', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MAHARASHTRA', 'pincode' => '411001']
        : ['name' => 'Col', 'phone' => '+919800000000', 'line1' => null, 'line2' => null, 'city' => null, 'state' => null, 'pincode' => null];

    return app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => 'Col', 'email' => $user->email, 'phone' => '+919800000000', 'marketing_opt_in' => false],
        $shipping,
        [], null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null,
        true, null, $centre?->id,
    )->fresh();
}

it('charges a collection nothing while the fee is zero', function () {
    feeSetting('commerce.shipping.fee_rupees', '60');
    feeSetting('commerce.collection_fee_rupees', '0');

    $order = placeOrder(sellableVariant(), collectionCentre());

    // R-94: this used to be 6000 paise for a delivery that never happened.
    expect($order->shipping_paise)->toBe(0)
        ->and($order->collection_fee_paise)->toBe(0)
        ->and($order->delivery_type)->toBe(Order::DELIVERY_COLLECT)
        ->and($order->total_paise)->toBe(100000);
});

it('charges the collection fee instead of the delivery fee, never as well', function () {
    feeSetting('commerce.shipping.fee_rupees', '60');
    feeSetting('commerce.collection_fee_rupees', '50');

    $order = placeOrder(sellableVariant(), collectionCentre());

    expect($order->shipping_paise)->toBe(0)
        ->and($order->collection_fee_paise)->toBe(5000)
        ->and($order->total_paise)->toBe(105000);
});

it('still charges a delivery fee for a home delivery', function () {
    feeSetting('commerce.shipping.fee_rupees', '60');
    feeSetting('commerce.collection_fee_rupees', '50');

    $order = placeOrder(sellableVariant(), null);

    expect($order->shipping_paise)->toBe(6000)
        ->and($order->collection_fee_paise)->toBe(0)
        ->and($order->delivery_type)->toBe(Order::DELIVERY_SHIP)
        ->and($order->total_paise)->toBe(106000);
});

it('does not let the free-shipping threshold waive a collection fee', function () {
    feeSetting('commerce.shipping.fee_rupees', '60');
    feeSetting('commerce.collection_fee_rupees', '50');
    feeSetting('commerce.shipping.free_threshold_rupees', '100');

    $shipping = app(ShippingService::class);

    // Well above the threshold: delivery is free, collection is not. The
    // threshold buys off a delivery cost, and there is no delivery here.
    expect($shipping->feeForOrderPaise(5000000, false))->toBe(['shipping' => 0, 'collection' => 0])
        ->and($shipping->feeForOrderPaise(5000000, true))->toBe(['shipping' => 0, 'collection' => 5000]);
});

it('stores no delivery address on a collection order', function () {
    $order = placeOrder(sellableVariant(), collectionCentre());

    // The heart of R-47. The centre's postal address used to land in these
    // columns and render as the buyer's own under "Shipping to".
    expect($order->ship_line1)->toBeNull()
        ->and($order->ship_line2)->toBeNull()
        ->and($order->ship_city)->toBeNull()
        ->and($order->ship_state)->toBeNull()
        ->and($order->ship_pincode)->toBeNull()
        // Name and phone stay: the centre has to know who may collect.
        ->and($order->ship_name)->toBe('Col')
        ->and($order->ship_phone_e164)->toBe('+919800000000');
});

it('never writes the centre address into the buyer\'s address book', function () {
    $centre = collectionCentre();
    $order = placeOrder(sellableVariant(), $centre);

    // Otherwise the centre's address would prefill the buyer's next order.
    expect(DB::table('customer_addresses')->where('customer_id', $order->customer_id)->count())->toBe(0);
});

it('charges IGST on an inter-state collection, not CGST and SGST', function () {
    // Seller in Telangana; the buyer collects from a centre in Andhra Pradesh.
    feeSetting('tax.seller_state', 'TELANGANA');
    $order = placeOrder(sellableVariant(), collectionCentre('ANDHRA PRADESH'));

    $invoice = app(InvoiceGenerator::class)->generate($order);

    // With `ship_state` null and no centre lookup, place of supply fell back to
    // the SELLER's state and this invoice came out intra-state — CGST/SGST
    // where IGST is due, frozen at checkout where no later render fixes it.
    expect($invoice->place_of_supply)->toBe('ANDHRA PRADESH')
        ->and($invoice->buyer_state)->toBe('ANDHRA PRADESH');

    $lines = DB::table('invoice_lines')->where('invoice_id', $invoice->id)->get();
    expect($lines)->not->toBeEmpty();
    foreach ($lines as $line) {
        expect((int) $line->igst_paise)->toBeGreaterThan(0)
            ->and((int) $line->cgst_paise)->toBe(0)
            ->and((int) $line->sgst_paise)->toBe(0);
    }
});

it('charges CGST and SGST on an intra-state collection', function () {
    feeSetting('tax.seller_state', 'TELANGANA');
    $order = placeOrder(sellableVariant(), collectionCentre('TELANGANA'));

    $invoice = app(InvoiceGenerator::class)->generate($order);

    expect($invoice->place_of_supply)->toBe('TELANGANA');

    $lines = DB::table('invoice_lines')->where('invoice_id', $invoice->id)->get();
    foreach ($lines as $line) {
        expect((int) $line->igst_paise)->toBe(0)
            ->and((int) $line->cgst_paise)->toBeGreaterThan(0);
    }
});

/**
 * Renders the real checkout page. The summary was restructured into two
 * server-costed blocks, and a Blade that compiles is not the same as a Blade
 * that renders the right numbers — this is the check that the figure a buyer
 * sees for each delivery method is the one they will be charged.
 */
function checkoutPageFor(ProductVariant $variant): string
{
    DB::table('settings')->updateOrInsert(['key' => 'commerce.checkout.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
    DB::table('settings')->updateOrInsert(['key' => 'payments.gateway.stub.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
    collectionCentre();

    $user = User::create([
        'full_name' => 'Page Buyer', 'email' => 'page-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    // A signed-in buyer's cart is found by customer_id, not by the anon
    // cookie, so the Customer row has to exist and be linked.
    $customer = Customer::create([
        'user_id' => $user->id,
        'display_name' => 'Page Buyer',
    ]);
    $cart = Cart::create([
        'customer_id' => $customer->id,
        'anonymous_key' => 'pg'.uniqid(),
        'expires_at' => now()->addDay(),
    ]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => $variant->sale_price_paise, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
    ]);

    return test()->actingAs($user)
        ->get(route('shop.checkout'))
        ->assertOk()
        ->getContent();
}

it('shows a separately costed total for each delivery method', function () {
    feeSetting('commerce.shipping.fee_rupees', '60');
    feeSetting('commerce.collection_fee_rupees', '0');

    $html = checkoutPageFor(sellableVariant());

    // Both blocks are rendered by the server; the toggle only changes which
    // one is visible, so no arithmetic happens in the browser.
    expect($html)->toContain('data-summary="ship"')
        ->toContain('data-summary="collect"')
        // Delivery is charged, collection is not.
        ->toContain('₹60.00')
        ->toContain('No charge');
});

it('does not tell a consumer about BV at the collection centre', function () {
    $html = checkoutPageFor(sellableVariant());

    // Shown to guests with no ADN, to whom BV means nothing while framing
    // their purchase as feeding someone's compensation.
    expect($html)->not->toContain('its BV is collected at that centre')
        ->toContain('for you to collect');
});
