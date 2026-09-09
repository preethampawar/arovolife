@extends('admin.layouts.admin')
@section('title', 'Announcements')
@section('heading', 'Announcements')

@section('content')
@php
    use App\Modules\Content\Models\Announcement;
    use App\Modules\Shared\Support\IndianNumber;
    $badge = [
        Announcement::STATUS_DRAFT => 'bg-gray-100 text-gray-700',
        Announcement::STATUS_PUBLISHED => 'bg-green-100 text-green-700',
        Announcement::STATUS_ARCHIVED => 'bg-amber-100 text-amber-800',
    ];
@endphp

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    An announcement is company copy addressed to distributors, so it is held to the same rule as the public site: nothing that implies a future income (DSR 2021 Rule 5(1)(d)). The wording is checked when you save and again when you publish, and a phrase that breaks the rule is refused rather than flagged. Publishing is a separate step from saving — write it, read it back, then publish.
</div>

@if(session('status'))
    <div class="mb-4 rounded-lg border border-leaf-200 bg-leaf-50 px-4 py-3 text-sm text-leaf-800">{{ session('status') }}</div>
@endif

<a href="{{ route('admin.announcements.create') }}"
   class="mb-4 inline-flex items-center gap-1.5 rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800">
    <x-lucide-plus class="h-4 w-4" />
    New announcement
</a>

@if($announcements->isEmpty())
    <div class="rounded-xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-600">
        Nothing written yet.
    </div>
@else
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-600">
                <tr>
                    <th class="px-4 py-3">Title</th>
                    <th class="px-4 py-3">Audience</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Opened by</th>
                    <th class="px-4 py-3">Written by</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($announcements as $announcement)
                    <tr>
                        <td class="px-4 py-3 font-medium text-gray-900">
                            @if($announcement->pinned)<x-lucide-pin class="mr-1 inline h-3.5 w-3.5 text-gray-500" />@endif
                            {{ $announcement->title }}
                        </td>
                        <td class="px-4 py-3 text-gray-700">{{ $announcement->audienceLabel() }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$announcement->status] ?? 'bg-gray-100 text-gray-700' }}">
                                {{ ucfirst($announcement->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-700">{{ IndianNumber::format($announcement->reads_count) }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $announcement->author?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.announcements.edit', ['announcement' => $announcement->id]) }}"
                               class="text-sm font-medium text-brand-700 hover:text-brand-800">Open</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $announcements->links() }}</div>
@endif
@endsection
