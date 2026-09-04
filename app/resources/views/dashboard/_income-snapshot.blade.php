{{-- Income snapshot — wallet balance, payout dates and per-bonus credits.
     Rendered only when at least one bonus engine is switched on. Every rupee
     is money already credited to the wallet; nothing here is a projection. --}}
@php
    $fmt = \App\Modules\Shared\Support\IndianNumber::class;
    $monthKeys = array_keys($creditsByMonth);
    $monthVals = array_values($creditsByMonth);
    $mn = count($monthVals);
    $mPeak = max(1, $mn > 0 ? max($monthVals) : 1);
    $mw = 300; $mh = 76; $mBase = 74; $mPlot = 66;
    $mSlot = $mn > 0 ? $mw / $mn : $mw;
    $mBar = $mSlot * 0.5;
    $monthTotal = $monthVals[$mn - 1] ?? 0;
    $lifetimeTotal = array_sum(array_column($bonusSummary, 'lifetimePaise'));
@endphp
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 mb-6">
    {{-- Header --}}
    <div class="flex items-start justify-between gap-3 mb-6 flex-wrap">
        <div>
            <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">Income snapshot</p>
            <p class="text-sm text-gray-800 mt-1">Bonus income credited to your wallet from product sales.</p>
        </div>
        <a href="{{ route('income.dashboard') }}" class="text-xs font-semibold text-brand-700 hover:text-brand-800 underline">Full income view →</a>
    </div>

    {{-- Two-column body --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Left: stat tiles + chart --}}
        <div class="flex flex-col gap-5">
            {{-- Stat tiles — 2×2 grid --}}
            <div class="grid grid-cols-2 gap-3">
                {{-- Wallet balance — spans both columns --}}
                <div class="col-span-2 rounded-xl bg-gradient-to-br from-brand-600 to-brand-800 text-white p-4">
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-[11px] uppercase tracking-wider font-semibold text-white/80">Wallet balance</p>
                        <x-help-tip :light="true" text="Transferred to your bank on payout days, after the 3% admin charge, 5% TDS and any repurchase deduction, once the minimum payout is met." />
                    </div>
                    <p class="text-3xl font-bold leading-tight">{{ $walletBalancePaise !== null ? $fmt::rupees($walletBalancePaise) : '₹—' }}</p>
                </div>

                {{-- Credited this month --}}
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-600">This month</p>
                        <x-help-tip text="Total bonus credits that reached your wallet since the 1st of this month." />
                    </div>
                    <p class="text-xl font-bold text-gray-900 leading-tight">{{ $fmt::rupees((int) $monthTotal) }}</p>
                    <p class="text-[11px] text-gray-500 mt-0.5">Credited</p>
                </div>

                {{-- Lifetime total --}}
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-600">Lifetime</p>
                        <x-help-tip text="Total bonus credits across all engines since you joined." />
                    </div>
                    <p class="text-xl font-bold text-gray-900 leading-tight">{{ $fmt::rupees($lifetimeTotal) }}</p>
                    <p class="text-[11px] text-gray-500 mt-0.5">Total earned</p>
                </div>

                {{-- Next weekly payout --}}
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-600">Next weekly</p>
                        <x-help-tip text="Weekly bonuses transfer to your bank every Tuesday (IST), provided your balance meets the minimum payout." />
                    </div>
                    <p class="text-xl font-bold text-gray-900 leading-tight">{{ $keyDates['nextWeeklyPayout']->format('D, d M') }}</p>
                    <p class="text-[11px] text-gray-500 mt-0.5">Payout date</p>
                </div>

                {{-- Next monthly payout (if any monthly bonuses are active, else show payout label) --}}
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-600">Next monthly</p>
                        <x-help-tip text="Monthly bonuses transfer to your bank on the 9th of each month, provided your balance meets the minimum payout." />
                    </div>
                    @if($keyDates['hasMonthlyBonuses'] ?? false)
                        <p class="text-xl font-bold text-gray-900 leading-tight">{{ $keyDates['nextMonthlyPayout']->format('D, d M') }}</p>
                    @else
                        <p class="text-xl font-bold text-gray-400 leading-tight">—</p>
                    @endif
                    <p class="text-[11px] text-gray-500 mt-0.5">Payout date</p>
                </div>
            </div>

            {{-- Six-month credits chart --}}
            @if($mn > 0)
                <div>
                    <p class="text-[11px] text-gray-700 uppercase tracking-wider font-semibold mb-2">Wallet credits, last {{ $mn }} months</p>
                    <svg viewBox="0 0 {{ $mw }} {{ $mh }}" class="w-full h-28" role="img" preserveAspectRatio="none"
                         aria-label="Wallet credits per month for the last {{ $mn }} months">
                        <line x1="0" y1="{{ $mBase }}" x2="{{ $mw }}" y2="{{ $mBase }}" stroke="#e5e7eb" stroke-width="1"/>
                        @foreach($monthVals as $i => $paise)
                            @php
                                $bh = $paise > 0 ? max(3, $paise / $mPeak * $mPlot) : 1.5;
                                $x = $i * $mSlot + ($mSlot - $mBar) / 2;
                                $y = $mBase - $bh;
                            @endphp
                            <rect x="{{ round($x, 2) }}" y="{{ round($y, 2) }}" width="{{ round($mBar, 2) }}" height="{{ round($bh, 2) }}" rx="2"
                                  fill="{{ $paise > 0 ? '#0a719f' : '#d1d5db' }}">
                                <title>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $monthKeys[$i])->format('M Y') }}: {{ $fmt::rupees((int) $paise) }}</title>
                            </rect>
                        @endforeach
                    </svg>
                    <div class="grid text-center text-[11px] text-gray-600 mt-1" style="grid-template-columns: repeat({{ $mn }}, minmax(0, 1fr))">
                        @foreach($monthKeys as $key)
                            <span>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $key)->format('M') }}</span>
                        @endforeach
                    </div>
                    @if(array_sum($monthVals) === 0)
                        <p class="text-[11px] text-gray-600 mt-1">No wallet credits in this period yet.</p>
                    @endif
                </div>
            @endif
        </div>

        {{-- Right: per-bonus table --}}
        <div>
            <div class="divide-y divide-gray-100 border border-gray-100 rounded-xl overflow-hidden">
                <div class="grid grid-cols-[1fr_auto_auto] gap-x-4 px-4 py-3 text-[11px] uppercase tracking-wider font-semibold text-gray-600 bg-gray-50">
                    <span>Bonus</span><span class="text-right w-24">This month</span><span class="text-right w-28">Lifetime</span>
                </div>
                @foreach($bonusSummary as $row)
                    <a href="{{ route($row['route']) }}" class="grid grid-cols-[1fr_auto_auto] gap-x-4 items-center px-4 py-3 text-sm hover:bg-gray-50 transition">
                        <span class="inline-flex items-center gap-1 min-w-0">
                            <span class="font-medium text-gray-900 truncate">{{ $row['label'] }}</span>
                            <x-help-tip :text="$row['tip']" />
                        </span>
                        <span class="text-right w-24 font-semibold text-gray-900">{{ $fmt::rupees($row['monthPaise']) }}</span>
                        <span class="text-right w-28 text-gray-700">{{ $fmt::rupees($row['lifetimePaise']) }}</span>
                    </a>
                @endforeach
                {{-- Totals row --}}
                <div class="grid grid-cols-[1fr_auto_auto] gap-x-4 items-center px-4 py-3 bg-gray-50 border-t border-gray-200">
                    <span class="text-xs font-bold text-gray-700 uppercase tracking-wide">Total</span>
                    <span class="text-right w-24 font-bold text-gray-900">{{ $fmt::rupees((int) $monthTotal) }}</span>
                    <span class="text-right w-28 font-bold text-gray-900">{{ $fmt::rupees($lifetimeTotal) }}</span>
                </div>
            </div>
            <p class="text-[11px] text-gray-500 mt-3">Click any row to view detailed engine reports.</p>
        </div>
    </div>
</div>
