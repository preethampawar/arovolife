{{-- The next rank's published conditions next to the distributor's own
     figures, with the rank progress note. Shared by the distributor's Rank
     Bonus page (audience 'self') and the admin compensation tab ('admin') so
     both show the same measurement. --}}
{{-- Next rank conditions — plan facts vs the distributor's own figures.
     No timeline, no earnings estimate (DSR 2021 r.5(1)(d)). --}}
@if($rankStatus->nextRank !== null)
<div>
    <p class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3">
        Conditions for {{ $rankStatus->nextRankName() }}
    </p>
    <div class="space-y-3">
        @foreach($rankStatus->nextRequirements as $requirement)
        @php
            $isBv = $requirement->unit === 'bv';
            $currentLabel = $isBv
                ? \App\Modules\Shared\Support\IndianNumber::format($requirement->current / 100, 0).' BV'
                : \App\Modules\Shared\Support\IndianNumber::format($requirement->current);
            $requiredLabel = $isBv
                ? \App\Modules\Shared\Support\IndianNumber::format($requirement->required / 100, 0).' BV'
                : \App\Modules\Shared\Support\IndianNumber::format($requirement->required);
        @endphp
        <div>
            <div class="flex items-center justify-between text-sm mb-1">
                <span class="text-gray-700 flex items-center gap-1">
                    {{ $requirement->label }}
                    @if($requirement->note)
                        <x-help-tip :text="$requirement->note" />
                    @endif
                </span>
                <span class="font-mono {{ $requirement->met() ? 'text-green-700 font-semibold' : 'text-gray-600' }}">
                    {{ $currentLabel }} <span class="text-gray-600">of</span> {{ $requiredLabel }}
                    @if($requirement->met()) <span class="ml-1">✓</span> @endif
                </span>
            </div>
            <div class="w-full bg-gray-100 rounded-full h-1.5">
                <div class="{{ $requirement->met() ? 'bg-green-500' : 'bg-brand-700' }} h-1.5 rounded-full" style="width:{{ $requirement->percent() }}%"></div>
            </div>
        </div>
        @endforeach
    </div>
    {{-- The Genos BV above is the COUNTED BV: days on which the
         repurchase condition was not met contribute nothing to either
         side, permanently (client spec 2026-09-07 §2.2). Saying so is
         the difference between "your BV is lower than you expected"
         and "here is exactly why". --}}
    @if($rankStatus->forfeitedDaysThisMonth > 0 && $rankStatus->hasMonthGenosBvRequirements())
    <p class="text-xs text-red-700 mt-3 flex items-start gap-1">
        <x-lucide-circle-alert class="w-3.5 h-3.5 mt-0.5 shrink-0" />
        <span class="flex items-center gap-1">
            {{ $rankStatus->forfeitedDaysThisMonth }} {{ \Illuminate\Support\Str::plural('day', $rankStatus->forfeitedDaysThisMonth) }} this month not counted (repurchase condition not met)
            <x-help-tip text="Your repurchase period had closed without being met on those days, so their Left and Right Genos BV was not added to the figures above and never will be. Counting resumed on the day you met the condition. Personal purchase BV is not affected." />
        </span>
    </p>
    @endif
    @include('income._rank-progress-note', ['rankStatus' => $rankStatus])
    <p class="text-xs text-gray-600 mt-3">
        @if($audience === 'self')
        These are the plan's published conditions for {{ $rankStatus->nextRankName() }} shown next to your own current figures. Meeting them is not a guarantee of any income.
        @else
        The plan's published conditions for {{ $rankStatus->nextRankName() }} next to this distributor's current figures — exactly what the distributor sees.
        @endif
    </p>
</div>
@endif
