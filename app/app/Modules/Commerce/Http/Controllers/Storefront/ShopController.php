<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Commerce\Services\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShopController extends Controller
{
    public function __construct(private readonly CartService $cartService) {}

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

        return view('shop.index', [
            'products' => $products,
            'categories' => $categories,
            'activeSlug' => $activeSlug,
            'cart' => $this->cartService->currentCart($request),
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

        return view('shop.product', [
            'product' => $product,
            'cart' => $this->cartService->currentCart($request),
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
