@extends('layouts.app')
@section('title', 'My Income — Genos BV')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        Every day at 23:59, the platform locks your Left and Right Genos BV. The weaker side (lower of the two) is matched against the 7 slabs to determine your Genos Sales Bonus — but only up to the slab your personal purchase title allows. After the match, the weaker side resets to zero. The stronger (power) side carries forward, capped at 4,50,000 BV — any excess is flushed. For Slab 1 (15,000 BV match) only, your weaker side also carries forward indefinitely until matched — there is no time limit on your first Genos Sales Bonus.
    </div>

    {{-- Slab ladder --}}
    @if($slabProgress !== null)
    <div class="bg-white rounded-2xl border border-gray-200 mb-8 overflow-hidden">
        <div class="px-5 pt-5 pb-4 flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Slab ladder</h2>
                <p class="text-sm text-gray-500">Where you stand on the {{ count($slabProgress->rows) }} Genos Sales Bonus slabs.</p>
            </div>
            @if($slabProgress->highestEarnedSlab !== null)
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">Highest slab earned: Slab {{ $slabProgress->highestEarnedSlab }}</span>
            @else
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500">No slab earned yet</span>
            @endif
        </div>

        @if(! $slabProgress->genosBvEligible)
            <div class="mx-5 mb-5 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800">
                Genos BV is not being counted yet. Genos BV starts counting toward these slabs after your lifetime personal purchases reach {{ \App\Modules\Shared\Support\IndianNumber::format($slabProgress->gsbMinBvPaise / 100, 0) }} BV.
            </div>
        @else
            @php
                // The slab-1 weaker carry-forward bucket has no L/R column: it
                // applies to whichever side is weaker at the next cut-off, so it
                // is shown as a hint under the currently weaker tile rather than
                // added into either side's total. Tie ⇒ Left is the power side
                // (engine tie-break), so Right is the weaker one.
                $slab1WeakerCfBv = (int) round($slabProgress->slab1WeakerCfPaise / 100);
                $powerSideIsLeft = $slabProgress->powerSide() === 'L';
                $weakerSideIsLeft = ! $powerSideIsLeft;
                $sideBadgeClasses = 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium';
                $powerBadgeClasses = $sideBadgeClasses.' bg-indigo-100 text-indigo-700';
                $weakerBadgeClasses = $sideBadgeClasses.' bg-amber-100 text-amber-700';
                $slab1WeakerCfHint = $slab1WeakerCfBv > 0
                    ? '+ '.\App\Modules\Shared\Support\IndianNumber::format($slab1WeakerCfBv, 0).' BV in slab-1 weaker carry over — counted under Slab 1 below'
                    : null;
                $slab1WeakerCfTip = 'Weaker-side BV from earlier days — including any personal-BV top-up — carries over into the slab-1 weaker bucket at each cut-off. That bucket is not pinned to a side; it applies to whichever side is weaker at the next cut-off, so it is not part of this side\'s total. It appears as Slab 1 "Your progress" in the ladder below and counts toward the 15,000 BV first-slab match.';
            @endphp
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 px-5 pb-5">
                <div class="bg-gray-50 rounded-xl px-4 py-3">
                    <p class="text-xs text-gray-500 flex items-center gap-1">Left Genos BV today <x-help-tip text="Today's Left Genos BV plus any BV carried over on your Left side. This is the figure tonight's 23:59 cut-off will use." /></p>
                    <p class="text-xl font-bold font-mono text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($slabProgress->leftEffectivePaise / 100, 0) }}</p>
                    <p class="mt-1">
                        <span class="{{ $powerSideIsLeft ? $powerBadgeClasses : $weakerBadgeClasses }}">{{ $powerSideIsLeft ? 'Power side' : 'Weaker side' }}</span>
                    </p>
                    @if ($slab1WeakerCfHint !== null && $weakerSideIsLeft)
                        <p class="text-xs text-gray-400 mt-1 flex items-start gap-1">
                            {{ $slab1WeakerCfHint }}
                            <x-help-tip :text="$slab1WeakerCfTip" />
                        </p>
                    @endif
                </div>
                <div class="bg-gray-50 rounded-xl px-4 py-3">
                    <p class="text-xs text-gray-500 flex items-center gap-1">Right Genos BV today <x-help-tip text="Today's Right Genos BV plus any BV carried over on your Right side. This is the figure tonight's 23:59 cut-off will use." /></p>
                    <p class="text-xl font-bold font-mono text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($slabProgress->rightEffectivePaise / 100, 0) }}</p>
                    <p class="mt-1">
                        <span class="{{ $powerSideIsLeft ? $weakerBadgeClasses : $powerBadgeClasses }}">{{ $powerSideIsLeft ? 'Weaker side' : 'Power side' }}</span>
                    </p>
                    @if ($slab1WeakerCfHint !== null && ! $weakerSideIsLeft)
                        <p class="text-xs text-gray-400 mt-1 flex items-start gap-1">
                            {{ $slab1WeakerCfHint }}
                            <x-help-tip :text="$slab1WeakerCfTip" />
                        </p>
                    @endif
                </div>
                <div class="bg-gray-50 rounded-xl px-4 py-3">
                    <p class="text-xs text-gray-500 flex items-center gap-1">Matched BV so far <x-help-tip text="The lower of your Left and Right Genos BV. The slabs below are matched against this figure at the 23:59 cut-off." /></p>
                    <p class="text-xl font-bold font-mono text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format(min($slabProgress->leftEffectivePaise, $slabProgress->rightEffectivePaise) / 100, 0) }}</p>
                </div>
            </div>
        @endif

        <div class="overflow-x-auto border-t border-gray-200">
            <table class="w-full text-sm min-w-[820px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-5 py-3 font-semibold text-gray-600">Slab</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Matched BV required <x-help-tip text="BV that must match on both your Left and Right group for this slab." /></span>
                        </th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center gap-1">Title required <x-help-tip text="Your personal purchase title caps the slab you can earn. Titles come from your own lifetime purchase BV." /></span>
                        </th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center gap-1">Left / Right <x-help-tip text="Each side's BV counting toward this slab, shown against the slab's requirement: L is your Left group, R your Right group. Only the weaker (lower) side is matched at the 23:59 cut-off, so a slab pays only once BOTH sides reach it. For Slab 1 the weaker side's figure also includes your lifetime slab-1 weaker carry over." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Your progress <x-help-tip text="Matched BV counting toward this slab right now. Slab 1 includes your lifetime carry over; slabs 2-7 count today's BV only." /></span>
                        </th>
                        <th class="text-left px-5 py-3 font-semibold text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($slabProgress->rows as $row)
                    <tr class="{{ $row->earnedCount > 0 ? 'bg-green-50/60' : ($row->isNext ? 'bg-amber-50/70' : '') }}">
                        <td class="px-5 py-3 font-semibold {{ $row->earnedCount > 0 ? 'text-green-800' : ($row->isNext ? 'text-amber-800' : 'text-gray-700') }}">Slab {{ $row->slab }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::format($row->matchedBvPaise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-gray-600">
                            {{ $row->titleRequired }}
                            @if($row->lockedByTitle)
                                <span class="block text-xs text-gray-400">unlocks at {{ \App\Modules\Shared\Support\IndianNumber::format($row->titleMinBvPaise / 100, 0) }} BV of personal purchases</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">
                            @if($slabProgress->genosBvEligible)
                                @php
                                    $slabRequiredBv = \App\Modules\Shared\Support\IndianNumber::format($row->matchedBvPaise / 100, 0);
                                    $leftMet = $row->leftProgressPaise >= $row->matchedBvPaise;
                                    $rightMet = $row->rightProgressPaise >= $row->matchedBvPaise;
                                @endphp
                                <div class="{{ $leftMet ? 'text-green-700 font-medium' : 'text-gray-500' }}">
                                    L {{ \App\Modules\Shared\Support\IndianNumber::format($row->leftProgressPaise / 100, 0) }} / {{ $slabRequiredBv }}
                                    @if($leftMet)
                                        <span aria-label="met">&check;</span>
                                    @endif
                                </div>
                                <div class="{{ $rightMet ? 'text-green-700 font-medium' : 'text-gray-500' }}">
                                    R {{ \App\Modules\Shared\Support\IndianNumber::format($row->rightProgressPaise / 100, 0) }} / {{ $slabRequiredBv }}
                                    @if($rightMet)
                                        <span aria-label="met">&check;</span>
                                    @endif
                                </div>
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono {{ $row->isNext ? 'font-medium text-amber-800' : 'text-gray-600' }}">
                            {{ $slabProgress->genosBvEligible ? \App\Modules\Shared\Support\IndianNumber::format($row->progressPaise / 100, 0) : '—' }}
                        </td>
                        <td class="px-5 py-3">
                            @if($row->earnedCount > 0)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Earned ×{{ $row->earnedCount }}</span>
                            @elseif($row->isNext)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">Next target</span>
                                @if($slabProgress->genosBvEligible)
                                    <span class="block mt-1 text-xs text-amber-700">{{ \App\Modules\Shared\Support\IndianNumber::format($row->remainingPaise / 100, 0) }} BV more to match</span>
                                @endif
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Filter form --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="from" value="{{ request('from') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="to" value="{{ request('to') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <button type="submit" class="px-4 py-1.5 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 transition-colors">Filter</button>
        @if(request('from') || request('to'))
            <a href="{{ route('income.genos-bv') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">No Genos BV data yet.</p>
            <p class="text-sm text-gray-400 mt-1">Your daily BV log will appear here once the 23:59 cut-off engine is active and your Genos members begin purchasing.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[700px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Date</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Left Genos BV <x-help-tip text="Total Business Volume generated by all distributors placed in your Left Genos subtree today." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Right Genos BV <x-help-tip text="Total Business Volume generated by all distributors placed in your Right Genos subtree today." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-center gap-1">Weaker side <x-help-tip text="The lower of your Left and Right Genos BV. The Genos Sales Bonus slab is matched against the weaker side — the stronger side carries forward." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-center gap-1">Slab <x-help-tip text="The Genos Sales Bonus slab matched at this day's cut-off. See the slab ladder above for each slab's matched BV requirement." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Power CF after <x-help-tip text="BV on your stronger Genos side that moves to the next day — carry over before a slab match; on a day a slab matched, it is the remaining carry forward. Capped at 4,50,000 BV." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Slab-1 weaker CF <x-help-tip text="Weaker side BV accumulating toward the 15,000 BV first-slab match. No time limit." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Result</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-700">{{ $row->cutoff_date->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ \App\Modules\Shared\Support\IndianNumber::format($row->left_bv_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ \App\Modules\Shared\Support\IndianNumber::format($row->right_bv_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center text-gray-500">{{ $row->left_bv_paise <= $row->right_bv_paise ? 'Left' : 'Right' }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($row->slab)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">Slab {{ $row->slab }}</span>
                            @else
                                <span class="text-gray-400 text-xs">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::format($row->power_cf_after_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::format($row->slab1_weaker_cf_after_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($row->slab)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">GSB earned</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">No match</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(method_exists($rows, 'links'))
            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
    @endif
</div>
@endsection
