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
            'food_type' => ['nullable', Rule::in([Product::FOOD_VEG, Product::FOOD_NON_VEG])],
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
            // Externally-hosted gallery images: a textarea of one URL per
            // line, normalised to an array in prepareForValidation().
            'gallery_image_urls' => ['nullable', 'array'],
            'gallery_image_urls.*' => ['url', 'max:1000'],
        ];
    }

    /**
     * The gallery image-URL field is a free-text textarea (one URL per line).
     * Normalise it into an array of trimmed, non-blank lines so the `.*` URL
     * rules apply per line and the controller receives a clean list.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('gallery_image_urls');

        if (is_string($raw)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            $urls = array_values(array_filter(
                array_map('trim', $lines),
                static fn (string $line): bool => $line !== '',
            ));
            $this->merge(['gallery_image_urls' => $urls]);
        }
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug must be lowercase letters, numbers and hyphens only.',
            'images.*.mimes' => 'Product images must be JPG or PNG.',
            'images.*.max' => 'Each product image must be 5 MB or smaller.',
            'gallery_image_urls.*.url' => 'Each gallery image URL must be a valid URL (one per line).',
        ];
    }
}
