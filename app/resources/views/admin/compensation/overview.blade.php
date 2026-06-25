@extends('admin.layouts.admin')
@section('title', 'Compensation')
@section('heading', 'Compensation Overview')

@section('content')

{{-- Page note --}}
<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    The Compensation Overview shows the real-time status of today's daily GSB cut-off, any failed or stuck jobs, the total pending payout queue, and this week's GSB distributed. Items in the attention feed need action before Tuesday's payout — use Retry or Recalculate to resolve them.
</div>

{{-- Stat cards --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider flex items-center gap-1">
            Today's cut-off
            <x-help-tip text="The 23:59 daily GSB cut-off runs automatically. If it shows Failed, use Manual Controls → Retry." />
        </p>
        <p class="mt-1 text-lg font-bold {{ $cutoffStatus === 'done' ? 'text-green-700' : ($cutoffStatus === 'failed' ? 'text-red-600' : 'text-amber-600') }}">
            {{ match($cutoffStatus) { 'done' => '✓ Done', 'failed' => '✗ Failed', default => '⚠ Pending' } }}
        </p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider flex items-center gap-1">
            Failed jobs
            <x-help-tip text="Jobs that errored during today's cut-off or payout run. Each links to the affected distributor." />
        </p>
        <p class="mt-1 text-lg font-bold {{ $todayFailed > 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($todayFailed) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider flex items-center gap-1">
            Pending payouts
            <x-help-tip text="Total amount queued for the next Tuesday bank transfer. Does not include wallets below the ₹500 minimum." />
        </p>
        <p class="mt-1 text-lg font-bold text-blue-700">₹{{ number_format($pendingPayoutPaise / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider flex items-center gap-1">
            GSB this week
            <x-help-tip text="Net GSB (after admin charge + TDS) credited to wallets since last Tuesday 00:00." />
        </p>
        <p class="mt-1 text-lg font-bold text-purple-700">₹{{ number_format($gsbThisWeekPaise / 100, 2) }}</p>
    </div>
</div>

{{-- Attention feed --}}
@if($failedCutoffs->isEmpty())
<div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700 font-medium">
    ✓ All systems normal — no failed cut-offs today.
</div>
@else
<div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
    <p class="text-sm font-semibold text-red-800 mb-3">⚠ {{ $failedCutoffs->count() }} failed cut-off(s) need attention</p>
    @foreach($failedCutoffs as $item)
    <div class="flex items-center justify-between py-2 border-b border-red-100 last:border-0 text-sm">
        <span class="text-red-700">
            <strong>{{ $item->distributor->adn ?? '—' }}</strong>
            — {{ $item->failure_reason ?? 'Unknown error' }}
        </span>
        <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $item->distributor->adn ?? '']) }}"
           class="text-xs px-2 py-1 rounded bg-amber-100 text-amber-800 hover:bg-amber-200 font-medium">
            Retry →
        </a>
    </div>
    @endforeach
</div>
@endif

{{-- Quick links --}}
@php
    use App\Modules\Shared\Features\FortuneBonusFeature;
    use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
    use App\Modules\Shared\Features\LifetimeAwardsFeature;
    use App\Modules\Shared\Features\RankBonusFeature;
    use Laravel\Pennant\Feature;
@endphp
<div class="flex flex-wrap gap-2 mb-6">
    <a href="{{ route('admin.compensation.weekly-payouts.index') }}" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-xs text-gray-700 hover:bg-gray-50">Weekly Payouts →</a>
    @if(Feature::for(null)->active(GrowthBoosterBonusFeature::class))
    <a href="{{ route('admin.compensation.gbb.index') }}" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-xs text-gray-700 hover:bg-gray-50">Growth Booster Bonus →</a>
    @endif
    @if(Feature::for(null)->active(RankBonusFeature::class))
    <a href="{{ route('admin.compensation.rank-bonus.index') }}" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-xs text-gray-700 hover:bg-gray-50">Rank Bonus →</a>
    @endif
    @if(Feature::for(null)->active(LifetimeAwardsFeature::class))
    <a href="{{ route('admin.lifetime-awards.index') }}" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-xs text-gray-700 hover:bg-gray-50">Lifetime Awards →</a>
    @endif
    @if(Feature::for(null)->active(FortuneBonusFeature::class))
    <a href="{{ route('admin.compensation.fortune-bonus.index') }}" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-xs text-gray-700 hover:bg-gray-50">Fortune Bonus →</a>
    @endif
    <a href="{{ route('admin.compensation.carry-forwards.index') }}" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-xs text-gray-700 hover:bg-gray-50">Carry-forwards →</a>
    <a href="{{ route('admin.compensation.manual-controls.index') }}" class="px-3 py-1.5 rounded-lg border border-indigo-200 bg-indigo-50 text-xs text-indigo-700 hover:bg-indigo-100">Manual Controls →</a>
</div>

{{-- Today's cut-off table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Today's cut-off — {{ \Illuminate\Support\Carbon::today()->format('d M Y') }}</span>
        <a href="{{ route('admin.compensation.daily-cutoffs.index') }}" class="text-xs text-brand-600 hover:underline">View all dates →</a>
    </div>
    @if($cutoffTable->isEmpty())
    <p class="px-5 py-8 text-sm text-gray-400 text-center">No data yet — GSB engine not yet active.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500 font-medium">ADN</th>
                    <th class="px-4 py-2 text-right text-gray-500 font-medium">Left BV <x-help-tip text="Left group BV accumulated today." /></th>
                    <th class="px-4 py-2 text-right text-gray-500 font-medium">Right BV</th>
                    <th class="px-4 py-2 text-center text-gray-500 font-medium">Slab</th>
                    <th class="px-4 py-2 text-right text-gray-500 font-medium">Net GSB</th>
                    <th class="px-4 py-2 text-center text-gray-500 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($cutoffTable as $row)
                <tr class="{{ $row->status === 'failed' ? 'bg-red-50' : '' }}">
                    <td class="px-4 py-2 font-mono">{{ $row->distributor->adn ?? '—' }}</td>
                    <td class="px-4 py-2 text-right">@bv($row->left_bv_paise)</td>
                    <td class="px-4 py-2 text-right">@bv($row->right_bv_paise)</td>
                    <td class="px-4 py-2 text-center">{{ $row->slab ?? '—' }}</td>
                    <td class="px-4 py-2 text-right font-semibold {{ $row->net_gsb_paise > 0 ? 'text-green-700' : 'text-gray-400' }}">
                        {{ $row->net_gsb_paise > 0 ? '₹'.number_format($row->net_gsb_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-4 py-2 text-center">
                        @php $badges = ['credited' => 'bg-green-100 text-green-700', 'failed' => 'bg-red-100 text-red-700', 'no_match' => 'bg-gray-100 text-gray-600', 'frozen' => 'bg-blue-100 text-blue-700', 'below_600bv' => 'bg-amber-100 text-amber-700']; @endphp
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badges[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ str_replace('_', ' ', $row->status) }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $cutoffTable->links() }}</div>
    @endif
</div>

@endsection
