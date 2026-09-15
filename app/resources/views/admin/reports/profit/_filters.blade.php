{{--
    Shared filter bar for every profit view. One file so the four reports
    cannot drift into accepting different filters — the figures only reconcile
    with each other if they were asked the same question.
--}}
<form method="GET" class="flex flex-wrap items-end gap-3 mb-4">
    <label class="block">
        <span class="block text-xs text-gray-700 mb-1 font-medium">From</span>
        <input type="date" name="date_from" value="{{ $dateFrom }}"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
    </label>
    <label class="block">
        <span class="block text-xs text-gray-700 mb-1 font-medium">To</span>
        <input type="date" name="date_to" value="{{ $dateTo }}"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
    </label>
    <label class="block">
        <span class="block text-xs text-gray-700 mb-1 font-medium">
            Count a sale on its
            <x-help-tip text="Shipped date is the accounting answer: revenue is recognised when goods leave, and that is also when their cost was taken out of stock, so profit and cost always land in the same period. Order date answers a different question — what was ordered in this window — and will include orders that have not shipped yet, which have revenue but no cost." />
        </span>
        <select name="basis"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            @foreach ($bases as $value => $label)
                <option value="{{ $value }}" @selected($basis === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    @isset($warehouses)
        <label class="block">
            <span class="block text-xs text-gray-700 mb-1 font-medium">Warehouse</span>
            <select name="warehouse_code"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <option value="">All warehouses</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->code }}" @selected($warehouseCode === $warehouse->code)>{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </label>
    @endisset

    <x-ui.button type="submit">Apply</x-ui.button>
    <a href="{{ route('admin.reports.profit.'.$slug) }}" class="text-sm text-gray-600 hover:text-gray-900 py-2">Clear</a>

    <div class="ml-auto flex items-center gap-3">
        <a href="{{ request()->fullUrlWithQuery(['format' => 'xlsx']) }}"
            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
            ↓ Download Excel
        </a>
        <a href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}"
            class="text-sm text-gray-600 hover:text-gray-900">CSV</a>
    </div>
</form>
