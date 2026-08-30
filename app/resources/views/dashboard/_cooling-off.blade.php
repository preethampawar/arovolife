{{-- Cooling-off card — statutory 30-day window with a progress ring. --}}
@php
    $daysLeft = now()->diffInDays($distributor->cooling_off_end_at, false);
    $isActive = $daysLeft > 0;
    $daysShown = max(0, (int) $daysLeft);
    $fraction = min(1, max(0, $daysShown / 30));
    $r = 30; $circ = 2 * M_PI * $r;
    $dash = round($circ * $fraction, 2);
    $urgent = $daysLeft <= 7;
@endphp
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
    <p class="text-xs text-gray-700 uppercase tracking-wider mb-3 font-semibold">Cooling-Off Period</p>
    @if($isActive)
        <div class="flex items-center gap-4">
            <svg viewBox="0 0 72 72" class="w-20 h-20 shrink-0" role="img" aria-label="{{ $daysShown }} of 30 cooling-off days remaining">
                <circle cx="36" cy="36" r="{{ $r }}" fill="none" stroke="#f3f4f6" stroke-width="7"/>
                <circle cx="36" cy="36" r="{{ $r }}" fill="none" stroke="{{ $urgent ? '#b91c1c' : '#b76017' }}" stroke-width="7"
                        stroke-linecap="round" stroke-dasharray="{{ $dash }} {{ round($circ, 2) }}" transform="rotate(-90 36 36)"/>
                <text x="36" y="40" text-anchor="middle" font-size="18" font-weight="700" fill="{{ $urgent ? '#b91c1c' : '#b76017' }}">{{ $daysShown }}</text>
            </svg>
            <div class="min-w-0">
                <p class="text-2xl font-bold {{ $urgent ? 'text-red-700' : 'text-amber-700' }} leading-tight">{{ $daysShown }} days</p>
                <p class="text-xs text-gray-700 mt-1">remaining (ends {{ $distributor->cooling_off_end_at->format('d M Y') }})</p>
            </div>
        </div>
        <p class="text-xs text-gray-700 mt-3">You may cancel your registration during this window.</p>
        <a href="{{ route('cooling-off.show') }}" class="inline-block mt-3 text-xs text-red-600 hover:text-red-700 underline">
            Cancel registration →
        </a>
    @else
        <p class="text-sm text-gray-800">Cooling-off period expired</p>
    @endif
</div>
