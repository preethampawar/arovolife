@extends('admin.layouts.admin')
@section('title', 'Content Pages')
@section('heading', 'Content Pages')

@section('content')

<div class="flex items-center justify-between mb-6">
    <p class="text-sm text-gray-600">
        Manage public content pages (Terms, Privacy, Ethics, Grievance, Notices).
        Every change is recorded in the audit log.
    </p>
    <a href="{{ route('admin.content.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors">
        + New Page
    </a>
</div>

<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Slug</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Updated</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($pages as $page)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.content.edit', $page) }}" class="font-medium text-gray-900 hover:text-brand-600">
                            {{ $page->title }}
                        </a>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-500">/p/{{ $page->slug }}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded-full text-xs border
                            {{ $page->status === 'published' ? 'bg-green-50 text-green-700 border-green-200'
                             : ($page->status === 'draft'   ? 'bg-amber-50 text-amber-700 border-amber-200'
                             : 'bg-gray-100 text-gray-500 border-gray-200') }}">
                            {{ ucfirst($page->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-xs">
                        {{ $page->updated_at->format('d M Y H:i') }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if($page->status === 'published')
                        <a href="{{ route('content.show', $page->slug) }}" target="_blank"
                           class="text-xs text-brand-600 hover:text-brand-700 mr-4">View ↗</a>
                        @endif
                        <a href="{{ route('admin.content.edit', $page) }}" class="text-xs text-brand-600 hover:text-brand-700">Edit →</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">
                        No content pages yet. <a href="{{ route('admin.content.create') }}" class="text-brand-600">Create one →</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($pages->hasPages())
    <div class="px-4 py-4 border-t border-gray-200">{{ $pages->links() }}</div>
    @endif
</div>

@endsection
