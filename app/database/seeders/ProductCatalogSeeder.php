<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Seeder;

final class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Demo imagery: royalty-free Unsplash / Picsum stock photos chosen to
        // show generic, unbranded products. Real product photography replaces
        // these when the catalogue goes live. Prices/BV in paise; gst in bp.
        //
        // `category_slug` maps to a ProductCategory (seeded by
        // ProductCategorySeeder, which runs first — see DatabaseSeeder).
        // `dist` is the after-login distributor price tier (0 = not offered).
        // `attributes` are the sorted rich product-information sections.
        $products = [
            [
                'sku' => 'AV-HW-001', 'slug' => 'gentle-hand-wash', 'category_slug' => 'personal-care',
                'name' => 'arovolife Gentle Hand Wash',
                'short_description' => 'Aloe vera & neem. 250 ml pump bottle.',
                'description' => 'A daily-use hand wash with aloe vera and neem extract. Paraben-free. Safe for sensitive skin.',
                'hsn_code' => '3401',
                'image_url' => 'https://images.unsplash.com/photo-1584305574647-0cc949a2bb9f?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 29500, 'sale' => 24500, 'cost' => 8000, 'dist' => 0, 'bv' => 15000, 'pv' => 15000, 'gst_bp' => 1800,
            ],
            [
                'sku' => 'AV-SH-001', 'slug' => 'scalpcare-shampoo', 'category_slug' => 'personal-care',
                'name' => 'arovolife ScalpCare Shampoo',
                'short_description' => 'Anti-dandruff formula. 300 ml.',
                'description' => 'Clinically tested anti-dandruff shampoo with tea tree and piroctone olamine. Sulphate-free.',
                'hsn_code' => '3305',
                'image_url' => 'https://images.unsplash.com/photo-1556228720-195a672e8a03?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 135000, 'sale' => 115000, 'cost' => 38000, 'dist' => 0, 'bv' => 80000, 'pv' => 80000, 'gst_bp' => 1800,
            ],
            [
                'sku' => 'AV-MV-001', 'slug' => 'multi-vitamin', 'category_slug' => 'health-care',
                'name' => 'arovolife Multi-Vitamin',
                'short_description' => '30 tablets. Daily essentials.',
                'description' => 'Daily multivitamin with 12 vitamins and 9 minerals. Consult a physician before use.',
                'hsn_code' => '2936',
                'image_url' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 89900, 'sale' => 79900, 'cost' => 22000, 'dist' => 65000, 'bv' => 50000, 'pv' => 50000, 'gst_bp' => 1200,
                'attributes' => [
                    ['label' => 'Directions for use', 'sort' => 1, 'value_html' => '<p>Take 1 tablet daily after breakfast, or as directed by your healthcare professional. Do not exceed the recommended dose.</p>'],
                    ['label' => 'Nutritional information (per tablet)', 'sort' => 2, 'value_html' => '<table><thead><tr><th>Nutrient</th><th>Amount</th><th>% RDA</th></tr></thead><tbody><tr><td>Vitamin C</td><td>40 mg</td><td>100%</td></tr><tr><td>Vitamin D3</td><td>10 mcg</td><td>50%</td></tr><tr><td>Zinc</td><td>10 mg</td><td>91%</td></tr><tr><td>Iron</td><td>14 mg</td><td>74%</td></tr></tbody></table>'],
                ],
            ],
            [
                'sku' => 'AV-OL-001', 'slug' => 'hair-essential-oil', 'category_slug' => 'personal-care',
                'name' => 'arovolife Hair Essential Oil',
                'short_description' => 'Bhringraj & amla. 200 ml.',
                'description' => 'Traditional hair oil with bhringraj, amla and coconut base. For strength and shine.',
                'hsn_code' => '3305',
                'image_url' => 'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 115000, 'sale' => 99900, 'cost' => 28000, 'dist' => 0, 'bv' => 70000, 'pv' => 70000, 'gst_bp' => 1800,
            ],
            [
                'sku' => 'AV-FD-001', 'slug' => 'herbal-green-tea', 'category_slug' => 'health-care',
                'name' => 'arovolife Herbal Green Tea',
                'short_description' => '25 bags. Tulsi & ashwagandha.',
                'description' => 'Daily wellness tea blend with tulsi, ashwagandha and green tea leaves. Caffeine-lite.',
                'hsn_code' => '0902',
                'image_url' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 45000, 'sale' => 39900, 'cost' => 12000, 'dist' => 0, 'bv' => 25000, 'pv' => 25000, 'gst_bp' => 500,
            ],
            // Added 2026-06-01 (created on staging via the catalog admin, captured here).
            [
                'sku' => 'AV-IB-001', 'slug' => 'immunity-booster', 'category_slug' => 'health-care',
                'name' => 'arovolife Immunity Booster',
                'short_description' => '60 capsules. Zinc, C & elderberry.',
                'description' => 'Daily immunity support with vitamin C, zinc and elderberry extract.',
                'hsn_code' => '3004',
                'image_url' => 'https://picsum.photos/seed/immunity/800/800',
                'mrp' => 65000, 'sale' => 54900, 'cost' => 18000, 'dist' => 45000, 'bv' => 30000, 'pv' => 30000, 'gst_bp' => 1800,
            ],
            [
                'sku' => 'AV-VCS-001', 'slug' => 'vitamin-c-serum', 'category_slug' => 'skin-and-beauty',
                'name' => 'arovolife Vitamin C Serum',
                'short_description' => '30 ml. Brightening day serum.',
                'description' => 'A lightweight vitamin C face serum for daily brightening and even tone.',
                'hsn_code' => '3304',
                'image_url' => 'https://picsum.photos/seed/vitaminc/800/800',
                'mrp' => 89900, 'sale' => 74900, 'cost' => 24000, 'dist' => 62000, 'bv' => 40000, 'pv' => 40000, 'gst_bp' => 1800,
            ],
            [
                'sku' => 'AV-AFW-001', 'slug' => 'aloe-face-wash', 'category_slug' => 'personal-care',
                'name' => 'arovolife Aloe Face Wash',
                'short_description' => '150 ml. Gentle daily cleanser.',
                'description' => 'A gentle aloe vera face wash for daily use. Soap-free and pH-balanced.',
                'hsn_code' => '3401',
                'image_url' => 'https://picsum.photos/seed/aloe/800/800',
                'mrp' => 35000, 'sale' => 29900, 'cost' => 9000, 'dist' => 25000, 'bv' => 18000, 'pv' => 18000, 'gst_bp' => 1800,
            ],
        ];

        // slug → id map for the FK; categories are seeded before this seeder.
        $categoryIds = ProductCategory::query()->pluck('id', 'slug');

        foreach ($products as $data) {
            $product = Product::updateOrCreate(
                ['sku' => $data['sku']],
                [
                    'slug' => $data['slug'],
                    'name' => $data['name'],
                    'short_description' => $data['short_description'],
                    'description' => $data['description'],
                    'category' => $data['category_slug'],
                    'category_id' => $categoryIds[$data['category_slug']] ?? null,
                    'hsn_code' => $data['hsn_code'],
                    'image_url' => $data['image_url'] ?? null,
                    'status' => Product::STATUS_ACTIVE,
                ],
            );

            $variant = ProductVariant::updateOrCreate(
                ['variant_sku' => $data['sku'].'-V1'],
                [
                    'product_id' => $product->id,
                    'name' => 'Default',
                    'mrp_paise' => $data['mrp'],
                    'sale_price_paise' => $data['sale'],
                    'cost_paise' => $data['cost'],
                    'distributor_price_paise' => $data['dist'] ?? 0,
                    'bv_paise' => $data['bv'],
                    'pv_paise' => $data['pv'],
                    'gst_rate_bp' => $data['gst_bp'],
                    'inventory_policy' => 'track',
                    'status' => 'active',
                ],
            );

            InventoryLevel::updateOrCreate(
                ['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT'],
                ['on_hand' => 500, 'reserved' => 0],
            );

            // Rich product-information sections. Replace-in-place so re-seeding
            // stays idempotent (mirrors AdminProductController::syncAttributes).
            if (! empty($data['attributes'])) {
                $product->productAttributes()->delete();
                foreach ($data['attributes'] as $attr) {
                    ProductAttribute::create([
                        'product_id' => $product->id,
                        'label' => $attr['label'],
                        'value_html' => $attr['value_html'],
                        'sort' => $attr['sort'],
                    ]);
                }
            }
        }

        $this->command->info('Seeded '.count($products).' products.');
    }
}
