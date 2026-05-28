<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Catalog\Models\Product;
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

        $products = Product::with(['variants' => fn ($q) => $q->where('status', 'active')])
            ->where('status', Product::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();

        // Canonical storefront categories — always offered as filters even
        // before any product is tagged with them. Stored as slugs to match
        // the product `category` convention (e.g. "personal-care"), so a
        // canonical entry and a product already tagged with it dedupe to one
        // pill and the filter actually matches. The pill label is derived for
        // display via ucwords(str_replace('-',' ', …)) in the view. Any extra
        // categories existing products use are appended after these.
        $canonicalCategories = collect([
            'health-care',
            'skin-and-beauty',
            'personal-care',
            'home-care',
            'agri-care',
            'lifestyle',
        ]);

        $categories = $canonicalCategories
            ->merge($products->pluck('category')->filter())
            ->unique()
            ->values();

        return view('shop.index', [
            'products' => $products,
            'categories' => $categories,
            'cart' => $this->cartService->currentCart($request),
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $this->ensureStorefrontEnabled();

        $product = Product::with(['variants' => fn ($q) => $q->where('status', 'active')])
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
