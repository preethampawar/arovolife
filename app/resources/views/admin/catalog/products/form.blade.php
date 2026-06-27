@extends('admin.layouts.admin')
@section('title', $product->exists ? 'Edit product' : 'New product')
@section('heading', $product->exists ? 'Edit: '.$product->name : 'New product')

@push('styles')
<link rel="stylesheet" href="https://unpkg.com/trix@2.1.15/dist/trix.css">
<style>
    trix-editor { min-height: 14rem; background: #fff; border-radius: 0.5rem; border-color: #d1d5db; }
    trix-editor h1 { font-size: 1.25rem; font-weight: 700; }
    trix-toolbar .trix-button-group { border-color: #e5e7eb; }
</style>
@endpush

@section('content')
@php
    $paise = fn ($p) => $p ? number_format($p / 100, 2, '.', '') : '';
    $isEdit = $product->exists;
    $action = $isEdit ? route('admin.catalog.products.update', $product) : route('admin.catalog.products.store');
    // Leave number fields empty (not "0") on a NEW product so typing doesn't
    // produce a leading-zero value like "075" that fails integer validation.
    $onHand = old('on_hand', $isEdit ? ($variant->inventory?->on_hand ?? 0) : null);
@endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="max-w-4xl space-y-6">
    @csrf
    @if($isEdit) @method('PUT') @endif

    {{-- ── Basics ─────────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Product details</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Name <x-help-tip text="The customer-facing product name shown on the shop and product page." /></span>
                <input type="text" name="name" value="{{ old('name', $product->name) }}" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">SKU <x-help-tip text="Unique internal stock code for this product. Used in inventory and orders." /></span>
                <input type="text" name="sku" value="{{ old('sku', $product->sku) }}" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Slug <x-help-tip text="The product's URL segment on the shop. Use lowercase words joined by hyphens." /></span>
                <input type="text" name="slug" value="{{ old('slug', $product->slug) }}" required placeholder="lowercase-with-hyphens"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Category <x-help-tip text="The shop category this product is listed under. Leave blank for none." /></span>
                <select name="category_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">— none —</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" @selected((int) old('category_id', $product->category_id) === $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">HSN code <x-help-tip text="The GST HSN classification code for this product. Used on tax invoices." /></span>
                <input type="text" name="hsn_code" value="{{ old('hsn_code', $product->hsn_code) }}" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Manufacturer <x-help-tip text="Name of the company that makes this product. Shown on the product page." /></span>
                <input type="text" name="manufacturer" value="{{ old('manufacturer', $product->manufacturer) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Country of origin <x-help-tip text="The country where this product is manufactured. Shown on the product page." /></span>
                <input type="text" name="country_of_origin" value="{{ old('country_of_origin', $product->country_of_origin) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Veg / Non-veg <x-help-tip text="Sets the FSSAI veg/non-veg mark for food items. Use Not applicable for non-food." /></span>
                <select name="food_type" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    @foreach(['' => 'Not applicable (non-food)', 'veg' => 'Vegetarian', 'non_veg' => 'Non-vegetarian'] as $val => $lbl)
                        <option value="{{ $val }}" @selected((string) old('food_type', $product->food_type) === $val)>{{ $lbl }}</option>
                    @endforeach
                </select>
                <span class="block text-[11px] text-gray-500 mt-1">FSSAI mark shown on the storefront for food items. Leave as “Not applicable” for personal-care / agri products.</span>
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Status <x-help-tip text="Draft hides the product; Active shows it on the shop; Archived removes it from sale." /></span>
                <select name="status" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    @foreach(['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('status', $product->status) === $val)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Short description <x-help-tip text="A one-line summary shown in product listings and previews (up to 500 characters)." /></span>
                <input type="text" name="short_description" value="{{ old('short_description', $product->short_description) }}" maxlength="500"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Primary image URL <x-help-tip text="Optional hosted image URL shown when no gallery image is uploaded." /></span>
                <input type="url" name="image_url" value="{{ old('image_url', $product->image_url) }}" maxlength="1000" placeholder="https://… (used when no gallery image is uploaded)"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <span class="block text-xs text-gray-500 mt-1">Optional. A hosted/CDN image URL shown when no gallery image is uploaded.</span>
            </label>
        </div>
    </div>

    {{-- ── Pricing & BV (single default variant) ──────────────────────── --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Pricing &amp; BV <span class="text-gray-400 normal-case font-normal">(₹)</span></h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            @foreach([
                'cost_price' => ['Cost price', $variant->cost_paise ?? 0],
                'landing_price' => ['Landing price', $variant->landing_price_paise ?? 0],
                'distributor_price' => ['Distributor price', $variant->distributor_price_paise ?? 0],
                'mrp' => ['MRP', $variant->mrp_paise ?? 0],
                'sale_price' => ['Sale price', $variant->sale_price_paise ?? 0],
                'bv' => ['BV', $variant->bv_paise ?? 0],
            ] as $field => [$label, $val])
                <label class="block">
                    <span class="block text-xs text-gray-700 mb-1 font-medium">{{ $label }} <x-help-tip :text="[
                        'cost_price' => 'Your internal purchase cost for this product. Not shown to customers.',
                        'landing_price' => 'Landed cost including freight and duties. Not shown to customers.',
                        'distributor_price' => 'The price charged to distributors when they buy this product.',
                        'mrp' => 'Maximum retail price printed on the product. Used as the strike-through price.',
                        'sale_price' => 'The actual selling price charged at checkout.',
                        'bv' => 'Business Volume points attached to this product for the compensation engine.',
                    ][$field] ?? ''" /></span>
                    <input type="number" step="0.01" min="0" name="{{ $field }}"
                        value="{{ old($field, $paise($val)) }}"
                        @if(in_array($field, ['mrp', 'sale_price'])) required @endif
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
                </label>
            @endforeach
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">GST % <x-help-tip text="The GST rate applied to this product at checkout and on the tax invoice." /></span>
                <input type="number" step="0.01" min="0" max="100" name="gst_rate" required
                    value="{{ old('gst_rate', $paise($variant->gst_rate_bp ?? 1800)) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Weight (g) <x-help-tip text="Shipping weight in grams. Used to calculate delivery charges." /></span>
                <input type="number" step="1" min="0" name="weight_g" value="{{ old('weight_g', $isEdit ? $variant->weight_g : null) }}" placeholder="0"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Inventory <x-help-tip text="Track stock counts down on-hand per order; Don't track allows unlimited selling." /></span>
                <select name="inventory_policy" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="track" @selected(old('inventory_policy', $variant->inventory_policy ?? 'track') === 'track')>Track stock</option>
                    <option value="no_track" @selected(old('inventory_policy', $variant->inventory_policy ?? 'track') === 'no_track')>Don't track</option>
                </select>
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Stock on hand <x-help-tip text="Current quantity available to sell. Decreases as orders are placed." /></span>
                <input type="number" step="1" min="0" name="on_hand" value="{{ $onHand }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
        </div>
    </div>

    {{-- ── Product attributes (rich, sortable) ────────────────────────── --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-3">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Product information <x-help-tip text="Add labelled sections (e.g. Ingredients, Storage) shown on the product page. Sort sets the order, lowest first." /></h2>
        <p class="text-xs text-gray-500">
            Descriptive sections shown on the product detail page — e.g. Ingredients,
            Nutritional information, Storage, Caution. Each value supports tables and
            inline images (paste or insert a nutritional-facts table / image). Set
            <span class="font-medium">Sort</span> to control the display order (lowest first).
        </p>
        @php
            $attrRows = [];
            if (old('attr_labels') !== null) {
                foreach (old('attr_labels') as $i => $lbl) {
                    $attrRows[] = ['label' => $lbl, 'value_html' => old('attr_values_html')[$i] ?? '', 'sort' => old('attr_sort')[$i] ?? $i];
                }
            } elseif ($isEdit && $product->relationLoaded('productAttributes')) {
                foreach ($product->productAttributes as $a) {
                    $attrRows[] = ['label' => $a->label, 'value_html' => $a->value_html, 'sort' => $a->sort];
                }
            }
        @endphp
        <div id="attrRows" class="space-y-4">
            @foreach($attrRows as $row)
            <div class="attr-row rounded-lg border border-gray-200 p-3 space-y-2 bg-gray-50/50">
                <div class="flex gap-2 items-center">
                    <input type="number" name="attr_sort[]" value="{{ $row['sort'] }}" min="0" max="9999" placeholder="#" title="Display order"
                        class="w-16 rounded-lg border border-gray-300 px-2 py-2 text-sm font-mono text-center focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <input type="text" name="attr_labels[]" value="{{ $row['label'] }}" placeholder="Section label (e.g. Ingredients)" maxlength="150"
                        class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <button type="button" onclick="removeAttrRow(this)" class="px-3 py-2 rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-100" title="Remove section">×</button>
                </div>
                <textarea name="attr_values_html[]" class="attr-wysiwyg">{{ $row['value_html'] }}</textarea>
            </div>
            @endforeach
        </div>
        <button type="button" id="addAttrRow" class="text-sm text-brand-600 hover:text-brand-700 font-medium">+ Add section</button>

        {{-- Template for new rows (textarea is initialised on demand). --}}
        <template id="attrRowTemplate">
            <div class="attr-row rounded-lg border border-gray-200 p-3 space-y-2 bg-gray-50/50">
                <div class="flex gap-2 items-center">
                    <input type="number" name="attr_sort[]" value="" min="0" max="9999" placeholder="#" title="Display order"
                        class="w-16 rounded-lg border border-gray-300 px-2 py-2 text-sm font-mono text-center focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <input type="text" name="attr_labels[]" value="" placeholder="Section label (e.g. Ingredients)" maxlength="150"
                        class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <button type="button" onclick="removeAttrRow(this)" class="px-3 py-2 rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-100" title="Remove section">×</button>
                </div>
                <textarea name="attr_values_html[]" class="attr-wysiwyg"></textarea>
            </div>
        </template>
    </div>

    {{-- ── Images ─────────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Gallery images</h2>
        @if($galleryImages->isNotEmpty())
        <div class="flex flex-wrap gap-3">
            @foreach($galleryImages as $img)
            <div class="relative">
                <img src="{{ $img->url() }}" alt="{{ $img->alt }}" class="w-24 h-24 object-cover rounded-lg border border-gray-200">
                @if($img->external_url)
                <span class="absolute bottom-0 left-0 bg-black/60 text-white text-[9px] font-semibold px-1 rounded-tr rounded-bl-lg leading-tight">URL</span>
                @endif
                <button type="button" data-confirm-impact="Delete this image permanently?"
                    onclick="document.getElementById('delImg{{ $img->id }}').submit()"
                    class="absolute -top-2 -right-2 w-6 h-6 rounded-full bg-red-500 text-white text-xs leading-none">×</button>
            </div>
            @endforeach
        </div>
        @endif
        <input type="file" name="images[]" accept="image/jpeg,image/png" multiple
            class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-slate-900 file:text-white file:text-sm file:font-medium hover:file:bg-slate-800">
        <p class="text-xs text-gray-500">JPG or PNG, up to 5 MB each. Stored on S3. <x-help-tip text="Upload one or more gallery images shown on the product page. JPG or PNG, up to 5 MB each." /></p>

        <label class="block pt-2 border-t border-gray-100">
            <span class="block text-xs text-gray-700 mb-1 font-medium">…or add image URLs <x-help-tip text="Hosted image URLs, one per line, added to the gallery alongside any uploads." /></span>
            <textarea name="gallery_image_urls" rows="3" placeholder="One image URL per line — https://…"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">{{ is_array(old('gallery_image_urls')) ? implode("\n", old('gallery_image_urls')) : old('gallery_image_urls') }}</textarea>
            <span class="block text-xs text-gray-500 mt-1">Hosted/CDN image URLs, one per line. Added to the gallery alongside any uploads.</span>
        </label>
    </div>

    {{-- ── WYSIWYG description ─────────────────────────────────────────── --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-3">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Description <x-help-tip text="The full rich-text product description shown on the product detail page." /></h2>
        <input id="descInput" type="hidden" name="description_html" value="{{ old('description_html', $product->description_html) }}">
        <trix-editor input="descInput" class="trix-content"></trix-editor>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
            {{ $isEdit ? 'Save changes' : 'Create product' }}
        </button>
        <a href="{{ route('admin.catalog.products.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
        @if($isEdit)
        <form id="archiveForm" method="POST" action="{{ route('admin.catalog.products.archive', $product) }}" class="ml-auto"
            data-confirm-impact="Archive this product? It will be hidden from the storefront.">
            @csrf
            <button type="submit" class="text-sm text-red-600 hover:text-red-700 font-medium">Archive product</button>
        </form>
        @endif
    </div>
</form>

{{-- Hidden per-image delete forms (kept outside the main form to avoid nesting). --}}
@foreach($galleryImages as $img)
<form id="delImg{{ $img->id }}" method="POST" action="{{ route('admin.catalog.images.destroy', $img) }}" class="hidden">
    @csrf @method('DELETE')
</form>
@endforeach
@endsection

@push('scripts')
<script src="https://unpkg.com/trix@2.1.15/dist/trix.umd.min.js"></script>
{{-- TinyMCE (GPL, self-hosted via jsdelivr) for the rich, table/image-capable attribute values. --}}
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    const TRIX_UPLOAD_URL = @json(route('admin.catalog.trix-upload'));
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;

    // ── Shared S3 upload (used by both Trix and TinyMCE) ───────────────
    function uploadImageToS3(file) {
        const form = new FormData();
        form.append('file', file);
        return fetch(TRIX_UPLOAD_URL, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: form })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(d => d.url);
    }

    // ── TinyMCE config for attribute-value editors ─────────────────────
    const ATTR_TINYMCE_CONFIG = {
        license_key: 'gpl',
        menubar: false,
        height: 240,
        plugins: 'table image lists link code autoresize',
        toolbar: 'bold italic | bullist numlist | table | image link | removeformat | code',
        table_default_attributes: { border: '1' },
        branding: false,
        promotion: false,
        images_upload_handler: (blobInfo) => uploadImageToS3(blobInfo.blob()),
        // Mirror the server-side 'products' purifier allowlist so the editor
        // does not present formatting that will be stripped on save.
        valid_elements: 'p,br,strong,em,b,i,u,h2,h3,h4,ul,ol,li,a[href|title],img[src|alt|width|height],'
            + 'table[border|cellpadding|cellspacing|width],thead,tbody,tfoot,tr,th[colspan|rowspan|scope],td[colspan|rowspan],figure,figcaption,span',
    };

    function initAttrEditor(textarea) {
        tinymce.init({ ...ATTR_TINYMCE_CONFIG, target: textarea });
    }

    document.querySelectorAll('#attrRows .attr-wysiwyg').forEach(initAttrEditor);

    // ── Add / remove attribute section ─────────────────────────────────
    document.getElementById('addAttrRow')?.addEventListener('click', function () {
        const tpl = document.getElementById('attrRowTemplate');
        const node = tpl.content.firstElementChild.cloneNode(true);
        document.getElementById('attrRows').appendChild(node);
        initAttrEditor(node.querySelector('.attr-wysiwyg'));
        node.querySelector('input[name="attr_sort[]"]').value = document.querySelectorAll('#attrRows .attr-row').length - 1;
    });

    function removeAttrRow(btn) {
        const row = btn.closest('.attr-row');
        const ta = row.querySelector('.attr-wysiwyg');
        const ed = ta && tinymce.get(ta.id);
        if (ed) { ed.remove(); }
        row.remove();
    }

    // Flush every TinyMCE instance back into its textarea before submit.
    document.querySelector('form')?.addEventListener('submit', function () {
        if (window.tinymce) { tinymce.triggerSave(); }
    });

    // ── Trix inline-image upload → S3 (product description) ────────────
    document.addEventListener('trix-attachment-add', function (event) {
        const attachment = event.attachment;
        if (!attachment.file) return;
        uploadImageToS3(attachment.file)
            .then(url => attachment.setAttributes({ url: url, href: url }))
            .catch(() => { attachment.remove(); alert('Image upload failed. Use a JPG or PNG under 5 MB.'); });
    });
</script>
@endpush
