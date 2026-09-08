@props(['status', 'compact' => false])

<div>
    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium {{ $status->pillClasses() }}">
        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
        {{ $status->label() }}
    </span>
    <x-help-tip text="Your repurchase wallet must stand at ₹0 on two dates: the last day of your own 30-day repurchase window, and the last instant of every calendar month, which the monthly bonus engines check. This reminder counts down to whichever comes first. Spend the balance at checkout on your own repurchase. Green: more than 20 days left. Amber: 20 days or fewer. Red: 10 days or fewer, or a window already missed." />
    @unless($compact)
    <p class="text-[11px] text-gray-600 mt-1">{{ $status->detail() }}</p>
    @endunless
</div>
