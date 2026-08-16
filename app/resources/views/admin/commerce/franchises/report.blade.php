@extends('admin.layouts.admin')
@section('title', 'Franchise commission')
@section('heading', 'Franchise commission')

@section('content')
<a href="{{ route('admin.commerce.franchises.index') }}" class="text-sm text-sunrise-400 underline">← Franchises</a>

<div class="mt-4 mb-6 flex flex-wrap items-end justify-between gap-4">
    <div class="max-w-2xl text-sm leading-relaxed text-slate-400">
        <p>
            How each franchise's commission for the month is arrived at.
            <strong>Fulfilled value</strong> is the product value of the orders that franchise delivered —
            subtotal less discount. GST is excluded because it is tax collected for the government, and
            shipping because it is a pass-through cost; paying a share of either would be paying commission
            on money the company never earned.
        </p>
        <p class="mt-2 text-slate-500">
            Rows marked <em>not run</em> are a live projection of what the month currently holds. Nothing is
            owed until the engine has run and the row says <em>credited</em>.
        </p>
    </div>
    <form method="GET" action="{{ route('admin.commerce.franchises.report') }}" class="flex items-end gap-2">
        <div>
            <label for="month" class="mb-1 block text-xs font-medium text-slate-400">Month</label>
            <input id="month" name="month" type="month" value="{{ $month->format('Y-m') }}"
                   class="rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100">
        </div>
        <button type="submit" class="rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">
            View
        </button>
    </form>
</div>

<div class="mb-6 rounded-xl border border-slate-700 bg-slate-900/40 px-5 py-4">
    <p class="text-xs uppercase tracking-wider text-slate-500">Credited in {{ $month->format('F Y') }}</p>
    <p class="mt-1 text-2xl font-bold text-slate-100">₹@bv($totalGrossPaise / 100)</p>
    <p class="mt-1 text-xs text-slate-500">Plan rate {{ number_format($planRateBp / 100, 2) }}%</p>
</div>

@if ($rows === [])
    <div class="rounded-xl border border-dashed border-slate-700 px-6 py-12 text-center text-slate-400">
        No franchises on the register yet.
    </div>
@else
    <div class="overflow-x-auto rounded-xl border border-slate-700">
        <table class="min-w-full divide-y divide-slate-700 text-sm">
            <thead class="bg-slate-800 text-left text-xs uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-4 py-3">Code</th>
                    <th class="px-4 py-3">Operator</th>
                    <th class="px-4 py-3">Orders fulfilled</th>
                    <th class="px-4 py-3">Fulfilled value</th>
                    <th class="px-4 py-3">Rate</th>
                    <th class="px-4 py-3">Commission</th>
                    <th class="px-4 py-3">State</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
                @foreach ($rows as $row)
                    <tr class="hover:bg-slate-800/60">
                        <td class="px-4 py-3 font-mono text-xs">
                            <a href="{{ route('admin.commerce.franchises.edit', $row['franchise']->id) }}"
                               class="font-semibold text-sunrise-400 underline">{{ $row['franchise']->code }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-400">
                            {{ $row['franchise']->operator?->user?->full_name ?? ($row['franchise']->is_company_primary ? 'Company' : '—') }}
                        </td>
                        <td class="px-4 py-3 text-slate-400">{{ $row['order_count'] }}</td>
                        <td class="px-4 py-3 text-slate-400">₹@bv($row['base_paise'] / 100)</td>
                        <td class="px-4 py-3 text-slate-400">{{ number_format($row['rate_bp'] / 100, 2) }}%</td>
                        <td class="px-4 py-3 font-semibold {{ $row['state'] === 'credited' ? 'text-emerald-300' : 'text-slate-300' }}">
                            ₹@bv($row['gross_paise'] / 100)
                        </td>
                        <td class="px-4 py-3">
                            @if ($row['state'] === 'credited')
                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">Credited</span>
                            @elseif ($row['state'] === 'not_run')
                                <span class="rounded-full bg-slate-700 px-2.5 py-1 text-xs font-semibold text-slate-300">Not run</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">{{ ucfirst($row['state']) }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
