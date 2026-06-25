@extends('admin.layouts.admin')
@section('title', 'Growth Booster Bonus')
@section('heading', 'Growth Booster Bonus')

@section('content')

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    The Growth Booster Bonus (GBB) is 5% of company monthly turnover, distributed proportionally via Arovolife Growth Points (AGP). Eligible distributors earn AGP from Slab 1 (12 AGP), Slab 2 (5 AGP), or Slab 3 (2 AGP) of the GSB — capped at 120 AGP each. Runs automatically on the 2nd of each month via <code class="font-mono bg-blue-100 px-1 rounded">php artisan gbb:monthly-run</code>.
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($months->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-400 text-center">No GBB batches yet — engine has not yet run.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500">Month</th>
                    <th class="px-4 py-2 text-right text-gray-500">
                        Distributors <x-help-tip text="Number of eligible distributors who earned at least 1 AGP." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-500">
                        Total AGP <x-help-tip text="Sum of all AGP earned by eligible distributors for this month (each capped at 120)." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-500">Net GBB credited</th>
                    <th class="px-4 py-2 text-right text-gray-500">Credited at</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($months as $m)
                <tr>
                    <td class="px-4 py-2 font-medium">{{ \Illuminate\Support\Carbon::parse($m->year_month)->format('F Y') }}</td>
                    <td class="px-4 py-2 text-right">{{ number_format($m->distributor_count) }}</td>
                    <td class="px-4 py-2 text-right">{{ number_format($m->total_agp) }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-green-700">₹{{ number_format($m->total_net_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right text-gray-500">{{ $m->credited_at ? \Illuminate\Support\Carbon::parse($m->credited_at)->format('d M Y H:i') : '—' }}</td>
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.compensation.gbb.show', \Illuminate\Support\Carbon::parse($m->year_month)->format('Y-m')) }}"
                           class="text-brand-600 text-xs hover:underline">View →</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

@endsection
