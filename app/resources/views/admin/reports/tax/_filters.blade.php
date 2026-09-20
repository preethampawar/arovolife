{{--
    Shared filter bar for every statutory report. One file so the five views
    cannot drift into accepting different periods — a GST summary and its
    register only reconcile if they were asked the same question.
--}}
<form method="GET" class="flex flex-wrap items-end gap-3 mb-4">
    <label class="block">
        <span class="block text-xs text-gray-700 mb-1 font-medium">Month</span>
        <input type="month" name="month" value="{{ $period->monthValue() }}"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
    </label>
    <label class="block">
        <span class="block text-xs text-gray-700 mb-1 font-medium">
            Quarter
            <x-help-tip text="Indian financial year: Q1 is April–June, Q2 July–September, Q3 October–December and Q4 January–March of the following calendar year. Pick a quarter and it overrides the month. Leave it blank and the report runs on the month; with neither set it runs on the last completed month." />
        </span>
        <select name="quarter"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            <option value="">Use month</option>
            @foreach ($quarterOptions as $value => $label)
                <option value="{{ $value }}" @selected($period->quarterValue() === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>

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

<p class="text-sm text-gray-600 -mt-2 mb-4">
    Reporting <span class="font-medium text-gray-900">{{ $period->label }}</span>
    ({{ $period->from->format('d M Y') }} – {{ $period->to->format('d M Y') }}).
</p>
