<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Epic-2 storefront tests: category nav driven by the category master,
 * category filtering, and the refreshed product detail (attributes spec
 * table, BV, gallery, sanitized WYSIWYG body).
 */
function scatEnableStorefront(): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => 'commerce.storefront.enabled'],
        ['value' => 'true', 'version' => 1, 'updated_at' => now()],
    );
}

/** @param  array<string, mixed>  $overrides */
function scatProduct(string $sku, string $slug, ProductCategory $cat, array $overrides = []): Product
{
    $product = Product::create(array_merge([
        'sku' => $sku,
        'slug' => $slug,
        'name' => 'Test '.$sku,
        'short_description' => 'A short blurb.',
        'description_html' => '<p>Default body</p>',
        'category_id' => $cat->id,
        'hsn_code' => '3004',
        'manufacturer' => 'Arovolife Labs',
        'country_of_origin' => 'India',
        'status' => Product::STATUS_ACTIVE,
    ], $overrides));

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'variant_sku' => $sku.'-V1',
        'name' => 'Default',
        'attributes' => ['Volume' => '500 ml'],
        'weight_g' => 250,
        'mrp_paise' => 120000,
        'sale_price_paise' => 99900,
        'cost_paise' => 30000,
        'landing_price_paise' => 35000,
        'distributor_price_paise' => 70000,
        'bv_paise' => 55000,
        'pv_paise' => 55000,
        'gst_rate_bp' => 1800,
        'inventory_policy' => 'track',
        'status' => 'active',
    ]);

    InventoryLevel::create([
        'product_variant_id' => $variant->id,
        'warehouse_code' => 'DEFAULT',
        'on_hand' => 50,
        'reserved' => 0,
    ]);

    return $product;
}

it('SCAT-01: /shop lists category pills from the category master', function (): void {
    scatEnableStorefront();
    $health = ProductCategory::create(['slug' => 'health-care', 'name' => 'Health Care', 'sort' => 1, 'status' => 'active']);
    ProductCategory::create(['slug' => 'skin-and-beauty', 'name' => 'Skin and Beauty', 'sort' => 2, 'status' => 'active']);
    scatProduct('AV-T1', 't1', $health);

    $this->get(route('shop.index'))
        ->assertOk()
        ->assertSee('Health Care')
        ->assertSee('Skin and Beauty');
});

it('SCAT-02: /shop?category= filters products to that category', function (): void {
    scatEnableStorefront();
    $health = ProductCategory::create(['slug' => 'health-care', 'name' => 'Health Care', 'sort' => 1, 'status' => 'active']);
    $skin = ProductCategory::create(['slug' => 'skin-and-beauty', 'name' => 'Skin and Beauty', 'sort' => 2, 'status' => 'active']);
    scatProduct('AV-HEALTH', 'health-prod', $health, ['name' => 'Healthy Tonic']);
    scatProduct('AV-SKIN', 'skin-prod', $skin, ['name' => 'Glow Serum']);

    $response = $this->get(route('shop.index', ['category' => 'health-care']))->assertOk();
    $response->assertSee('Healthy Tonic');
    $response->assertDontSee('Glow Serum');
});

/** Minimal active distributor (user + self-referencing distributors row) with a known ADN. */
function scatDistributor(string $adn): User
{
    $user = User::create([
        'full_name' => 'Dist '.$adn,
        'email' => 'scat-dist-'.$adn.'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => $adn,
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

    return $user->fresh();
}

it('SCAT-03: product detail renders sorted rich attributes, gallery, category and sanitized WYSIWYG; BV hidden from public', function (): void {
    Storage::fake('s3');
    scatEnableStorefront();
    $health = ProductCategory::create(['slug' => 'health-care', 'name' => 'Health Care', 'sort' => 1, 'status' => 'active']);
    $product = scatProduct('AV-DET', 'detail-prod', $health, [
        'name' => 'Detail Tonic',
        'description_html' => '<p>Rich herbal body for the storefront.</p>',
    ]);
    ProductImage::create(['product_id' => $product->id, 's3_key' => 'products/gallery/x.jpg', 'kind' => 'gallery', 'sort' => 0]);
    // Two attributes; "Ingredients" (sort 1) must render before "Storage" (sort 2).
    ProductAttribute::create(['product_id' => $product->id, 'label' => 'Storage', 'value_html' => '<p>Keep cool and dry.</p>', 'sort' => 2]);
    ProductAttribute::create(['product_id' => $product->id, 'label' => 'Ingredients', 'value_html' => '<table><tbody><tr><td>Water 90%</td></tr></tbody></table>', 'sort' => 1]);

    $response = $this->get(route('shop.product', 'detail-prod'))->assertOk();
    $response->assertSee('Detail Tonic');
    $response->assertSee('Ingredients');                         // rich attribute label
    $response->assertSee('Water 90%');                           // rich attribute value (table)
    $response->assertSee('Keep cool and dry.', false);           // second attribute body
    $response->assertSee('Storage');
    $response->assertSee('Product information');                 // attribute section heading
    $response->assertDontSee('550 BV');                          // BV hidden from anonymous visitors (compliance: no implied income)
    $response->assertDontSee('Distributor price');               // after-login pricing hidden from public
    $response->assertDontSee('Easy Purchase');                   // share affordance is distributor-only
    $response->assertSee('Health Care');                         // category from master
    $response->assertSee('Arovolife Labs');                      // manufacturer in product-facts table
    $response->assertSee('Rich herbal body for the storefront.', false); // sanitized WYSIWYG body

    // Sorted: Ingredients appears before Storage in the rendered HTML.
    $html = $response->getContent();
    expect(strpos($html, 'Ingredients'))->toBeLessThan(strpos($html, 'Keep cool and dry.'));
});

it('SCAT-04: a logged-in distributor sees the distributor price, BV and the Easy Purchase share link', function (): void {
    Storage::fake('s3');
    scatEnableStorefront();
    $health = ProductCategory::create(['slug' => 'health-care', 'name' => 'Health Care', 'sort' => 1, 'status' => 'active']);
    scatProduct('AV-DP', 'dp-prod', $health, ['name' => 'Tier Tonic']);
    $dist = scatDistributor('AV12345678');

    $response = $this->actingAs($dist)->get(route('shop.product', 'dp-prod'))->assertOk();
    $response->assertSee('Distributor price');                   // after-login pricing visible
    $response->assertSee('₹700.00');                             // distributor_price_paise 70000
    $response->assertSee('550 BV');                              // BV visible to distributor
    $response->assertSee('Easy Purchase');                       // share affordance visible
    $response->assertSee('ref=AV12345678', false);               // referral link carries this ADN
});
