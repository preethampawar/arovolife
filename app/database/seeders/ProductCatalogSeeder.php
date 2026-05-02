<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Seeder;

final class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Phase 1 demo imagery: royalty-free Unsplash stock photos chosen to
        // show generic, unbranded products (no third-party logo on labels,
        // no recognisable competitor packaging). Real product photography
        // replaces these when the catalogue goes live in a later phase.
        $products = [
            [
                'sku' => 'AV-HW-001', 'slug' => 'gentle-hand-wash', 'category' => 'personal-care',
                'name' => 'arovolife Gentle Hand Wash',
                'short_description' => 'Aloe vera & neem. 250 ml pump bottle.',
                'description' => 'A daily-use hand wash with aloe vera and neem extract. Paraben-free. Safe for sensitive skin.',
                'hsn_code' => '3401',
                'image_url' => 'https://images.unsplash.com/photo-1584305574647-0cc949a2bb9f?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 29500, 'sale' => 24500, 'cost' => 8000, 'bv' => 15000, 'pv' => 15000, 'gst_bp' => 1800,
            ],
            [
                'sku' => 'AV-SH-001', 'slug' => 'scalpcare-shampoo', 'category' => 'personal-care',
                'name' => 'arovolife ScalpCare Shampoo',
                'short_description' => 'Anti-dandruff formula. 300 ml.',
                'description' => 'Clinically tested anti-dandruff shampoo with tea tree and piroctone olamine. Sulphate-free.',
                'hsn_code' => '3305',
                'image_url' => 'https://images.unsplash.com/photo-1556228720-195a672e8a03?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 135000, 'sale' => 115000, 'cost' => 38000, 'bv' => 80000, 'pv' => 80000, 'gst_bp' => 1800,
            ],
            [
                'sku' => 'AV-MV-001', 'slug' => 'multi-vitamin', 'category' => 'health',
                'name' => 'arovolife Multi-Vitamin',
                'short_description' => '30 tablets. Daily essentials.',
                'description' => 'Daily multivitamin with 12 vitamins and 9 minerals. Consult a physician before use.',
                'hsn_code' => '2936',
                'image_url' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 89900, 'sale' => 79900, 'cost' => 22000, 'bv' => 50000, 'pv' => 50000, 'gst_bp' => 1200,
            ],
            [
                'sku' => 'AV-OL-001', 'slug' => 'hair-essential-oil', 'category' => 'personal-care',
                'name' => 'arovolife Hair Essential Oil',
                'short_description' => 'Bhringraj & amla. 200 ml.',
                'description' => 'Traditional hair oil with bhringraj, amla and coconut base. For strength and shine.',
                'hsn_code' => '3305',
                'image_url' => 'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 115000, 'sale' => 99900, 'cost' => 28000, 'bv' => 70000, 'pv' => 70000, 'gst_bp' => 1800,
            ],
            [
                'sku' => 'AV-FD-001', 'slug' => 'herbal-green-tea', 'category' => 'food',
                'name' => 'arovolife Herbal Green Tea',
                'short_description' => '25 bags. Tulsi & ashwagandha.',
                'description' => 'Daily wellness tea blend with tulsi, ashwagandha and green tea leaves. Caffeine-lite.',
                'hsn_code' => '0902',
                'image_url' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 45000, 'sale' => 39900, 'cost' => 12000, 'bv' => 25000, 'pv' => 25000, 'gst_bp' => 500,
            ],
        ];

        foreach ($products as $data) {
            $product = Product::updateOrCreate(
                ['sku' => $data['sku']],
                [
                    'slug' => $data['slug'],
                    'name' => $data['name'],
                    'short_description' => $data['short_description'],
                    'description' => $data['description'],
                    'category' => $data['category'],
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
        }

        $this->command->info('Seeded '.count($products).' products.');
    }
}
