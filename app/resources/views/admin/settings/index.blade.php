@extends('admin.layouts.admin')
@section('title', 'Settings')
@section('heading', 'Platform Settings')

@php
use App\Modules\Shared\Support\IndianNumber;

$formatSettingDisplay = static function (string $rawValue, array $meta): ?string {
    if ($meta['type'] !== 'int') { return null; }
    $unit = $meta['display_unit'] ?? null;
    if ($unit === null) { return null; }
    $n = (int) $rawValue;
    if ($unit === 'rupees') {
        return IndianNumber::rupees($n, 0);
    }
    if ($unit === 'bv') {
        return IndianNumber::format($n / 100, 0) . ' BV';
    }
    return null;
};
@endphp

@section('content')

@include('partials._toast-container')

<div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900 max-w-3xl">
    <p class="font-semibold mb-1">Platform settings</p>
    <p>These values affect the whole arovolife platform and all users. Every change is audit-logged. Monetary values marked <strong>edit in paise</strong> store 100 paise per ₹1 — the current ₹ equivalent is shown below each field.</p>
</div>

<div class="max-w-3xl space-y-4">

    @foreach($grouped as $groupKey => $group)
    @php
        $groupHasError = collect($group['items'])->contains(function($item) use ($errors) {
            $errorKey = $item['meta']['type'] === 'json' ? 'state_age_minimums' : 'value';
            return $errors->has($errorKey) && session('saved_key') === $item['key'];
        });
        $groupHasSaved = collect($group['items'])->contains(fn($item) => session('saved_key') === $item['key']);
        $autoOpen = $groupHasError || $groupHasSaved;
    @endphp

    <details {{ $autoOpen ? 'open' : '' }} class="group bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <summary class="flex items-center justify-between px-5 py-4 cursor-pointer select-none list-none hover:bg-gray-50 transition-colors">
            <div>
                <h2 class="text-base font-semibold text-gray-900">{{ $group['meta']['label'] }}</h2>
                @if(!empty($group['meta']['description']))
                    <p class="text-xs text-gray-500 mt-0.5 leading-snug">{{ $group['meta']['description'] }}</p>
                @endif
            </div>
            <div class="flex items-center gap-2 ml-4 shrink-0">
                <span class="text-xs text-gray-400">{{ count($group['items']) }} {{ Str::plural('setting', count($group['items'])) }}</span>
                <x-lucide-chevron-down class="w-4 h-4 text-gray-400 transition-transform group-open:rotate-180" />
            </div>
        </summary>

        <div class="divide-y divide-gray-100 border-t border-gray-100">
            @foreach($group['items'] as $item)
                @php
                    $key = $item['key'];
                    $meta = $item['meta'];
                    $value = $item['value'];
                    $readOnly = !empty($meta['read_only']);
                    $fieldId = 'setting_' . str_replace('.', '_', $key);
                    $errorKey = $meta['type'] === 'json' ? 'state_age_minimums' : 'value';
                    $hasError = $errors->has($errorKey) && session('saved_key') === $key;
                    $displayValue = $formatSettingDisplay($value, $meta);
                @endphp

                <div data-setting-card data-setting-key="{{ $key }}"
                     class="px-5 py-4 {{ $readOnly ? 'bg-gray-50/50' : 'bg-white' }}">

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        {{-- Label + description as tooltip --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <label for="{{ $fieldId }}" class="text-sm font-medium text-gray-900 leading-tight">{{ $meta['label'] }}</label>
                                <x-help-tip :text="($meta['impact'] ?? null) ? $meta['description'] . ' — ' . $meta['impact'] : $meta['description']" />
                                @if($readOnly)
                                    <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium bg-amber-100 text-amber-800">Read-only</span>
                                @endif
                            </div>
                            @if($readOnly && !empty($meta['read_only_reason']))
                                <p class="text-xs text-amber-700 mt-1">{{ $meta['read_only_reason'] }}</p>
                            @endif
                        </div>

                        {{-- Input region --}}
                        <div class="shrink-0">
                            @if($meta['type'] === 'bool')
                                @include('admin.settings._toggle', [
                                    'fieldId' => $fieldId,
                                    'key' => $key,
                                    'value' => $value,
                                    'readOnly' => $readOnly,
                                    'label' => $meta['label'],
                                ])

                            @elseif($meta['type'] === 'int')
                                <div class="flex flex-col items-end gap-1">
                                    <form method="POST" action="{{ route('admin.settings.update', $key) }}"
                                          data-setting-form @if(!$readOnly) data-editable @endif class="flex items-center gap-2"
                                          data-confirm="Save the &lsquo;{{ $meta['label'] }}&rsquo; setting?"
                                          data-confirm-title="Confirm setting change"
                                          data-confirm-impact="Changes a platform-wide setting that affects all users on arovolife. The change is audit-logged and can be edited again later.">
                                        @csrf
                                        <input type="number"
                                               id="{{ $fieldId }}"
                                               name="value"
                                               data-field-label="{{ $meta['label'] }}"
                                               value="{{ old('value', $value) }}"
                                               min="{{ $meta['min'] ?? '' }}"
                                               max="{{ $meta['max'] ?? '' }}"
                                               step="1"
                                               {{ $readOnly ? 'disabled' : '' }}
                                               class="w-28 rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-right focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-100 disabled:text-gray-500 font-mono">
                                        <button type="submit"
                                                {{ $readOnly ? 'disabled' : '' }}
                                                class="px-3 py-1.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold disabled:bg-gray-300 disabled:cursor-not-allowed">
                                            Save
                                        </button>
                                    </form>
                                    @if($displayValue !== null)
                                        <span class="text-xs text-gray-500 font-medium">= {{ $displayValue }}</span>
                                    @endif
                                </div>

                            @elseif($meta['type'] === 'string')
                                <form method="POST" action="{{ route('admin.settings.update', $key) }}"
                                      data-setting-form @if(!$readOnly) data-editable @endif class="flex items-center gap-2"
                                      data-confirm="Save the &lsquo;{{ $meta['label'] }}&rsquo; setting?"
                                      data-confirm-title="Confirm setting change"
                                      data-confirm-impact="Changes a platform-wide setting that affects all users on arovolife. The change is audit-logged and can be edited again later.">
                                    @csrf
                                    <input type="{{ match($meta['format'] ?? '') { 'email' => 'email', 'date' => 'date', default => 'text' } }}"
                                           id="{{ $fieldId }}"
                                           name="value"
                                           data-field-label="{{ $meta['label'] }}"
                                           value="{{ old('value', $value) }}"
                                           maxlength="{{ $meta['max'] ?? 255 }}"
                                           {{ $readOnly ? 'disabled' : '' }}
                                           class="w-60 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-100 disabled:text-gray-500">
                                    <button type="submit"
                                            {{ $readOnly ? 'disabled' : '' }}
                                            class="px-3 py-1.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold disabled:bg-gray-300 disabled:cursor-not-allowed">
                                        Save
                                    </button>
                                </form>

                            @elseif($meta['type'] === 'enum')
                                <div class="space-y-2">
                                    <form method="POST" action="{{ route('admin.settings.update', $key) }}"
                                          data-setting-form @if(!$readOnly) data-editable @endif class="flex items-center gap-2"
                                          data-confirm="Save the &lsquo;{{ $meta['label'] }}&rsquo; setting?"
                                          data-confirm-title="Confirm setting change"
                                          data-confirm-impact="Changes a platform-wide setting that affects all users on arovolife. The change is audit-logged and can be edited again later.">
                                        @csrf
                                        <select id="{{ $fieldId }}" name="value"
                                                data-field-label="{{ $meta['label'] }}"
                                                {{ $readOnly ? 'disabled' : '' }}
                                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-100">
                                            @foreach($meta['options'] ?? [] as $opt)
                                                <option value="{{ $opt['value'] }}" @selected($opt['value'] === $value)>{{ $opt['label'] }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit"
                                                {{ $readOnly ? 'disabled' : '' }}
                                                class="px-3 py-1.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold disabled:bg-gray-300 disabled:cursor-not-allowed">
                                            Save
                                        </button>
                                    </form>

                                    @if(collect($meta['options'] ?? [])->contains(fn ($o) => ! empty($o['note'])))
                                    <ul class="space-y-0.5 text-xs text-gray-500 max-w-xs">
                                        @foreach($meta['options'] ?? [] as $opt)
                                            @if(! empty($opt['note']))
                                            <li class="{{ $opt['value'] === $value ? 'text-gray-700 font-medium' : '' }}">
                                                <span class="{{ $opt['value'] === $value ? 'text-brand-700' : 'text-gray-600' }}">{{ $opt['label'] }}@if($opt['value'] === $value) ✓@endif:</span>
                                                {{ $opt['note'] }}
                                            </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                    @endif
                                </div>

                            @elseif($meta['type'] === 'json')
                                <form method="POST" action="{{ route('admin.settings.age-rules') }}"
                                      data-setting-form @if(!$readOnly) data-editable @endif class="w-full sm:w-56"
                                      data-confirm="Save the state age-minimum rules?"
                                      data-confirm-title="Confirm setting change"
                                      data-confirm-impact="Changes the per-state minimum-age rules platform-wide, affecting who can register on arovolife. The change is audit-logged and can be edited again later.">
                                    @csrf
                                    <textarea id="{{ $fieldId }}" name="state_age_minimums" data-field-label="State age minimums" rows="3" maxlength="2048"
                                              {{ $readOnly ? 'disabled' : '' }}
                                              class="w-full font-mono text-xs rounded-lg border border-gray-300 px-3 py-2 focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-100">{{ old('state_age_minimums', $value) }}</textarea>
                                    <button type="submit"
                                            {{ $readOnly ? 'disabled' : '' }}
                                            class="mt-1 w-full px-4 py-1.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold disabled:bg-gray-300 disabled:cursor-not-allowed">
                                        Save
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>

                    @if($hasError)
                        <div class="mt-2 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs text-red-700">
                            {{ $errors->first($errorKey) }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </details>
    @endforeach

    {{-- Engineer raw view --}}
    @if($settings->isNotEmpty())
    <details class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <summary class="flex items-center justify-between px-5 py-4 cursor-pointer select-none list-none hover:bg-gray-50 transition-colors group">
            <div>
                <h2 class="text-base font-semibold text-gray-900">Raw settings table</h2>
                <p class="text-xs text-gray-500 mt-0.5">All edits flow through the cards above — this is read-only.</p>
            </div>
            <x-lucide-chevron-down class="w-4 h-4 text-gray-400 transition-transform group-open:rotate-180 shrink-0" />
        </summary>
        <div class="border-t border-gray-100 overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b border-gray-200 bg-gray-50">
                        <th class="text-left px-5 py-2 text-gray-500 font-medium">Key</th>
                        <th class="text-left px-5 py-2 text-gray-500 font-medium">Value</th>
                        <th class="text-left px-5 py-2 text-gray-500 font-medium">Ver</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($settings as $setting)
                    <tr>
                        <td class="px-5 py-2 font-mono text-gray-500 break-all">{{ $setting->key }}</td>
                        <td class="px-5 py-2 font-mono text-brand-700 break-all">{{ $setting->value }}</td>
                        <td class="px-5 py-2 text-gray-400">v{{ $setting->version }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
    @endif
</div>

@push('scripts')
<script>
    @if(session('saved_key'))
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof window.showToast === 'function') {
            window.showToast(@json(session('status', 'Saved.')), 'success');
        }
        // Scroll saved card into view
        const card = document.querySelector('[data-setting-key="{{ session('saved_key') }}"]');
        if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    @endif

    document.querySelectorAll('[data-toggle-switch]').forEach((btn) => {
        if (btn.disabled) return;
        btn.addEventListener('click', () => {
            const form = btn.closest('form');
            const input = form.querySelector('input[name="value"]');
            const currentlyOn = btn.getAttribute('aria-checked') === 'true';
            input.value = currentlyOn ? 'false' : 'true';
            const label = btn.getAttribute('data-toggle-label') || 'Setting';
            form.dataset.confirmChanges = JSON.stringify([
                { label, from: currentlyOn ? 'On' : 'Off', to: currentlyOn ? 'Off' : 'On' },
            ]);
            form.requestSubmit();
        });
    });
</script>
@endpush

@endsection
