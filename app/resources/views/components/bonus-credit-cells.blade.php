@props([
    'gross',
    'credited',
    'deduction' => 0,
    'repurchase' => true,
    'dashWhenZero' => false,
    'isCredited' => true,
    'tdClass' => 'px-4 py-2 text-right font-mono',
])
@php
    $inr = fn (int $paise): string => '₹'.\App\Modules\Shared\Support\IndianNumber::format($paise / 100, 2);
    $show = fn (int $paise): string => ($dashWhenZero && $paise === 0) ? '—' : $inr($paise);
@endphp
{{-- Body cells for a bonus result row; pairs with <x-bonus-credit-head>. Values are paise ints
     read straight off the stored result row — the page never re-derives the deduction. --}}
<td {{ $attributes->merge(['class' => $tdClass.' text-gray-700']) }}>{{ $show((int) $gross) }}{{ $slot }}</td>
@if($repurchase)
<td class="{{ $tdClass }} {{ (int) $deduction > 0 ? 'text-red-600' : 'text-gray-500' }}">{{ (int) $deduction > 0 ? '-'.$inr((int) $deduction) : '—' }}</td>
@endif
<td class="{{ $tdClass }} font-semibold {{ $isCredited && (int) $credited > 0 ? 'text-green-700' : 'text-gray-600' }}">{{ $isCredited ? $show((int) $credited) : '—' }}</td>
