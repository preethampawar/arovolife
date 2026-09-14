{{--
    Shared list-page filter toolbar.

    Renders a ListFilters instance as a labelled GET form plus a row of
    removable chips for whatever is currently in force. Every control carries a
    real <label>, so tests address it with getByLabel() and screen readers get
    a name; the form itself, Apply, Clear and each chip carry data-testid
    hooks so a spec never has to select on Tailwind classes.

    Light-first utilities only — the admin dark theme is applied globally in
    resources/css/app.css as `html.dark .bg-white { … }` overrides, so a
    `dark:` variant here would fight it.

    @see \App\Modules\Shared\Support\ListFilters
    @see docs/plans/list-page-filters-2026-09-13.md
--}}
@props([
    'filters',
    'action' => null,
    'exports' => [],
])

@php
    use App\Modules\Shared\Support\FilterField;

    $formAction = $action ?? url()->current();
    $controlClass = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500';
    $labelClass = 'text-xs font-medium text-gray-600';
@endphp

<form method="GET" action="{{ $formAction }}" data-testid="filter-bar"
      class="mb-4 flex flex-wrap items-end gap-3">

    {{-- Non-filter state (a tab selection, say) must survive pressing Filter. --}}
    @foreach($filters->carriedParams() as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach

    @foreach($filters->fields as $field)
        @switch($field->type)

            @case(FilterField::TYPE_TEXT)
                <label class="flex flex-col gap-1">
                    <span class="{{ $labelClass }}">{{ $field->label }}</span>
                    <input type="search" name="{{ $field->key }}"
                           value="{{ $filters->value($field->key) }}"
                           placeholder="{{ $field->placeholder }}"
                           class="{{ $controlClass }} min-w-56">
                </label>
                @break

            @case(FilterField::TYPE_SELECT)
                <label class="flex flex-col gap-1">
                    <span class="{{ $labelClass }}">{{ $field->label }}</span>
                    <select name="{{ $field->key }}" class="{{ $controlClass }}">
                        <option value="">{{ $field->placeholder }}</option>
                        @foreach($field->options as $value => $label)
                            <option value="{{ $value }}" @selected($filters->value($field->key) === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                @break

            @case(FilterField::TYPE_DATE_RANGE)
                <label class="flex flex-col gap-1">
                    <span class="{{ $labelClass }}">{{ $field->label }} from</span>
                    <input type="date" name="{{ $field->fromKey }}"
                           value="{{ $filters->value($field->fromKey) }}"
                           class="{{ $controlClass }}">
                </label>
                <label class="flex flex-col gap-1">
                    <span class="{{ $labelClass }}">{{ $field->label }} to</span>
                    <input type="date" name="{{ $field->toKey }}"
                           value="{{ $filters->value($field->toKey) }}"
                           class="{{ $controlClass }}">
                </label>
                @break

            @case(FilterField::TYPE_MONTH)
                <label class="flex flex-col gap-1">
                    <span class="{{ $labelClass }}">{{ $field->label }}</span>
                    <input type="month" name="{{ $field->key }}"
                           value="{{ $filters->value($field->key) }}"
                           class="{{ $controlClass }}">
                </label>
                @break

            @case(FilterField::TYPE_BOOLEAN)
                <label class="flex items-center gap-2 py-2">
                    <input type="checkbox" name="{{ $field->key }}" value="1"
                           @checked($filters->has($field->key))
                           class="rounded border-gray-300 text-brand-700 focus:ring-brand-500">
                    <span class="text-sm text-gray-700">{{ $field->label }}</span>
                </label>
                @break

        @endswitch
    @endforeach

    {{-- These were the console's one remaining slate-900 button, which made
         the busiest control on 39 list pages a different colour from every
         other primary action in the product. The data-testid hooks pass
         straight through the component to the element. --}}
    <x-ui.button type="submit" data-testid="filter-apply" icon="list-filter">Filter</x-ui.button>

    @if($filters->any())
        <x-ui.button :href="$filters->clearUrl()" variant="secondary" data-testid="filter-clear" icon="x">Clear</x-ui.button>
    @endif

    @foreach($exports as $export)
        <x-ui.button :href="$export['url']" variant="secondary" data-testid="filter-export" icon="download">
            {{ $export['label'] }}
        </x-ui.button>
    @endforeach

    {{ $slot }}
</form>

@if($filters->any())
    <div data-testid="filter-chips" class="mb-4 flex flex-wrap items-center gap-2">
        <span class="text-xs text-gray-500">Filtered by</span>
        @foreach($filters->chips() as $chip)
            <a href="{{ $chip['url'] }}" data-testid="filter-chip" data-filter-key="{{ $chip['key'] }}"
               class="inline-flex items-center gap-1.5 rounded-full border border-gray-300 bg-white px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">
                <span class="text-gray-500">{{ $chip['label'] }}:</span>
                <span class="font-medium">{{ $chip['display'] }}</span>
                <span aria-hidden="true" class="text-gray-400">{{ svg('lucide-x', 'w-3 h-3') }}</span>
                <span class="sr-only">Remove this filter</span>
            </a>
        @endforeach
    </div>
@endif
