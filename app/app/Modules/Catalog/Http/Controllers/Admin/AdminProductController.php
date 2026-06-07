<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Http\Requests\ProductRequest;
use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductImageStorage;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Mews\Purifier\Facades\Purifier;

final class AdminProductController extends Controller
{
    public function __construct(private readonly ProductImageStorage $images) {}

    public function index(): View
    {
        $products = Product::query()
            ->with(['productCategory', 'variants' => fn ($q) => $q->orderBy('id')])
            ->orderByDesc('id')
            ->paginate(25);

        return view('admin.catalog.products.index', ['products' => $products]);
    }

    public function create(): View
    {
        return view('admin.catalog.products.form', [
            'product' => new Product(['status' => Product::STATUS_DRAFT, 'country_of_origin' => 'India']),
            'variant' => new ProductVariant(['gst_rate_bp' => 1800, 'inventory_policy' => 'track']),
            'categories' => $this->categoryOptions(),
            'galleryImages' => collect(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $product = DB::transaction(function () use ($data, $request): Product {
            $product = Product::create([
                'sku' => $data['sku'],
                'slug' => $data['slug'],
                'name' => $data['name'],
                'short_description' => $data['short_description'] ?? null,
                'description_html' => $this->purify($data['description_html'] ?? ''),
                'category_id' => $data['category_id'] ?? null,
                'manufacturer' => $data['manufacturer'] ?? null,
                'country_of_origin' => $data['country_of_origin'] ?? null,
                'food_type' => $data['food_type'] ?? null,
                'hsn_code' => $data['hsn_code'],
                'image_url' => $data['image_url'] ?? null,
                'status' => $data['status'],
                'created_by_user_id' => Auth::id(),
            ]);

            $this->syncDefaultVariant($product, $data);
            $this->syncAttributes($product, $data);
            $this->storeGalleryImages($product, $request);
            $this->storeGalleryImageUrls($product, $data);

            return $product;
        });

        $this->audit('catalog.product.created', $product);

        return redirect()
            ->route('admin.catalog.products.edit', $product)
            ->with('status', "Product \"{$product->name}\" created.");
    }

    public function edit(Product $product): View
    {
        $product->load('galleryImages', 'productAttributes');

        return view('admin.catalog.products.form', [
            'product' => $product,
            'variant' => $product->primaryVariant() ?? new ProductVariant(['gst_rate_bp' => 1800, 'inventory_policy' => 'track']),
            'categories' => $this->categoryOptions(),
            'galleryImages' => $product->galleryImages,
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($product, $data, $request): void {
            $product->update([
                'sku' => $data['sku'],
                'slug' => $data['slug'],
                'name' => $data['name'],
                'short_description' => $data['short_description'] ?? null,
                'description_html' => $this->purify($data['description_html'] ?? ''),
                'category_id' => $data['category_id'] ?? null,
                'manufacturer' => $data['manufacturer'] ?? null,
                'country_of_origin' => $data['country_of_origin'] ?? null,
                'food_type' => $data['food_type'] ?? null,
                'hsn_code' => $data['hsn_code'],
                'image_url' => $data['image_url'] ?? null,
                'status' => $data['status'],
            ]);

            $this->syncDefaultVariant($product, $data);
            $this->syncAttributes($product, $data);
            $this->storeGalleryImages($product, $request);
            $this->storeGalleryImageUrls($product, $data);
        });

        $this->audit('catalog.product.updated', $product);

        return redirect()
            ->route('admin.catalog.products.edit', $product)
            ->with('status', 'Product saved.');
    }

    public function archive(Product $product): RedirectResponse
    {
        $product->update(['status' => Product::STATUS_ARCHIVED]);
        $this->audit('catalog.product.archived', $product);

        return redirect()
            ->route('admin.catalog.products.index')
            ->with('status', "Product \"{$product->name}\" archived.");
    }

    public function deleteImage(ProductImage $image): RedirectResponse
    {
        $productId = $image->product_id;
        $this->images->delete($image);

        return back()->with('status', 'Image removed.');
    }

    /**
     * Trix attachment upload target. Stores the inline image on S3 and returns
     * its public URL for the editor to embed.
     */
    public function trixUpload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],
        ]);

        $image = $this->images->store($request->file('file'), ProductImage::KIND_INLINE);

        return response()->json(['url' => $image->url(), 'key' => $image->s3_key]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Create or update the product's single default variant (Epic-1 MVP:
     * one variant per product) from the rupee/percent form inputs, and
     * reconcile its DEFAULT-warehouse inventory level.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncDefaultVariant(Product $product, array $data): void
    {
        $variant = $product->primaryVariant() ?? new ProductVariant(['product_id' => $product->id]);

        $variant->fill([
            'product_id' => $product->id,
            'variant_sku' => $variant->variant_sku ?? ($data['sku'].'-V1'),
            'name' => 'Default',
            // Variant-level attributes JSON is preserved as-is; descriptive
            // product content now lives in the product_attributes table.
            'attributes' => $variant->attributes ?? [],
            'weight_g' => (int) ($data['weight_g'] ?? 0),
            'mrp_paise' => $this->toPaise($data['mrp']),
            'sale_price_paise' => $this->toPaise($data['sale_price']),
            'cost_paise' => $this->toPaise($data['cost_price'] ?? 0),
            'landing_price_paise' => $this->toPaise($data['landing_price'] ?? 0),
            'distributor_price_paise' => $this->toPaise($data['distributor_price'] ?? 0),
            'bv_paise' => $this->toPaise($data['bv'] ?? 0),
            'gst_rate_bp' => (int) round(((float) $data['gst_rate']) * 100),
            'inventory_policy' => $data['inventory_policy'],
            'status' => 'active',
        ]);
        $variant->save();

        InventoryLevel::updateOrCreate(
            ['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT'],
            ['on_hand' => (int) ($data['on_hand'] ?? 0)],
        );
    }

    private function storeGalleryImages(Product $product, Request $request): void
    {
        foreach ((array) $request->file('images', []) as $file) {
            if ($file !== null) {
                $this->images->store($file, ProductImage::KIND_GALLERY, $product->id);
            }
        }
    }

    /**
     * Attach externally-hosted (CDN) gallery images from the "image URLs"
     * textarea. ProductRequest has already split the textarea into a clean
     * array of validated, non-blank URLs; each becomes a URL-only
     * ProductImage row (no S3 upload).
     *
     * @param  array<string, mixed>  $data
     */
    private function storeGalleryImageUrls(Product $product, array $data): void
    {
        foreach ($data['gallery_image_urls'] ?? [] as $url) {
            $this->images->storeUrl((string) $url, ProductImage::KIND_GALLERY, $product->id);
        }
    }

    /**
     * Replace the product's descriptive attribute rows from the sortable
     * repeater. Each value is sanitised WYSIWYG HTML (tables / inline images
     * allowed via the 'products' purifier profile). Rows with a blank label
     * AND a blank value are dropped; the remaining rows are renumbered by the
     * submitted sort, falling back to submission order.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncAttributes(Product $product, array $data): void
    {
        $labels = $data['attr_labels'] ?? [];
        $values = $data['attr_values_html'] ?? [];
        $sorts = $data['attr_sort'] ?? [];

        $product->productAttributes()->delete();

        foreach (array_keys($labels) as $i) {
            $label = trim((string) ($labels[$i] ?? ''));
            $valueHtml = $this->purify(trim((string) ($values[$i] ?? '')));

            // Skip fully-blank rows (purify reduces e.g. "<p></p>" → "").
            if ($label === '' && $valueHtml === '') {
                continue;
            }

            ProductAttribute::create([
                'product_id' => $product->id,
                'label' => $label,
                'value_html' => $valueHtml,
                'sort' => isset($sorts[$i]) && $sorts[$i] !== '' ? (int) $sorts[$i] : $i,
            ]);
        }
    }

    private function toPaise(mixed $rupees): int
    {
        return (int) round(((float) $rupees) * 100);
    }

    private function purify(string $dirty): string
    {
        return $dirty === '' ? '' : Purifier::clean($dirty, 'products');
    }

    /**
     * @return Collection<int, ProductCategory>
     */
    private function categoryOptions()
    {
        return ProductCategory::query()
            ->where('status', ProductCategory::STATUS_ACTIVE)
            ->orderBy('sort')
            ->get();
    }

    private function audit(string $action, Product $product): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'details' => [
                'sku' => $product->sku,
                'name' => $product->name,
                'status' => $product->status,
            ],
        ]);
    }
}
