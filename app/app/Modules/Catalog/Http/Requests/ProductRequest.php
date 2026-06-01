<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the admin product create/update form. Prices are entered in
 * RUPEES (decimal) and GST as a PERCENT for admin convenience; the
 * controller converts them to paise / basis-points before persisting.
 */
final class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $id = $this->route('product')?->id;

        return [
            // ── Product ──────────────────────────────────────────────
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($id)],
            'slug' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9-]+$/', Rule::unique('products', 'slug')->ignore($id)],
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')],
            'manufacturer' => ['nullable', 'string', 'max:200'],
            'country_of_origin' => ['nullable', 'string', 'max:64'],
            'hsn_code' => ['required', 'string', 'max:16'],
            // Hosted image URL — primary product image when no gallery image is
            // uploaded (the storefront falls back to it). Lets admins use a CDN/
            // hosted image without an S3 gallery upload.
            'image_url' => ['nullable', 'url', 'max:1000'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description_html' => ['nullable', 'string', 'max:100000'],
            'status' => ['required', Rule::in([Product::STATUS_DRAFT, Product::STATUS_ACTIVE, Product::STATUS_ARCHIVED])],

            // ── Default variant (single-variant MVP) ─────────────────
            'mrp' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'landing_price' => ['nullable', 'numeric', 'min:0'],
            'distributor_price' => ['nullable', 'numeric', 'min:0'],
            'bv' => ['nullable', 'numeric', 'min:0'],
            'pv' => ['nullable', 'numeric', 'min:0'],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'weight_g' => ['nullable', 'integer', 'min:0'],
            'inventory_policy' => ['required', Rule::in(['track', 'no_track'])],
            'on_hand' => ['nullable', 'integer', 'min:0'],

            // ── Product attributes (rich, sortable repeater) ─────────
            // Each row: a short label + a WYSIWYG value that may carry a
            // formatted table or an inline image (e.g. nutritional facts).
            // value_html is sanitised with the 'products' purifier profile
            // in the controller before persisting.
            'attr_labels' => ['nullable', 'array'],
            'attr_labels.*' => ['nullable', 'string', 'max:150'],
            'attr_values_html' => ['nullable', 'array'],
            'attr_values_html.*' => ['nullable', 'string', 'max:100000'],
            'attr_sort' => ['nullable', 'array'],
            'attr_sort.*' => ['nullable', 'integer', 'min:0', 'max:9999'],

            // ── Gallery images ───────────────────────────────────────
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpeg,jpg,png', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug must be lowercase letters, numbers and hyphens only.',
            'images.*.mimes' => 'Product images must be JPG or PNG.',
            'images.*.max' => 'Each product image must be 5 MB or smaller.',
        ];
    }
}
