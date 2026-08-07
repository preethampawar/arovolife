@extends('admin.layouts.admin')
@section('title', 'Fortune Bonus')
@section('heading', 'Fortune Bonus')

@section('content')

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    The Fortune Bonus is 5% of the month's company-wide BV, shared out on FB points. Eligible distributors are placed first-come, first-served into a monthly 3×9 forced matrix based on GSB activity, and each participant earns points from the enrolled distributors below them (9/9/9/8/7/6/5/4/3 per member at levels 1–9). The point value is the month's pool divided by everyone's total points, floored to the whole rupee; pool, total points and point value are frozen before any credit and never recomputed. No admin charge applies; 5% TDS is deducted. Runs automatically on the 9th via <code class="font-mono bg-blue-100 px-1 rounded">php artisan fortune:monthly-run</code>.
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($months->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-400 text-center">No Fortune Bonus batches yet — engine has not yet run.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500">Month</th>
                    <th class="px-4 py-2 text-right text-gray-500">Participants</th>
                    <th class="px-4 py-2 text-right text-gray-500">
                        Pool
                        <x-help-tip text="5% of the month's company-wide BV, frozen before any credit." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-500">
                        Total points
                        <x-help-tip text="The sum of every enrolled participant's FB points — the denominator of the point value." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-500">
                        Point value
                        <x-help-tip text="Pool ÷ total FB points, floored to the whole rupee." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-500">Net credited</th>
                    <th class="px-4 py-2 text-right text-gray-500">Credited at</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($months as $m)
                @php($pool = $pools[$m->month_start] ?? null)
                <tr>
                    <td class="px-4 py-2 font-medium">{{ \Illuminate\Support\Carbon::parse($m->month_start)->format('F Y') }}</td>
                    <td class="px-4 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::format($m->participant_count) }}</td>
                    <td class="px-4 py-2 text-right text-indigo-700">{{ $pool ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->pool_paise / 100, 2) : '—' }}</td>
                    <td class="px-4 py-2 text-right text-gray-600">{{ $pool ? \App\Modules\Shared\Support\IndianNumber::format($pool->total_points) : '—' }}</td>
                    <td class="px-4 py-2 text-right text-gray-600">{{ $pool ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->point_value_paise / 100, 2) : '—' }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($m->total_net_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right text-gray-500">
                        {{ $m->credited_at ? \Illuminate\Support\Carbon::parse($m->credited_at)->format('d M Y H:i') : '—' }}
                    </td>
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.compensation.fortune-bonus.show', \Illuminate\Support\Carbon::parse($m->month_start)->format('Y-m')) }}"
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
