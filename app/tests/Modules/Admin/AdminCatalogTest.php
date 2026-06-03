<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Epic-1 (Catalog Admin) tests: product CRUD with pricing tiers + attributes,
 * S3 image upload, WYSIWYG sanitization, category CRUD, admin-only auth, and
 * audit logging.
 */
function acatAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'Catalog Admin',
        'email' => 'acat-admin-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

function acatNonAdmin(): User
{
    return User::create([
        'full_name' => 'Plain User',
        'email' => 'acat-user-'.uniqid().'@example.com',
        'phone_e164' => '+9181111'.rand(10000, 99999),
        'password_hash' => bcrypt('User!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function acatProductPayload(int $categoryId, array $overrides = []): array
{
    return array_merge([
        'name' => 'Test Wellness Tonic',
        'sku' => 'AV-TST-'.rand(1000, 9999),
        'slug' => 'test-wellness-tonic-'.rand(1000, 9999),
        'category_id' => $categoryId,
        'hsn_code' => '3004',
        'image_url' => 'https://cdn.example.com/tonic.jpg',
        'manufacturer' => 'Arovolife Labs',
        'country_of_origin' => 'India',
        'short_description' => 'A test tonic.',
        'status' => 'active',
        'mrp' => '1000',
        'sale_price' => '850.50',
        'cost_price' => '300',
        'landing_price' => '350',
        'distributor_price' => '700',
        'bv' => '500',
        'gst_rate' => '18',
        'weight_g' => '250',
        'inventory_policy' => 'track',
        'on_hand' => '40',
        // Rich, sortable product attributes (label + WYSIWYG value + sort).
        'attr_labels' => ['Storage', 'Ingredients'],
        'attr_values_html' => ['<p>Keep cool &amp; dry.</p>', '<table><tbody><tr><td>Water</td><td>90%</td></tr></tbody></table>'],
        'attr_sort' => ['2', '1'],
        'description_html' => '<p>Great product</p>',
    ], $overrides);
}

it('ACAT-01: admin creates a product with pricing tiers + attributes + inventory + audit', function (): void {
    Storage::fake('s3');
    $admin = acatAdmin();
    $cat = ProductCategory::create(['slug' => 'health-care', 'name' => 'Health Care', 'sort' => 1, 'status' => 'active']);

    $response = $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.products.store'), acatProductPayload($cat->id, [
            'sku' => 'AV-TONIC-1', 'slug' => 'tonic-one',
        ]));

    $response->assertRedirect();

    $product = Product::where('sku', 'AV-TONIC-1')->first();
    expect($product)->not->toBeNull();
    expect($product->category_id)->toBe($cat->id);
    expect($product->manufacturer)->toBe('Arovolife Labs');
    expect($product->image_url)->toBe('https://cdn.example.com/tonic.jpg');

    $variant = $product->primaryVariant();
    expect($variant->mrp_paise)->toBe(100000);          // ₹1000.00
    expect($variant->sale_price_paise)->toBe(85050);     // ₹850.50
    expect($variant->distributor_price_paise)->toBe(70000);
    expect($variant->landing_price_paise)->toBe(35000);
    expect($variant->bv_paise)->toBe(50000);
    expect($variant->gst_rate_bp)->toBe(1800);           // 18% → 1800 bp
    expect($variant->inventory->on_hand)->toBe(40);

    // Rich product attributes persisted and ordered by submitted sort.
    $attrs = $product->productAttributes;
    expect($attrs)->toHaveCount(2);
    expect($attrs[0]->label)->toBe('Ingredients');       // sort 1 comes first
    expect($attrs[0]->sort)->toBe(1);
    expect($attrs[0]->value_html)->toContain('<table>');
    expect($attrs[1]->label)->toBe('Storage');           // sort 2

    expect(AuditLog::where('action', 'catalog.product.created')->where('actor_id', $admin->id)->count())->toBe(1);
});

it('ACAT-07: a product attribute value_html is sanitized (script stripped, table kept)', function (): void {
    Storage::fake('s3');
    $admin = acatAdmin();
    $cat = ProductCategory::create(['slug' => 'health-care', 'name' => 'Health Care', 'sort' => 1, 'status' => 'active']);

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.products.store'), acatProductPayload($cat->id, [
            'sku' => 'AV-ATTR-XSS', 'slug' => 'attr-xss',
            'attr_labels' => ['Nutritional information'],
            'attr_values_html' => ['<table><tbody><tr><td>Energy</td><td>42 kcal</td></tr></tbody></table><script>alert(1)</script>'],
            'attr_sort' => ['1'],
        ]))
        ->assertRedirect();

    $attr = Product::where('sku', 'AV-ATTR-XSS')->first()->productAttributes->first();
    expect($attr->value_html)->toContain('42 kcal');
    expect($attr->value_html)->toContain('<table>');
    expect($attr->value_html)->not->toContain('<script');
    expect($attr->value_html)->not->toContain('alert(');
});

