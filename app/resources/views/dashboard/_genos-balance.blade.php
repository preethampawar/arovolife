{{-- Genos balance — Left vs Right members, and (GSB on) the carried Genos BV
     on each side. Plain Left / Right labels only on this surface. --}}
@php
    $fmt = \App\Modules\Shared\Support\IndianNumber::class;
    $leftTeam  = (int) ($teamStats['left_team'] ?? 0);
    $rightTeam = (int) ($teamStats['right_team'] ?? 0);
    $teamTotal = $leftTeam + $rightTeam;
    // A 0 side still gets a sliver so both labels stay readable.
    $leftPct  = $teamTotal > 0 ? max(4, min(96, (int) round($leftTeam / $teamTotal * 100))) : 50;
    $rightPct = 100 - $leftPct;

    $showBvBars = $gsbOn && $genosBvEligible && $slabProgress !== null;
    if ($showBvBars) {
        $leftBv  = $slabProgress->carriedLeftPaise();
        $rightBv = $slabProgress->carriedRightPaise();
        $bvMax   = max($leftBv, $rightBv, 1);
        $leftBvPct  = (int) round($leftBv / $bvMax * 100);
        $rightBvPct = (int) round($rightBv / $bvMax * 100);
    }
@endphp
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
    <div class="flex items-start justify-between gap-3 mb-5 flex-wrap">
        <div>
            <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">Genos balance</p>
            <p class="text-sm text-gray-800 mt-1">How your Left and Right groups compare right now.</p>
        </div>
        <a href="{{ route('tree.binary') }}" class="text-xs font-semibold text-brand-700 hover:text-brand-800 underline">Open My Genos →</a>
    </div>

    {{-- Members split --}}
    <div class="mb-6">
        <div class="flex items-center justify-between text-xs mb-2">
            <span class="inline-flex items-center gap-1.5 font-semibold text-sky-700"><span class="w-2 h-2 rounded-full bg-sky-500"></span>← Left group · {{ $fmt::format($leftTeam) }} {{ $leftTeam === 1 ? 'member' : 'members' }}</span>
            <span class="inline-flex items-center gap-1.5 font-semibold text-indigo-700">{{ $fmt::format($rightTeam) }} {{ $rightTeam === 1 ? 'member' : 'members' }} · Right group →<span class="w-2 h-2 rounded-full bg-indigo-500"></span></span>
        </div>
        <div class="flex h-4 w-full overflow-hidden rounded-full bg-gray-100" role="img" aria-label="Left group {{ $leftTeam }} members, Right group {{ $rightTeam }} members">
            <div class="h-full bg-gradient-to-r from-sky-400 to-sky-600 transition-all" style="width: {{ $leftPct }}%"></div>
            <div class="h-full bg-gradient-to-r from-indigo-500 to-indigo-600 transition-all" style="width: {{ $rightPct }}%"></div>
        </div>
        <p class="text-[11px] text-gray-600 mt-2">Members placed under each side of your Genos, including everyone below them.</p>
    </div>

    @if($gsbOn)
        <div class="border-t border-gray-100 pt-5">
            <div class="flex items-center justify-between gap-2 mb-3">
                <p class="text-[11px] text-gray-700 uppercase tracking-wider font-semibold">Genos BV carried into tonight's cut-off</p>
                <x-help-tip text="Business Volume on each side that is still waiting to be matched at the 23:59 IST cut-off — today's purchases plus any BV carried over from earlier days. See Genos BV for the slab ladder." />
            </div>
            @if($showBvBars)
                <div class="space-y-3">
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="font-semibold text-sky-700">← Left group</span>
                            <span class="font-bold text-gray-900">@bv($leftBv)</span>
                        </div>
                        <div class="h-2.5 w-full rounded-full bg-gray-100 overflow-hidden"><div class="h-full rounded-full bg-sky-500" style="width: {{ max(2, $leftBvPct) }}%"></div></div>
                    </div>
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="font-semibold text-indigo-700">Right group →</span>
                            <span class="font-bold text-gray-900">@bv($rightBv)</span>
                        </div>
                        <div class="h-2.5 w-full rounded-full bg-gray-100 overflow-hidden"><div class="h-full rounded-full bg-indigo-500" style="width: {{ max(2, $rightBvPct) }}%"></div></div>
                    </div>
                </div>
                <a href="{{ route('income.genos-bv') }}" class="inline-block mt-4 text-xs font-semibold text-brand-700 hover:text-brand-800 underline">Genos BV details →</a>
            @else
                <p class="text-sm text-gray-700">
                    Genos BV is credited once your lifetime personal BV reaches the plan minimum{{ $gsbMinBvPaise !== null ? ' ('.\App\Modules\Commerce\Support\Bv::format($gsbMinBvPaise).')' : '' }}.
                    Until then both sides show 0 BV.
                </p>
                <a href="{{ route('my-business') }}" class="inline-block mt-3 text-xs font-semibold text-brand-700 hover:text-brand-800 underline">My Business →</a>
            @endif
        </div>
    @endif
</div>
