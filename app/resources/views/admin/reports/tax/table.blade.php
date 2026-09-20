@extends('admin.layouts.admin')

@section('title', $title)
@section('heading', $title)

@section('content')
    @php
        $panel = str_starts_with($slug, 'tds') ? '_how-tds-works' : '_how-gst-works';
        $summarySlug = str_starts_with($slug, 'tds') ? 'tds' : 'gst';
    @endphp

    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-4">
            <a href="{{ route('admin.reports.profit.index') }}"
                class="inline-flex items-center gap-1.5 text-sm text-gray-600 hover:text-gray-900">
                {{ svg('lucide-arrow-left', 'w-3.5 h-3.5', ['aria-hidden' => 'true']) }}
                All profit reports
            </a>
            <a href="{{ route('admin.reports.profit.'.$summarySlug, request()->query()) }}"
                class="inline-flex items-center gap-1.5 text-sm text-brand-600 hover:text-brand-700 font-medium">
                {{ svg('lucide-arrow-left', 'w-3.5 h-3.5', ['aria-hidden' => 'true']) }}
                Back to the summary
            </a>
        </div>

        @include('admin.reports.tax.'.$panel)
        @include('admin.reports.tax._filters')

        @if (! empty($notice))
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                {{ $notice }}
            </div>
        @endif

        <x-ui.card flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs uppercase tracking-wider text-gray-600">
                            @foreach ($columns as $column)
                                <th class="px-4 py-3 whitespace-nowrap {{ ($column['align'] ?? '') === 'right' ? 'text-right' : '' }}">
                                    {{ $column['label'] }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr class="hover:bg-gray-50">
                                @foreach ($columns as $column)
                                    <td class="px-4 py-3 {{ ($column['align'] ?? '') === 'right' ? 'text-right font-mono whitespace-nowrap' : '' }}">
                                        {{ $row[$column['key']] ?? '' }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <x-ui.empty-state :colspan="count($columns)" title="Nothing was recorded in this period." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
@endsection
