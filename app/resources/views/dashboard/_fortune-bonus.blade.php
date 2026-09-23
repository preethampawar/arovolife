{{-- Fortune Bonus — this month's gate checklist and last month's written
     outcome, for the logged-in distributor only. Rendered only while the
     Fortune Bonus engine flag is on. The checklist is read through the same
     builders and gates the month-end enrolment uses, so it cannot disagree
     with the engine. Facts only: no rupee figure for a month that has not
     closed, and nothing here projects income. --}}
@php
    $fmt = \App\Modules\Shared\Support\IndianNumber::class;
    $q = $fortuneCard->thisMonth;
    $last = $fortuneCard->lastMonth;
    $entry = $fortuneCard->lastMonthEntry;
    $lastMonthLabel = $fortuneCard->month->subMonthNoOverflow()->format('F Y');
@endphp
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 mb-6">
    {{-- Header --}}
    <div class="flex items-start justify-between gap-3 mb-6 flex-wrap">
        <div>
            <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">Fortune Bonus</p>
            <p class="text-sm text-gray-800 mt-1">Your standing this month and last month's result.</p>
        </div>
        <a href="{{ route('income.fortune-bonus') }}" class="text-xs font-semibold text-brand-700 hover:text-brand-800 underline">Details →</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- This month --}}
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
            <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-600">
                    This month — {{ $fortuneCard->month->format('F Y') }}
                    <x-help-tip text="Your Fortune Bonus conditions so far this month, from your own purchases and GSB income. They are checked again when the month closes." />
                </p>
                @if($q->qualified)
                    <span class="inline-flex items-center gap-1 rounded-full bg-green-50 border border-green-200 px-2 py-1 text-[11px] font-medium text-green-800">
                        <x-lucide-badge-check class="w-3.5 h-3.5" />
                        Qualified so far
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 px-2 py-1 text-[11px] font-medium text-amber-800">
                        <x-lucide-circle-dashed class="w-3.5 h-3.5" />
                        Not qualified yet
                    </span>
                @endif
            </div>

            <p class="text-sm text-gray-800 mb-3">
                Your tier: <span class="font-semibold text-gray-900">{{ $q->tierLabel() }}</span>
                <x-help-tip text="The tier decides which conditions apply to you this month: first-month joiners, distributors without a rank, and each rank have their own personal BV and GSB slab requirements." />
            </p>

            @if($q->rankIneligible)
                <p class="text-sm text-gray-600">Your current rank is not part of the Fortune Bonus.</p>
            @else
                <ul class="flex flex-col gap-2 text-sm text-gray-800">
                    <li class="flex items-start gap-2">
                        @if($q->hasGsbIncome)
                            <x-lucide-check class="w-4 h-4 text-green-700 mt-0.5 shrink-0" />
                        @else
                            <x-lucide-x class="w-4 h-4 text-gray-400 mt-0.5 shrink-0" />
                        @endif
                        <span>
                            GSB income earned this month
                            <x-help-tip text="At least one Genos Sales Bonus credited to you since the 1st of this month." />
                        </span>
                    </li>
                    <li class="flex items-start gap-2">
                        @if($q->personalBvPaise >= $q->bvRequiredPaise)
                            <x-lucide-check class="w-4 h-4 text-green-700 mt-0.5 shrink-0" />
                        @else
                            <x-lucide-x class="w-4 h-4 text-gray-400 mt-0.5 shrink-0" />
                        @endif
                        <span>
                            Personal BV this month: {{ $fmt::format(\App\Modules\Commerce\Support\Bv::points($q->personalBvPaise)) }} of @bv($q->bvRequiredPaise)
                            <x-help-tip text="BV from your own purchases dated this month, against the requirement for your tier." />
                        </span>
                    </li>
                    <li class="flex items-start gap-2">
                        @if($q->slabCount >= $q->slabsRequired)
                            <x-lucide-check class="w-4 h-4 text-green-700 mt-0.5 shrink-0" />
                        @else
                            <x-lucide-x class="w-4 h-4 text-gray-400 mt-0.5 shrink-0" />
                        @endif
                        <span>
                            GSB slabs this month: {{ $fmt::format($q->slabCount) }} of {{ $fmt::format($q->slabsRequired) }}
                            <x-help-tip text="Credited GSB slab achievements this month. Repeats of the same slab count. In your first month only the first slab counts." />
                        </span>
                    </li>
                    @if($q->holdsTitle !== null)
                        <li class="flex items-start gap-2">
                            @if($q->holdsTitle)
                                <x-lucide-check class="w-4 h-4 text-green-700 mt-0.5 shrink-0" />
                            @else
                                <x-lucide-x class="w-4 h-4 text-gray-400 mt-0.5 shrink-0" />
                            @endif
                            <span>
                                Personal-purchase title held
                                <x-help-tip text="Without a rank, you need one of the personal-purchase titles, based on your lifetime personal BV." />
                            </span>
                        </li>
                    @endif
                </ul>
            @endif

            <p class="text-[11px] text-gray-500 mt-4">
                Qualification is confirmed when the month closes. Positions are first come, first served by the date of your first GSB income, and your repurchase wallet must be at ₹0 at month end.
            </p>
        </div>

        {{-- Last month --}}
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
            <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-600 mb-3">
                Last month — {{ $lastMonthLabel }}
                <x-help-tip text="Your recorded Fortune Bonus result for last month, once the month's run has been written." />
            </p>

            @if($last)
                <div class="flex items-center gap-2 flex-wrap mb-3">
                    @if($last->status === \App\Modules\Compensation\Models\FortuneBonusResult::STATUS_CREDITED)
                        <span class="inline-flex items-center gap-1 rounded-full bg-green-50 border border-green-200 px-2 py-1 text-[11px] font-medium text-green-800">
                            <x-lucide-check class="w-3.5 h-3.5" />
                            Credited
                        </span>
                    @elseif($last->status === \App\Modules\Compensation\Models\FortuneBonusResult::STATUS_REPURCHASE_WALLET_BLOCKED)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 px-2 py-1 text-[11px] font-medium text-amber-800">
                            <x-lucide-circle-alert class="w-3.5 h-3.5" />
                            Blocked: repurchase wallet not cleared
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 border border-gray-200 px-2 py-1 text-[11px] font-medium text-gray-700">
                            <x-lucide-minus class="w-3.5 h-3.5" />
                            No income
                        </span>
                    @endif
                </div>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-[11px] text-gray-600">
                            Matrix level
                            <x-help-tip text="Your level in last month's Fortune Bonus matrix, set by your first-come, first-served position." />
                        </dt>
                        <dd class="text-xl font-bold text-gray-900 leading-tight">Level {{ $last->matrix_level }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] text-gray-600">
                            Points
                            <x-help-tip text="Points for your level in last month's Fortune Bonus matrix." />
                        </dt>
                        <dd class="text-xl font-bold text-gray-900 leading-tight">{{ $fmt::format((int) $last->points) }} points</dd>
                    </div>
                    @if($last->status === \App\Modules\Compensation\Models\FortuneBonusResult::STATUS_CREDITED)
                        <div class="col-span-2">
                            <dt class="text-[11px] text-gray-600">
                                Net credited
                                <x-help-tip text="The amount credited to your wallet for last month, after the repurchase deduction." />
                            </dt>
                            <dd class="text-xl font-bold text-gray-900 leading-tight">{{ $fmt::rupees((int) $last->net_paise) }}</dd>
                        </div>
                    @endif
                </dl>
            @elseif($entry)
                <p class="text-sm text-gray-800">
                    Level {{ $entry->matrix_level }}, entered on {{ $entry->enrolled_at?->format('d M') }}
                </p>
                <p class="text-sm text-gray-600 mt-1">Result pending</p>
            @else
                <p class="text-sm text-gray-600">You did not take part last month.</p>
            @endif
        </div>
    </div>
</div>