it('ACAT-02: product WYSIWYG description is sanitized (script stripped)', function (): void {
    Storage::fake('s3');
    $admin = acatAdmin();
    $cat = ProductCategory::create(['slug' => 'health-care', 'name' => 'Health Care', 'sort' => 1, 'status' => 'active']);

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.products.store'), acatProductPayload($cat->id, [
            'sku' => 'AV-XSS-1', 'slug' => 'xss-one',
            'description_html' => '<p>Safe body</p><script>alert("xss")</script><a href="https://x.test">link</a>',
        ]))
        ->assertRedirect();

    $product = Product::where('sku', 'AV-XSS-1')->first();
    expect($product->description_html)->toContain('Safe body');
    expect($product->description_html)->not->toContain('<script');
    expect($product->description_html)->not->toContain('alert(');
});

it('ACAT-03: gallery image uploads to S3 and records a ProductImage row', function (): void {
    Storage::fake('s3');
    $admin = acatAdmin();
    $cat = ProductCategory::create(['slug' => 'health-care', 'name' => 'Health Care', 'sort' => 1, 'status' => 'active']);

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.products.store'), acatProductPayload($cat->id, [
            'sku' => 'AV-IMG-1', 'slug' => 'img-one',
            'images' => [UploadedFile::fake()->image('front.jpg', 600, 600)],
        ]))
        ->assertRedirect();

    $product = Product::where('sku', 'AV-IMG-1')->first();
    $image = ProductImage::where('product_id', $product->id)->where('kind', 'gallery')->first();
    expect($image)->not->toBeNull();
    expect($image->s3_key)->toStartWith('products/gallery/');
    Storage::disk('s3')->assertExists($image->s3_key);
});

it('ACAT-03b: gallery image URLs are recorded as URL-only ProductImage rows (no S3)', function (): void {
    Storage::fake('s3');
    $admin = acatAdmin();
    $cat = ProductCategory::create(['slug' => 'health-care', 'name' => 'Health Care', 'sort' => 1, 'status' => 'active']);

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.products.store'), acatProductPayload($cat->id, [
            'sku' => 'AV-IMG-URL', 'slug' => 'img-url',
            // Textarea: one URL per line, with a blank line that must be dropped.
            'gallery_image_urls' => "https://cdn.example.com/a.jpg\n\nhttps://cdn.example.com/b.png",
        ]))
        ->assertRedirect();

    $product = Product::where('sku', 'AV-IMG-URL')->first();
    $images = ProductImage::where('product_id', $product->id)->where('kind', 'gallery')->orderBy('sort')->get();

    expect($images)->toHaveCount(2);
    expect($images->pluck('external_url')->all())
        ->toBe(['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.png']);
    // URL-only images carry no S3 key and url() returns the URL verbatim.
    expect($images->first()->s3_key)->toBeNull();
    expect($images->first()->url())->toBe('https://cdn.example.com/a.jpg');
});

it('ACAT-03c: an invalid gallery image URL is rejected', function (): void {
    Storage::fake('s3');
    $admin = acatAdmin();
    $cat = ProductCategory::create(['slug' => 'health-care', 'name' => 'Health Care', 'sort' => 1, 'status' => 'active']);

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.products.store'), acatProductPayload($cat->id, [
            'sku' => 'AV-IMG-BAD', 'slug' => 'img-bad',
            'gallery_image_urls' => 'not-a-url',
        ]))
        ->assertSessionHasErrors('gallery_image_urls.0');

    expect(Product::where('sku', 'AV-IMG-BAD')->exists())->toBeFalse();
});

it('ACAT-04: trix-upload stores an inline image and returns its URL', function (): void {
    Storage::fake('s3');
    $admin = acatAdmin();

    $response = $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.trix-upload'), ['file' => UploadedFile::fake()->image('inline.png', 400, 400)]);

    $response->assertOk()->assertJsonStructure(['url', 'key']);
    expect(ProductImage::where('kind', 'inline')->count())->toBe(1);
});

it('ACAT-05: admin creates a category with audit', function (): void {
    Storage::fake('s3');
    $admin = acatAdmin();

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.catalog.categories.store'), [
            'name' => 'Agri Care',
            'slug' => 'agri-care',
            'sort' => '5',
            'status' => 'active',
            'description' => 'Agriculture products.',
        ])
        ->assertRedirect();

    $cat = ProductCategory::where('slug', 'agri-care')->first();
    expect($cat)->not->toBeNull();
    expect($cat->name)->toBe('Agri Care');
    expect(AuditLog::where('action', 'catalog.category.created')->count())->toBe(1);
});

it('ACAT-06: a non-admin cannot reach the catalog admin', function (): void {
    $user = acatNonAdmin();

    $this->actingAs($user)
        ->get(route('admin.catalog.products.create'))
        ->assertForbidden();
});
