{{-- Team growth — registrations in the Genos downline per day, last 30 days.
     Inline SVG bars; historical counts only. --}}
@php
    $fmt = \App\Modules\Shared\Support\IndianNumber::class;
    $days = array_values($teamGrowth);
    $keys = array_keys($teamGrowth);
    $n = count($days);
    $total = array_sum($days);
    $peak = max(1, $n > 0 ? max($days) : 1);
    $w = 300; $h = 80; $base = 70; $plotH = 62;
    $slot = $n > 0 ? $w / $n : $w;
    $bar = max(2, $slot * 0.62);
    $firstLabel = $n > 0 ? \Illuminate\Support\Carbon::parse($keys[0])->format('d M') : '';
    $lastLabel  = $n > 0 ? \Illuminate\Support\Carbon::parse($keys[$n - 1])->format('d M') : '';
@endphp
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
    <div class="flex items-start justify-between gap-3 mb-4 flex-wrap">
        <div>
            <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">Team growth</p>
            <p class="text-sm text-gray-800 mt-1">Registrations in your Genos, last 30 days.</p>
        </div>
        <div class="text-right">
            <p class="text-2xl font-bold text-leaf-700 leading-none">{{ $fmt::format($total) }}</p>
            <p class="text-[11px] text-gray-600 mt-1">new {{ $total === 1 ? 'member' : 'members' }}</p>
        </div>
    </div>

    @if($n > 0)
        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full h-28" role="img" preserveAspectRatio="none"
             aria-label="{{ $total }} registrations in your Genos over the last {{ $n }} days">
            <line x1="0" y1="{{ $base }}" x2="{{ $w }}" y2="{{ $base }}" stroke="#e5e7eb" stroke-width="1"/>
            @foreach($days as $i => $count)
                @php
                    $bh = $count > 0 ? max(3, $count / $peak * $plotH) : 1.5;
                    $x = $i * $slot + ($slot - $bar) / 2;
                    $y = $base - $bh;
                @endphp
                <rect x="{{ round($x, 2) }}" y="{{ round($y, 2) }}" width="{{ round($bar, 2) }}" height="{{ round($bh, 2) }}" rx="1.5"
                      fill="{{ $count > 0 ? '#4fb435' : '#d1d5db' }}">
                    <title>{{ \Illuminate\Support\Carbon::parse($keys[$i])->format('d M Y') }}: {{ $count }} {{ $count === 1 ? 'registration' : 'registrations' }}</title>
                </rect>
            @endforeach
        </svg>
        <div class="flex items-center justify-between text-[11px] text-gray-600 mt-1">
            <span>{{ $firstLabel }}</span>
            <span>Peak day: {{ $fmt::format($n > 0 ? max($days) : 0) }}</span>
            <span>{{ $lastLabel }}</span>
        </div>
    @else
        <p class="text-sm text-gray-700">Growth figures will appear here once your team data is available.</p>
    @endif
</div>
