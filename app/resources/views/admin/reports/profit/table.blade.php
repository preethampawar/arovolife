@extends('admin.layouts.admin')

@section('title', $title)
@section('heading', $title)

@section('content')
    <div class="space-y-4">
        <a href="{{ route('admin.reports.profit.index') }}"
            class="inline-flex items-center gap-1.5 text-sm text-gray-600 hover:text-gray-900">
            {{ svg('lucide-arrow-left', 'w-3.5 h-3.5', ['aria-hidden' => 'true']) }}
            All profit reports
        </a>

        @include('admin.reports.profit._how-it-works')
        @include('admin.reports.profit._filters')

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
                                <th class="px-4 py-3 {{ ($column['align'] ?? '') === 'right' ? 'text-right' : '' }}">
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
                            <x-ui.empty-state :colspan="count($columns)" title="No sales in this period." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
@endsection
