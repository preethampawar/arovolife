@csrf

<div class="grid grid-cols-1 gap-5">

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Title <span class="text-red-600">*</span> <x-help-tip text="The page heading shown to visitors and used as the browser tab title." /></label>
        <input name="title" type="text" required
            value="{{ old('title', $page->title) }}"
            maxlength="200"
            class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
            @isset($slugReadonly) @endisset>
        @error('title')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Slug <span class="text-red-600">*</span> <x-help-tip text="The URL key for this page (the part after /p/); lowercase letters, numbers and hyphens only, and it cannot be changed after publishing." /></label>
        <div class="flex">
            <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-gray-200 bg-gray-50 text-gray-500 text-sm select-none font-mono">/p/</span>
            <input name="slug" type="text" required
                value="{{ old('slug', $page->slug) }}"
                maxlength="120"
                pattern="[a-z0-9-]+"
                placeholder="e.g. privacy"
                class="flex-1 rounded-l-none rounded-r-lg bg-white border border-gray-200 px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9-]/g,'')">
        </div>
        <p class="mt-1 text-xs text-gray-500">Lowercase letters, numbers and hyphens only. Cannot be changed after publishing.</p>
        @error('slug')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Meta Description <x-help-tip text="A short summary shown by search engines in results; up to 300 characters." /></label>
        <input name="meta_description" type="text"
            value="{{ old('meta_description', $page->meta_description) }}"
            maxlength="300"
            placeholder="Short summary for search engines (max 300 chars)"
            class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
        @error('meta_description')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Status <span class="text-red-600">*</span> <x-help-tip text="Draft keeps the page private, Published makes it publicly visible, and Archived hides it while keeping the record." /></label>
        <div class="flex gap-2">
            @foreach(['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'] as $val => $label)
            <label class="flex-1 flex items-center justify-center gap-2 p-3 rounded-lg border cursor-pointer transition-colors
                {{ old('status', $page->status) === $val ? 'border-brand-500 bg-brand-50' : 'border-gray-200 bg-white hover:border-gray-300' }}">
                <input type="radio" name="status" value="{{ $val }}"
                    {{ old('status', $page->status) === $val ? 'checked' : '' }}
                    class="text-brand-600 border-gray-300 focus:ring-brand-500">
                <span class="text-sm text-gray-800">{{ $label }}</span>
            </label>
            @endforeach
        </div>
        @error('status')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Body <x-help-tip text="The full rich-text content of the page shown to visitors." /></label>
        <input id="content-body-input" type="hidden" name="body" value="{{ old('body', $page->body) }}">
        <trix-editor input="content-body-input"
            class="trix-content bg-white border border-gray-200 rounded-lg min-h-[360px] p-4 focus:outline-none"></trix-editor>
        @error('body')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
    </div>

</div>

<div class="flex items-center justify-between mt-6 pt-5 border-t border-gray-200">
    <a href="{{ route('admin.content.index') }}" class="text-sm text-gray-600 hover:text-gray-900">← Back to list</a>
    <button type="submit"
        class="px-6 py-2.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
        {{ $submitLabel ?? 'Save' }}
    </button>
</div>

@push('styles')
<link rel="stylesheet" type="text/css" href="https://unpkg.com/trix@2.1.15/dist/trix.css">
<style>
    trix-toolbar { background: #f4f7f6; border: 1px solid #e5e7eb; border-bottom: 0; border-radius: 0.5rem 0.5rem 0 0; padding: 0.5rem; }
    trix-editor  { border-radius: 0 0 0.5rem 0.5rem !important; }
    trix-editor:focus { box-shadow: 0 0 0 2px var(--color-brand-500, #2ab3a6); border-color: transparent; }
    .trix-content h1 { font-size: 1.75rem; font-weight: 700; margin: 1rem 0 0.5rem; }
    .trix-content h2 { font-size: 1.4rem; font-weight: 700; margin: 1rem 0 0.5rem; }
    .trix-content ul { list-style: disc; padding-left: 1.5rem; }
    .trix-content ol { list-style: decimal; padding-left: 1.5rem; }
    .trix-content blockquote { border-left: 3px solid #2ab3a6; padding-left: 1rem; color: #4b5563; }
    .trix-content a { color: #1f9a8e; text-decoration: underline; }
</style>
@endpush

@push('scripts')
<script type="text/javascript" src="https://unpkg.com/trix@2.1.15/dist/trix.umd.min.js"></script>
@endpush
