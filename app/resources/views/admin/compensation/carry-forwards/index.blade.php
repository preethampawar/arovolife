@extends('admin.layouts.admin')
@section('title', 'Carry-forwards')
@section('heading', 'GSB Carry-forwards')

@section('content')

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Carry-forward state persists between daily cut-offs. The power side (stronger leg) carries forward up to 4,50,000 BV — excess is flushed at each cut-off. The slab-1 weaker side accumulates indefinitely until the 15,000 BV match. If a BV reversal happens after a cut-off, use <a href="{{ route('admin.compensation.manual-controls.index') }}" class="underline">Recalculate Carry-forward</a> to correct the state.
</div>

<form method="GET" class="flex flex-wrap gap-3 mb-5">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="Search ADN…"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-40">
    <label class="flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" name="filter" value="near_cap"
               {{ request('filter') === 'near_cap' ? 'checked' : '' }}>
        Near cap (&gt;80%)
    </label>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-400 text-center">
        No carry-forward data yet — GSB engine not yet active.
    </p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-500">ADN</th>
                    <th class="px-3 py-2 text-right text-gray-500">
                        Power-side CF
                        <x-help-tip text="BV on the stronger leg carried forward into tomorrow. Capped at 4,50,000 BV." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-500">
                        % of cap
                        <x-help-tip text="Power CF as a percentage of the 4,50,000 BV hard cap." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-500">
                        Power side
                        <x-help-tip text="Which leg (L or R) the carry-forward belongs to." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500">
                        Slab-1 weaker CF
                        <x-help-tip text="Accumulated weaker-side BV counting toward the first 15,000 BV match. No time limit." />
                    </th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                @php
                    $pct = $cap > 0 ? round($row->power_side_bv_paise / $cap * 100, 1) : 0;
                    $atCap = $row->power_side_bv_paise >= $cap;
                @endphp
                <tr class="{{ $atCap ? 'bg-red-50' : '' }}">
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                           class="text-brand-600 hover:underline">
                            {{ $row->distributor->adn ?? '—' }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-right {{ $atCap ? 'text-red-700 font-semibold' : '' }}">
                        @bv($row->power_side_bv_paise)
                    </td>
                    <td class="px-3 py-2 text-center">
                        <div class="flex items-center justify-center gap-2">
                            <div class="w-16 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                <div class="h-full rounded-full {{ $pct >= 80 ? 'bg-red-500' : 'bg-brand-500' }}"
                                     style="width: {{ min(100, $pct) }}%"></div>
                            </div>
                            <span class="{{ $pct >= 80 ? 'text-red-700 font-medium' : 'text-gray-600' }}">{{ $pct }}%</span>
                        </div>
                    </td>
                    <td class="px-3 py-2 text-center font-mono">{{ $row->power_side ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">@bv($row->slab1_weaker_bv_paise)</td>
                    <td class="px-3 py-2 text-right">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                           class="text-brand-600 text-[10px] hover:underline">Detail →</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>

@endsection
