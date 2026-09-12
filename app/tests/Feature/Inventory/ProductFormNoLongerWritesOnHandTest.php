<?php

declare(strict_types=1);

/**
 * H8: on_hand is a projection of the stock ledger, so the product form no
 * longer writes it. It sets the DEFAULT reorder level instead and shows stock
 * read-only.
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function pfnAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'PFN Admin', 'email' => 'pfn-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.random_int(10000, 99999), 'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(), 'status' => 'active', 'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

/** @return array<string, mixed> */
function pfnPayload(int $categoryId, array $overrides = []): array
{
    return array_merge([
        'name' => 'PFN Tonic', 'sku' => 'AV-PFN-1', 'slug' => 'pfn-tonic', 'category_id' => $categoryId,
        'hsn_code' => '3004', 'status' => 'active', 'mrp' => '1000', 'sale_price' => '850',
        'gst_rate' => '18', 'inventory_policy' => 'track',
    ], $overrides);
}

it('saving a product no longer changes on_hand; reorder_level is saved on the DEFAULT level', function (): void {
    $admin = pfnAdmin();
    $cat = ProductCategory::create(['slug' => 'pfn', 'name' => 'PFN', 'sort' => 1, 'status' => 'active']);

    $this->actingAs($admin)->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.products.store'), pfnPayload($cat->id, ['on_hand' => '40', 'reorder_level' => '5']))
        ->assertRedirect();

    $product = Product::where('sku', 'AV-PFN-1')->firstOrFail();
    $variant = $product->primaryVariant();
    $level = InventoryLevel::where('product_variant_id', $variant->id)->where('warehouse_code', Warehouse::DEFAULT_CODE)->sole();
    expect($level->on_hand)->toBe(0)->and($level->reorder_level)->toBe(5);

    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_OPENING, 'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE, 'qty' => 12, 'reason' => 'Opening stock',
    ]);

    $this->actingAs($admin)->withoutMiddleware(PreventRequestForgery::class)
        ->put(route('admin.catalog.products.update', $product), pfnPayload($cat->id, ['on_hand' => '999', 'reorder_level' => '7']))
        ->assertRedirect();

    $level->refresh();
    expect($level->on_hand)->toBe(12)
        ->and($level->reorder_level)->toBe(7)
        ->and((int) StockMovement::where('product_variant_id', $variant->id)->sum('qty'))->toBe($level->on_hand);

    $this->actingAs($admin)->get(route('admin.catalog.products.edit', $product))
        ->assertOk()
        ->assertSee('Reorder level')
        ->assertSee('12 on hand')
        ->assertDontSee('name="on_hand"', false);
});
