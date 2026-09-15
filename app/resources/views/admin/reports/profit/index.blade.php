@extends('admin.layouts.admin')

@section('title', 'Profit on sales')
@section('heading', 'Profit on sales')

@section('content')
    @php
        $cards = [
            [
                'route' => 'summary',
                'title' => 'Profit summary',
                'blurb' => 'Purchases, stock and sales reconciled into cost of goods sold, gross profit and contribution. Start here.',
                'icon' => 'lucide-scale',
            ],
            [
                'route' => 'by-product',
                'title' => 'Profit by product',
                'blurb' => 'Which SKUs actually make money. Sold qty, net sales, landed cost, gross profit, margin and markup per product.',
                'icon' => 'lucide-package',
            ],
            [
                'route' => 'by-category',
                'title' => 'Profit by category',
                'blurb' => 'The same figures rolled up to product category, for deciding where the range is worth expanding.',
                'icon' => 'lucide-layers',
            ],
            [
                'route' => 'register',
                'title' => 'Profit register',
                'blurb' => 'One row per sold line, with its cost and margin. The drill-down behind every total on the other three.',
                'icon' => 'lucide-list',
            ],
        ];
    @endphp

    <div class="space-y-4">
        @include('admin.reports.profit._how-it-works')

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @foreach ($cards as $card)
                <a href="{{ route('admin.reports.profit.'.$card['route']) }}"
                    class="block rounded-lg border border-gray-200 bg-white p-5 hover:border-brand-300 hover:shadow-sm transition">
                    <div class="flex items-start gap-3">
                        <span class="shrink-0 rounded-lg bg-brand-50 p-2 text-brand-600">
                            {{ svg($card['icon'], 'w-5 h-5', ['aria-hidden' => 'true']) }}
                        </span>
                        <span class="block">
                            <span class="block text-sm font-semibold text-gray-900">{{ $card['title'] }}</span>
                            <span class="block text-xs text-gray-600 mt-1 leading-relaxed">{{ $card['blurb'] }}</span>
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
@endsection
