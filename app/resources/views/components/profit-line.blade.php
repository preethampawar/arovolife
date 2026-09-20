@props([
    'label',
    'value',
    'tip' => null,
    'note' => null,
    'strong' => false,
    'tone' => 'default',
])

{{--
    One line of the trading account. A component rather than repeated markup
    because the summary has twenty-odd of these and they must all align, and
    because a subtotal row differing from a detail row by a stray class is the
    kind of thing that makes a financial statement look untrustworthy.

    `tone` says what kind of money the row is: `muted` for a memo that is not
    part of any chain, `liability` for money the company is holding for
    somebody else. `note` puts the explanation on the page under the label
    rather than behind a hover — on the company snapshots, reading the
    statement correctly depends on knowing whose money each row is, and a
    tooltip nobody opens does not tell them.
--}}
@php
    $rowClass = $strong ? 'bg-gray-50 font-semibold text-gray-900' : ($tone === 'muted' ? 'text-gray-500' : 'text-gray-700');
    $valueClass = $tone === 'liability' ? 'text-amber-700' : ($tone === 'muted' ? 'text-gray-500' : '');
@endphp

<tr class="{{ $rowClass }}">
    <td class="px-4 py-2.5">
        <span>{{ $label }}</span>@if ($tip)<x-help-tip :text="$tip" />@endif
        @if ($note)
            <p class="mt-0.5 text-xs text-gray-500 font-normal">{{ $note }}</p>
        @endif
    </td>
    <td class="px-4 py-2.5 text-right font-mono whitespace-nowrap {{ $valueClass }}">{{ $value }}</td>
</tr>
