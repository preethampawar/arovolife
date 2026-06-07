<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $id = $this->route('category')?->id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9-]+$/', Rule::unique('product_categories', 'slug')->ignore($id)],
            'parent_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where(fn ($q) => $id ? $q->where('id', '!=', $id) : $q)],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in([ProductCategory::STATUS_ACTIVE, ProductCategory::STATUS_ARCHIVED])],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],
            'banner' => ['nullable', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],
            'banner_external_url' => ['nullable', 'url', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug must be lowercase letters, numbers and hyphens only.',
        ];
    }
}
