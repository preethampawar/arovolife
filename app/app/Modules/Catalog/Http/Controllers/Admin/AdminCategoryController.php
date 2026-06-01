<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Http\Requests\CategoryRequest;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Services\ProductImageStorage;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

final class AdminCategoryController extends Controller
{
    public function __construct(private readonly ProductImageStorage $images) {}

    public function index(): View
    {
        $categories = ProductCategory::query()
            ->with('parent')
            ->withCount('products')
            ->orderBy('sort')
            ->paginate(50);

        return view('admin.catalog.categories.index', ['categories' => $categories]);
    }

    public function create(): View
    {
        return view('admin.catalog.categories.form', [
            'category' => new ProductCategory(['status' => ProductCategory::STATUS_ACTIVE, 'sort' => 0]),
            'parents' => $this->parentOptions(null),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $category = new ProductCategory([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => $data['status'],
        ]);

        if ($request->hasFile('image')) {
            $category->image_s3_key = $this->images->putRaw($request->file('image'), 'categories');
        }

        $category->save();
        $this->audit('catalog.category.created', $category);

        return redirect()
            ->route('admin.catalog.categories.index')
            ->with('status', "Category \"{$category->name}\" created.");
    }

    public function edit(ProductCategory $category): View
    {
        return view('admin.catalog.categories.form', [
            'category' => $category,
            'parents' => $this->parentOptions($category->id),
        ]);
    }

    public function update(CategoryRequest $request, ProductCategory $category): RedirectResponse
    {
        $data = $request->validated();

        $category->fill([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => $data['status'],
        ]);

        if ($request->hasFile('image')) {
            $old = $category->image_s3_key;
            $category->image_s3_key = $this->images->putRaw($request->file('image'), 'categories');
            $this->images->deleteKey($old);
        }

        $category->save();
        $this->audit('catalog.category.updated', $category);

        return redirect()
            ->route('admin.catalog.categories.edit', $category)
            ->with('status', 'Category saved.');
    }

    public function archive(ProductCategory $category): RedirectResponse
    {
        $category->update(['status' => ProductCategory::STATUS_ARCHIVED]);
        $this->audit('catalog.category.archived', $category);

        return redirect()
            ->route('admin.catalog.categories.index')
            ->with('status', "Category \"{$category->name}\" archived.");
    }

    /**
     * Active categories eligible as a parent, excluding the category being
     * edited (a category can't be its own parent).
     *
     * @return \Illuminate\Support\Collection<int, ProductCategory>
     */
    private function parentOptions(?int $excludeId)
    {
        return ProductCategory::query()
            ->where('status', ProductCategory::STATUS_ACTIVE)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderBy('sort')
            ->get();
    }

    private function audit(string $action, ProductCategory $category): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => 'product_category',
            'subject_id' => $category->id,
            'details' => ['slug' => $category->slug, 'name' => $category->name, 'status' => $category->status],
        ]);
    }
}
