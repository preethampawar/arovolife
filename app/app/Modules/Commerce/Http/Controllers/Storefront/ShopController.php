<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\ShippingService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShopController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly ShippingService $shipping,
        private readonly StockLedger $stock,
    ) {}

    public function index(Request $request): View
    {
        $this->ensureStorefrontEnabled();

        // Storefront category nav is driven by the category master
        // (Atomy-style) — active categories in admin sort order.
        $categories = ProductCategory::query()
            ->where('status', ProductCategory::STATUS_ACTIVE)
            ->orderBy('sort')
            ->get();

        // Optional ?category=<slug> filter. Match on the FK (category_id) and
        // fall back to the legacy `category` string so products tagged either
        // way are found.
        $activeSlug = $request->query('category');
        $activeCategory = $activeSlug !== null ? $categories->firstWhere('slug', $activeSlug) : null;

        $products = Product::query()
            ->with([
                'variants' => fn ($q) => $q->where('status', 'active')->orderBy('id'),
                'galleryImages',
                'productCategory',
            ])
            ->where('status', Product::STATUS_ACTIVE)
            ->when($activeSlug !== null, function ($q) use ($activeSlug, $activeCategory): void {
                $q->where(function ($w) use ($activeSlug, $activeCategory): void {
                    if ($activeCategory !== null) {
                        $w->where('category_id', $activeCategory->id);
                    }
                    $w->orWhere('category', $activeSlug);
                });
            })
            ->orderBy('name')
            ->get();

        // "All products" view (no category filter): present products grouped
        // into per-category sections, up to 5 each, with a "View all" link to
        // the full category page. A product belongs to a category by FK or the
        // legacy `category` slug (same matching as the ?category= filter).
        $productsByCategory = $activeCategory !== null
            ? collect()
            : $categories
                ->map(fn (ProductCategory $cat): array => [
                    'category' => $cat,
                    'products' => $products
                        ->filter(fn (Product $p): bool => $p->category_id === $cat->id || $p->category === $cat->slug)
                        ->take(5)
                        ->values(),
                ])
                ->filter(fn (array $group): bool => $group['products']->isNotEmpty())
                ->values();

        return view('shop.index', [
            'products' => $products,
            'productsByCategory' => $productsByCategory,
            'categories' => $categories,
            'activeSlug' => $activeSlug,
            'activeCategory' => $activeCategory,
            // Shopping-mall carousel (home/unfiltered shop).
            'banners' => Banner::query()->displayable()->mall()->get(),
            // Banners assigned to the active category — slide on its page.
            'categoryBanners' => $activeCategory !== null
                ? Banner::query()->displayable()->forCategory($activeCategory->id)->get()
                : collect(),
            'cart' => $this->cartService->currentCart($request),
            'freeShippingThresholdRupees' => intdiv($this->shipping->freeThresholdPaise(), 100),
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $this->ensureStorefrontEnabled();

        $product = Product::query()
            ->with([
                'variants' => fn ($q) => $q->where('status', 'active')->orderBy('id'),
                'galleryImages',
                'productCategory',
                'productAttributes',
            ])
            ->where('slug', $slug)
            ->where('status', Product::STATUS_ACTIVE)
            ->first();

        if ($product === null) {
            throw new NotFoundHttpException;
        }

        // Plan §7.3: a tracked variant with nothing available shows a plain
        // "Out of stock" and disables Add to Cart — no scarcity copy, no
        // quantity. Gated behind InventoryFeature so a module deploy does not
        // start hiding buy buttons before the ledger has real numbers.
        $outOfStock = [];

        if (Feature::for(null)->active(InventoryFeature::class)) {
            foreach ($product->variants as $variant) {
                if ($variant->inventory_policy === 'track' && $this->stock->available($variant->id) <= 0) {
                    $outOfStock[$variant->id] = true;
                }
            }
        }

        return view('shop.product', [
            'product' => $product,
            'cart' => $this->cartService->currentCart($request),
            'outOfStock' => $outOfStock,
        ]);
    }

    private function ensureStorefrontEnabled(): void
    {
        $enabled = DB::table('settings')->where('key', 'commerce.storefront.enabled')->value('value');
        if ($enabled !== 'true') {
            throw new NotFoundHttpException;
        }
    }
}
