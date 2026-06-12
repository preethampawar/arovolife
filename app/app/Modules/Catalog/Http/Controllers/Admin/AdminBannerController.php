<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Services\ProductImageStorage;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Admin management of storefront shopping-mall carousel banners. Each banner's
 * image is EITHER uploaded to S3 OR an external URL (the upload wins if both are
 * given). Recommended size 1520x350 — surfaced as a note on the form.
 */
final class AdminBannerController extends Controller
{
    public function __construct(private readonly ProductImageStorage $images) {}

    public function index(): View
    {
        return view('admin.catalog.banners.index', [
            'banners' => Banner::query()->with('category')->orderBy('sort')->orderByDesc('id')->paginate(50),
        ]);
    }

    public function create(): View
    {
        return view('admin.catalog.banners.form', [
            'banner' => new Banner(['status' => Banner::STATUS_ACTIVE, 'sort' => 0]),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $banner = new Banner([
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'] ?? null,
            'caption' => $data['caption'] ?? null,
            'link_url' => $data['link_url'] ?? null,
            'external_url' => $data['external_url'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => $data['status'],
        ]);

        if ($request->hasFile('image')) {
            $banner->s3_key = $this->images->putRaw($request->file('image'), 'banners');
            $banner->external_url = null; // an uploaded file takes precedence
        }

        $banner->save();
        $this->audit('catalog.banner.created', $banner);

        return redirect()->route('admin.catalog.banners.index')->with('status', 'Banner created.');
    }

    public function edit(Banner $banner): View
    {
        return view('admin.catalog.banners.form', [
            'banner' => $banner,
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function update(Request $request, Banner $banner): RedirectResponse
    {
        $data = $this->validated($request);

        $banner->fill([
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'] ?? null,
            'caption' => $data['caption'] ?? null,
            'link_url' => $data['link_url'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => $data['status'],
        ]);

        if ($request->hasFile('image')) {
            $old = $banner->s3_key;
            $banner->s3_key = $this->images->putRaw($request->file('image'), 'banners');
            $banner->external_url = null;
            $this->images->deleteKey($old);
        } elseif (array_key_exists('external_url', $data)) {
            // Switching to / clearing an external URL (only when no upload).
            $banner->external_url = $data['external_url'] ?? null;
            if ($banner->external_url !== null && $banner->s3_key !== null) {
                $this->images->deleteKey($banner->s3_key);
                $banner->s3_key = null;
            }
        }

        $banner->save();
        $this->audit('catalog.banner.updated', $banner);

        return redirect()->route('admin.catalog.banners.edit', $banner)->with('status', 'Banner saved.');
    }

    public function destroy(Banner $banner): RedirectResponse
    {
        $this->images->deleteKey($banner->s3_key);
        $banner->delete();
        $this->audit('catalog.banner.deleted', $banner);

        return redirect()->route('admin.catalog.banners.index')->with('status', 'Banner removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            // null = shopping-mall (home) banner; a category id assigns it there.
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')],
            'title' => ['nullable', 'string', 'max:150'],
            'caption' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'url', 'max:500'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],
            'external_url' => ['nullable', 'url', 'max:500'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => ['required', 'in:active,archived'],
        ]);
    }

    /** @return Collection<int, ProductCategory> */
    private function categoryOptions(): Collection
    {
        return ProductCategory::query()
            ->where('status', 'active')
            ->orderBy('sort')
            ->get(['id', 'name']);
    }

    private function audit(string $action, Banner $banner): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => 'banner',
            'subject_id' => $banner->id,
            'details' => ['title' => $banner->title, 'status' => $banner->status],
        ]);
    }
}
