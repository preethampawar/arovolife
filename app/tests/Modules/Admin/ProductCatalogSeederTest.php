<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Categories first, then the catalog (mirrors DatabaseSeeder order so the
    // category_id slug lookup resolves).
    $this->seed(ProductCategorySeeder::class);
    $this->seed(ProductCatalogSeeder::class);
});

it('PCS-01: seeds the new products with category, distributor price, BV and image', function (): void {
    $expected = [
        ['sku' => 'AV-IB-001', 'cat' => 'Health Care', 'dist' => 45000, 'bv' => 30000],
        ['sku' => 'AV-VCS-001', 'cat' => 'Skin and Beauty', 'dist' => 62000, 'bv' => 40000],
        ['sku' => 'AV-AFW-001', 'cat' => 'Personal Care', 'dist' => 25000, 'bv' => 18000],
    ];

    foreach ($expected as $e) {
        $p = Product::where('sku', $e['sku'])->with('productCategory', 'variants')->first();
        expect($p)->not->toBeNull()
            ->and($p->status)->toBe(Product::STATUS_ACTIVE)
            ->and($p->productCategory?->name)->toBe($e['cat'])
            ->and($p->image_url)->toStartWith('https://')
            ->and($p->variants->first()->distributor_price_paise)->toBe($e['dist'])
            ->and($p->variants->first()->bv_paise)->toBe($e['bv']);
    }
});

it('PCS-02: Multi-Vitamin gets a distributor price + sorted rich attributes (incl. a table)', function (): void {
    $mv = Product::where('sku', 'AV-MV-001')->with('productAttributes', 'variants')->first();

    expect($mv->variants->first()->distributor_price_paise)->toBe(65000);

    $attrs = $mv->productAttributes;
    expect($attrs)->toHaveCount(2)
        ->and($attrs[0]->label)->toBe('Directions for use')      // sort 1 first
        ->and($attrs[1]->label)->toBe('Nutritional information (per tablet)')
        ->and($attrs[1]->value_html)->toContain('<table>')
        ->and($attrs[1]->value_html)->toContain('Vitamin C');
});

it('PCS-03: re-running the seeder is idempotent (no duplicate products or attributes)', function (): void {
    $this->seed(ProductCatalogSeeder::class); // second run

    expect(Product::count())->toBe(8);
    expect(Product::where('sku', 'AV-MV-001')->first()->productAttributes()->count())->toBe(2);
});
