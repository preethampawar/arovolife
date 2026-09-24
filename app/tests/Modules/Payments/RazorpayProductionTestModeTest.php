<?php

declare(strict_types=1);

/**
 * R-110: pre-launch testing on the production host with Razorpay TEST keys.
 *
 * PTM-01: production + test key + override on + before launch day → accepted
 * PTM-02: from launch day (IST) the override has no effect, whatever the setting says
 * PTM-03: without the override production still refuses test keys
 * PTM-04: the override never lets a live key run outside production, nor changes non-production
 * PTM-05: checkout shows the "no real money" notice only while the override is in force
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Services\RazorpayClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function ptmConfig(string $keyId, bool $override): void
{
    config()->set('arovolife.payments.razorpay', [
        'key_id' => $keyId, 'key_secret' => 'secret-xyz', 'webhook_secret' => 'whsec',
        'base_url' => 'https://api.razorpay.com/v1', 'timeout_seconds' => 5,
        'allow_test_mode_in_production' => $override,
    ]);
}

function ptmSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'version' => 1, 'updated_at' => now()]);
}

/** A logged-in customer with one item in the cart, so the checkout page renders. */
function ptmCustomerWithCart(): User
{
    $user = User::create([
        'full_name' => 'Tess Tester', 'email' => 'ptm-'.uniqid().'@test.com',
        'phone_e164' => '+919800000009', 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "PTM-{$n}", 'slug' => "ptm-{$n}", 'name' => "PTM {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "PTM-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);
    $customer = Customer::create(['display_name' => $user->full_name, 'user_id' => $user->id, 'distributor_id' => null]);
    $cart = Cart::create(['customer_id' => $customer->id, 'anonymous_key' => "k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
    ]);

    return $user;
}

it('PTM-01: production accepts a test key while the override is on and launch day has not come', function (): void {
    ptmConfig('rzp_test_ABCDEF123456', true);
    app()->detectEnvironment(fn () => 'production');
    $this->travelTo(CarbonImmutable::parse('2026-11-09 23:59:59', 'Asia/Kolkata'));

    $client = app(RazorpayClient::class);
    expect($client->productionTestModeActive())->toBeTrue()
        ->and($client->modeMatchesEnvironment())->toBeTrue();
});

it('PTM-02: from launch day (IST) production refuses test keys even with the override on', function (): void {
    ptmConfig('rzp_test_ABCDEF123456', true);
    app()->detectEnvironment(fn () => 'production');
    $this->travelTo(CarbonImmutable::parse(RazorpayClient::PRODUCTION_TEST_MODE_ENDS, 'Asia/Kolkata'));

    $client = app(RazorpayClient::class);
    expect($client->productionTestModeActive())->toBeFalse()
        ->and($client->modeMatchesEnvironment())->toBeFalse();
});

it('PTM-03: without the override production still refuses test keys', function (): void {
    ptmConfig('rzp_test_ABCDEF123456', false);
    app()->detectEnvironment(fn () => 'production');
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'Asia/Kolkata'));

    expect(app(RazorpayClient::class)->modeMatchesEnvironment())->toBeFalse();
});

it('PTM-04: the override changes nothing outside production and never admits a live key there', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'Asia/Kolkata'));
    app()->detectEnvironment(fn () => 'staging');

    ptmConfig('rzp_live_ABCDEF123456', true);
    expect(app(RazorpayClient::class)->modeMatchesEnvironment())->toBeFalse();

    ptmConfig('rzp_test_ABCDEF123456', true);
    expect(app(RazorpayClient::class)->productionTestModeActive())->toBeFalse()
        ->and(app(RazorpayClient::class)->modeMatchesEnvironment())->toBeTrue();
});

it('PTM-05: checkout warns that no real money moves only while production test mode is in force', function (): void {
    ptmSetting('commerce.storefront.enabled', 'true');
    ptmSetting('commerce.checkout.enabled', 'true');
    ptmSetting('commerce.guest_checkout.enabled', 'true');
    ptmSetting('payments.gateway.razorpay.enabled', 'true');
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'Asia/Kolkata'));
    $user = ptmCustomerWithCart();
    app()->detectEnvironment(fn () => 'production');

    ptmConfig('rzp_test_ABCDEF123456', true);
    $this->actingAs($user)->get(route('shop.checkout'))->assertOk()
        ->assertSee('Pre-launch test payments — no real money moves.')
        ->assertSee('You will be taken to a secure payment page.');

    ptmConfig('rzp_live_ABCDEF123456', false);
    $this->actingAs($user)->get(route('shop.checkout'))->assertOk()
        ->assertDontSee('Pre-launch test payments')
        ->assertSee('You will be taken to a secure payment page.');
});
