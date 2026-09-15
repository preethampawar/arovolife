@props([
    'label',
    'value',
    'tip' => null,
    'strong' => false,
])

{{--
    One line of the trading account. A component rather than repeated markup
    because the summary has twenty-odd of these and they must all align, and
    because a subtotal row differing from a detail row by a stray class is the
    kind of thing that makes a financial statement look untrustworthy.
--}}
<tr class="{{ $strong ? 'bg-gray-50 font-semibold text-gray-900' : 'text-gray-700' }}">
    <td class="px-4 py-2.5">
        <span>{{ $label }}</span>@if ($tip)<x-help-tip :text="$tip" />@endif
    </td>
    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap">{{ $value }}</td>
</tr>
