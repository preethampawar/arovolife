@extends('admin.layouts.admin')
@section('title', 'Compliance Documents')
@section('heading', 'Compliance Documents')

@section('content')

<div class="max-w-4xl">
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold mb-1">Compliance Documents</p>
        <p class="leading-relaxed">
            Upload statutory registrations, policies and certifications. Published documents appear publicly at
            <a href="{{ route('compliance-documents.index') }}" target="_blank" rel="noopener" class="underline">/compliance-documents</a>
            and are downloadable by anyone. Every upload, publish-toggle and deletion is audit-logged.
        </p>
    </div>

    {{-- Upload form --}}
    <form method="POST" action="{{ route('admin.compliance-documents.store') }}" enctype="multipart/form-data"
          class="bg-white rounded-2xl border border-gray-200 p-6 mb-8 space-y-4">
        @csrf
        <p class="text-sm font-semibold text-gray-900">Upload a new document</p>

        <div>
            <label for="title" class="block text-xs font-medium text-gray-700 mb-1">Title <x-help-tip text="The document's name shown on the public Compliance Documents page." /></label>
            <input id="title" name="title" type="text" required maxlength="255" value="{{ old('title') }}"
                placeholder="e.g. Certificate of Incorporation"
                class="w-full rounded-lg border px-3 py-2 text-sm focus:ring-brand-500 focus:border-brand-500 {{ $errors->has('title') ? 'border-red-400' : 'border-gray-300' }}">
        </div>

        <div>
            <label for="description" class="block text-xs font-medium text-gray-700 mb-1">Description <span class="text-gray-400">(optional)</span> <x-help-tip text="A short note shown beneath the title to explain what the document is." /></label>
            <input id="description" name="description" type="text" maxlength="512" value="{{ old('description') }}"
                placeholder="Short note shown beneath the title"
                class="w-full rounded-lg border px-3 py-2 text-sm focus:ring-brand-500 focus:border-brand-500 {{ $errors->has('description') ? 'border-red-400' : 'border-gray-300' }}">
        </div>

        <div>
            <label for="document" class="block text-xs font-medium text-gray-700 mb-1">File <x-help-tip text="The file to upload: PDF, Word, Excel or image (JPG/PNG), up to 20 MB." /></label>
            <input id="document" name="document" type="file" required
                accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
                class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100">
            <p class="text-xs text-gray-500 mt-1">PDF, Word, Excel or image (JPG/PNG). Max 20 MB.</p>
        </div>

        <button type="submit"
            class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors">
            Upload &amp; publish
        </button>
    </form>

    {{-- Document list --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50/50">
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider w-12">S.No</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Document</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">File</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($documents as $doc)
                <tr class="hover:bg-gray-50/40 transition-colors">
                    <td class="px-4 py-3 text-gray-500">{{ $loop->iteration }}</td>
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900">{{ $doc->title }}</p>
                        @if($doc->description)<p class="text-xs text-gray-600 mt-0.5">{{ $doc->description }}</p>@endif
                        <p class="text-[11px] text-gray-400 mt-0.5">{{ $doc->created_at->format('d M Y') }}@if($doc->uploader) · {{ $doc->uploader->full_name ?: $doc->uploader->email }}@endif</p>
                    </td>
                    <td class="px-4 py-3 text-gray-700 text-xs">
                        {{ strtoupper(pathinfo($doc->original_name, PATHINFO_EXTENSION)) }} · {{ $doc->humanSize() }}
                    </td>
                    <td class="px-4 py-3">
                        @if($doc->is_published)
                            <span class="px-2 py-0.5 rounded-full text-xs border bg-green-50 text-green-700 border-green-200">Public</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-xs border bg-gray-100 text-gray-600 border-gray-200">Hidden</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-2">
                            @if($doc->is_published)
                            <a href="{{ route('compliance-documents.download', $doc->id) }}" class="text-xs text-brand-600 hover:text-brand-700">Download</a>
                            @endif
                            <form method="POST" action="{{ route('admin.compliance-documents.toggle', $doc->id) }}"
                                  data-confirm="{{ $doc->is_published ? 'Hide this document from the public?' : 'Publish this document publicly?' }}"
                                  data-confirm-title="{{ $doc->is_published ? 'Hide document' : 'Publish document' }}"
                                  data-confirm-impact="{{ $doc->is_published ? 'It will no longer appear on the public Compliance Documents page or be downloadable.' : 'It will appear on the public Compliance Documents page and be downloadable by anyone.' }}">
                                @csrf @method('PATCH')
                                <button type="submit" class="text-xs text-gray-700 hover:text-gray-900">{{ $doc->is_published ? 'Hide' : 'Publish' }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.compliance-documents.destroy', $doc->id) }}"
                                  data-confirm="Delete this compliance document?"
                                  data-confirm-title="Delete document"
                                  data-confirm-impact="The file is permanently removed and the public download link stops working. This cannot be undone.">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs text-red-600 hover:text-red-700">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-600">No documents uploaded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
