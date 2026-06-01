<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use Illuminate\Database\Seeder;

/**
 * Seeds the Atomy-style category master with the six homepage categories
 * (see the `product_categories` project memory — Agri Care is a first-class
 * agriculture category that replaced Hair Care), then backfills the
 * `products.category_id` FK from the legacy `products.category` string.
 *
 * Strictly additive / idempotent (updateOrCreate by slug).
 */
final class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'health-care', 'name' => 'Health Care', 'sort' => 1, 'description' => 'Daily supplements, immunity blends, and Ayurveda-inspired wellness.'],
            ['slug' => 'skin-and-beauty', 'name' => 'Skin and Beauty', 'sort' => 2, 'description' => 'Cleansers, serums and treatments formulated for Indian skin.'],
            ['slug' => 'personal-care', 'name' => 'Personal Care', 'sort' => 3, 'description' => 'Everyday essentials with clean ingredient lists.'],
            ['slug' => 'home-care', 'name' => 'Home Care', 'sort' => 4, 'description' => 'Plant-based dishwash, laundry and surface cleaners.'],
            ['slug' => 'agri-care', 'name' => 'Agri Care', 'sort' => 5, 'description' => 'Organic fertilisers, bio-pesticides and soil conditioners.'],
            ['slug' => 'lifestyle', 'name' => 'Lifestyle', 'sort' => 6, 'description' => 'Curated wellness bundles and lifestyle accessories.'],
        ];

        $bySlug = [];
        foreach ($categories as $cat) {
            $bySlug[$cat['slug']] = ProductCategory::updateOrCreate(
                ['slug' => $cat['slug']],
                [
                    'name' => $cat['name'],
                    'description' => $cat['description'],
                    'sort' => $cat['sort'],
                    'status' => ProductCategory::STATUS_ACTIVE,
                ],
            );
        }

        // Backfill the FK from the legacy string category on existing products.
        $legacyMap = [
            'personal-care' => 'personal-care',
            'health' => 'health-care',
            'food' => 'health-care',
            'home-care' => 'home-care',
            'agri-care' => 'agri-care',
            'lifestyle' => 'lifestyle',
            'skin-and-beauty' => 'skin-and-beauty',
        ];

        foreach (Product::query()->whereNull('category_id')->whereNotNull('category')->get() as $product) {
            $slug = $legacyMap[$product->category] ?? null;
            if ($slug !== null && isset($bySlug[$slug])) {
                $product->update(['category_id' => $bySlug[$slug]->id]);
            }
        }

        $this->command->info('Seeded '.count($categories).' product categories and backfilled product category FKs.');
    }
}
