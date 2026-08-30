{{-- Placement card — position in the Genos and the distributor's quick links. --}}
@php
    // Line-change is a one-shot, 5-business-day window from
    // effective_date (mirrors LineChangeController::show + the
    // service-side guard in RequestLineChange).
    $lcBusinessDaysSince = (int) $distributor->effective_date->diffInWeekdays(now());
    $lcRemaining         = max(0, 5 - $lcBusinessDaysSince);
    $lcAvailable         = $lcBusinessDaysSince <= 5;
    $isLeft = $distributor->placement_side === 'L';
    $linkClass = 'flex items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-brand-700 hover:bg-brand-50 hover:text-brand-800 transition';
    $chev = '<svg class="w-3.5 h-3.5 shrink-0 opacity-60" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>';
@endphp
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
    <p class="text-xs text-gray-700 uppercase tracking-wider mb-3 font-semibold">Placement</p>
    <div class="flex items-center justify-between gap-3 rounded-xl {{ $isLeft ? 'bg-sky-50 border-sky-200' : 'bg-indigo-50 border-indigo-200' }} border px-3 py-2.5 text-sm">
        <span class="text-gray-800">Position</span>
        <span class="font-semibold {{ $isLeft ? 'text-sky-700' : 'text-indigo-700' }}">{{ $isLeft ? '← Left' : '→ Right' }} group</span>
    </div>
    <div class="mt-3 -mx-2.5 flex flex-col text-xs font-medium">
        <a href="{{ route('tree.binary') }}" class="{{ $linkClass }}">My Genos {!! $chev !!}</a>
        <a href="{{ route('tree.sponsorship') }}" class="{{ $linkClass }}">My Referrals {!! $chev !!}</a>
        <a href="{{ route('orders.index') }}" class="{{ $linkClass }}">My Orders {!! $chev !!}</a>
        @foreach(\App\Modules\Compensation\Support\IncomeNavLinks::visible() as $businessLink)
            @if(in_array($businessLink['route'], ['my-business', 'income.rank-bonus'], true))
                <a href="{{ route($businessLink['route']) }}" class="{{ $linkClass }}">
                    {{ $businessLink['route'] === 'income.rank-bonus' ? 'My Rank Status' : $businessLink['label'] }} {!! $chev !!}
                </a>
            @endif
        @endforeach
        @if($lcAvailable)
            <a href="{{ route('line-change.show') }}" class="{{ $linkClass }}">
                <span>Request line-change <span class="text-gray-600 font-normal">({{ $lcRemaining }} {{ $lcRemaining === 1 ? 'day' : 'days' }} left)</span></span>
                {!! $chev !!}
            </a>
        @else
            <span class="flex items-center px-2.5 py-2 text-gray-600 cursor-not-allowed line-through"
                  title="The 5-business-day line-change window has ended.">
                Request line-change
            </span>
        @endif
    </div>
</div>
