<?php

declare(strict_types=1);

/**
 * Plan §7.3 — `inventory:alerts` mails low-stock/expiry rows once, then
 * throttles the same row for 7 days so the same shortage does not land in
 * the inbox every morning.
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Notifications\InventoryAlertsNotification;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(InventoryFeature::class);
    DB::table('settings')->insert(['key' => 'inventory.alert_email', 'value' => 'ops@arovolife.test']);
});

function acVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "AC-{$n}", 'slug' => "ac-{$n}", 'name' => "AC {$n}", 'hsn_code' => '3004', 'status' => 'active']);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "AC-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

it('emails once, then suppresses the same low-stock row for 7 days', function (): void {
    $variant = acVariant();
    InventoryLevel::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'on_hand' => 5, 'reserved' => 0, 'reorder_level' => 10,
    ]);

    Notification::fake();
    $this->artisan('inventory:alerts')->assertSuccessful();
    Notification::assertSentOnDemand(InventoryAlertsNotification::class);

    // Re-running immediately: the same row was just alerted, so nothing new is sent.
    Notification::fake();
    $this->artisan('inventory:alerts')->assertSuccessful();
    Notification::assertNothingSent();

    // 8 days later the throttle has lapsed and the still-low row is alerted again.
    $this->travel(8)->days();
    Notification::fake();
    $this->artisan('inventory:alerts')->assertSuccessful();
    Notification::assertSentOnDemand(InventoryAlertsNotification::class);
});

it('sends nothing when InventoryFeature is off', function (): void {
    Notification::fake();
    Feature::for(null)->deactivate(InventoryFeature::class);

    $variant = acVariant();
    InventoryLevel::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'on_hand' => 1, 'reserved' => 0, 'reorder_level' => 10,
    ]);

    $this->artisan('inventory:alerts')->assertSuccessful();

    Notification::assertNothingSent();
});
