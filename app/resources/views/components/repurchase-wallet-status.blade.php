@props(['status', 'compact' => false])

<div>
    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium {{ $status->pillClasses() }}">
        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
        {{ $status->label() }}
    </span>
    <x-help-tip text="Your repurchase wallet must stand at ₹0 at the end of the month — the balance is frozen on the 1st and the bonus engines check it, the daily Genos Sales Bonus included. Spend it at checkout on your monthly repurchase. Green: 1st–10th. Amber: 11th–20th. Red: 21st to month end." />
    @unless($compact)
    <p class="text-[11px] text-gray-600 mt-1">{{ $status->detail() }}</p>
    @endunless
</div>
