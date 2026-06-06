@extends('admin.layouts.admin')
@section('title', 'Settings')
@section('heading', 'Platform Settings')

@section('content')

@include('partials._toast-container')

<div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900 max-w-3xl">
    <p class="font-semibold mb-1">Platform settings</p>
    <p class="leading-relaxed">These values affect the whole arovolife platform and all users. Change with care; every change is audit-logged.</p>
</div>

<div class="max-w-3xl space-y-8">

    {{-- Placement rule (see also the Placement settings group below) --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 class="font-semibold text-gray-900">Placement rule</h2>
        <p class="text-sm text-gray-500 mt-1">
            Every new registrant arrives via a referral link carrying
            <code class="bg-gray-100 px-1 rounded text-xs">sponsor</code>,
            <code class="bg-gray-100 px-1 rounded text-xs">placement</code>, and an optional
            <code class="bg-gray-100 px-1 rounded text-xs">side</code>. By default (ADR-0003) the
            engine places exactly at <code class="bg-gray-100 px-1 rounded text-xs">placement.&lt;side&gt;</code>
            (left preferred, right the fallback) and a full target is rejected. When
            <strong>Binary spillover</strong> (Placement settings below) is enabled, a full target
            instead spills into the next open slot below it, using the selected
            <strong>fill strategy</strong> (ADR-0007).
        </p>
    </div>

    @foreach($grouped as $groupKey => $group)
    <section class="space-y-3">
        <header class="px-1">
            <h2 class="text-lg font-semibold text-gray-900">{{ $group['meta']['label'] }}</h2>
            @if(!empty($group['meta']['description']))
            <p class="text-sm text-gray-500 mt-0.5">{{ $group['meta']['description'] }}</p>
            @endif
        </header>

        <div class="space-y-3">
            @foreach($group['items'] as $item)
                @php
                    $key = $item['key'];
                    $meta = $item['meta'];
                    $value = $item['value'];
                    $readOnly = !empty($meta['read_only']);
                    $fieldId = 'setting_' . str_replace('.', '_', $key);
                    $errorKey = $meta['type'] === 'json' ? 'state_age_minimums' : 'value';
                    $hasError = $errors->has($errorKey) && session('saved_key') === $key;
                @endphp

                <div data-setting-card data-setting-key="{{ $key }}"
                     class="bg-white rounded-2xl border border-gray-200 p-5 sm:p-6 {{ $readOnly ? 'opacity-95' : '' }}">

                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <label for="{{ $fieldId }}" class="text-base font-semibold text-gray-900">{{ $meta['label'] }} <x-help-tip text="A platform-wide setting that applies to all users on arovolife; changes are audit-logged and versioned." /></label>
                                @if($readOnly)
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800">Read-only</span>
                                @endif
                                <code class="text-[11px] text-gray-400 font-mono break-all">{{ $key }}</code>
                            </div>
                            <p class="text-sm text-gray-600 mt-1.5">{{ $meta['description'] }}</p>

                            @if($readOnly && !empty($meta['read_only_reason']))
                                <p class="text-xs text-amber-800 mt-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                                    {{ $meta['read_only_reason'] }}
                                </p>
                            @endif
                        </div>

                        {{-- Input region: type-specific renderer. --}}
                        <div class="shrink-0 w-full sm:w-auto sm:min-w-[180px]">
                            @if($meta['type'] === 'bool')
                                @include('admin.settings._toggle', [
                                    'fieldId' => $fieldId,
                                    'key' => $key,
                                    'value' => $value,
                                    'readOnly' => $readOnly,
                                    'label' => $meta['label'],
                                ])

                            @elseif($meta['type'] === 'int')
                                <form method="POST" action="{{ route('admin.settings.update', $key) }}"
                                      data-setting-form class="flex items-center gap-2"
                                      data-confirm="Save the &lsquo;{{ $meta['label'] }}&rsquo; setting?"
                                      data-confirm-title="Confirm setting change"
                                      data-confirm-impact="Changes a platform-wide setting that affects all users on arovolife. The change is audit-logged and can be edited again later.">
                                    @csrf
                                    <input type="number"
                                           id="{{ $fieldId }}"
                                           name="value"
                                           value="{{ old('value', $value) }}"
                                           min="{{ $meta['min'] ?? '' }}"
                                           max="{{ $meta['max'] ?? '' }}"
                                           step="1"
                                           {{ $readOnly ? 'disabled' : '' }}
                                           class="w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-100 disabled:text-gray-500">
                                    <button type="submit"
                                            {{ $readOnly ? 'disabled' : '' }}
                                            class="px-3 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold disabled:bg-gray-300 disabled:cursor-not-allowed">
                                        Save
                                    </button>
                                </form>

                            @elseif($meta['type'] === 'enum')
                                <form method="POST" action="{{ route('admin.settings.update', $key) }}"
                                      data-setting-form class="flex items-center gap-2"
                                      data-confirm="Save the &lsquo;{{ $meta['label'] }}&rsquo; setting?"
                                      data-confirm-title="Confirm setting change"
                                      data-confirm-impact="Changes a platform-wide setting that affects all users on arovolife. The change is audit-logged and can be edited again later.">
                                    @csrf
                                    <select id="{{ $fieldId }}" name="value"
                                            {{ $readOnly ? 'disabled' : '' }}
                                            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-100">
                                        @foreach($meta['options'] ?? [] as $opt)
                                            <option value="{{ $opt['value'] }}" @selected($opt['value'] === $value)>{{ $opt['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit"
                                            {{ $readOnly ? 'disabled' : '' }}
                                            class="px-3 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold disabled:bg-gray-300 disabled:cursor-not-allowed">
                                        Save
                                    </button>
                                </form>

                                {{-- Per-option behaviour notes, so the admin sees what each choice does. --}}
                                @if(collect($meta['options'] ?? [])->contains(fn ($o) => ! empty($o['note'])))
                                <ul class="mt-2 space-y-1 text-xs text-gray-500 max-w-prose">
                                    @foreach($meta['options'] ?? [] as $opt)
                                        @if(! empty($opt['note']))
                                        <li class="{{ $opt['value'] === $value ? 'text-gray-700' : '' }}">
                                            <span class="font-semibold {{ $opt['value'] === $value ? 'text-brand-700' : 'text-gray-700' }}">{{ $opt['label'] }}@if($opt['value'] === $value) (current)@endif:</span>
                                            {{ $opt['note'] }}
                                        </li>
                                        @endif
                                    @endforeach
                                </ul>
                                @endif

                            @elseif($meta['type'] === 'json')
                                {{-- JSON settings still post to the legacy endpoint that knows
                                     how to validate the structure (state-code regex, age range). --}}
                                <form method="POST" action="{{ route('admin.settings.age-rules') }}"
                                      data-setting-form class="w-full sm:w-[260px]"
                                      data-confirm="Save the state age-minimum rules?"
                                      data-confirm-title="Confirm setting change"
                                      data-confirm-impact="Changes the per-state minimum-age rules platform-wide, affecting who can register on arovolife. The change is audit-logged and can be edited again later.">
                                    @csrf
                                    <textarea id="{{ $fieldId }}" name="state_age_minimums" rows="3" maxlength="2048"
                                              {{ $readOnly ? 'disabled' : '' }}
                                              class="w-full font-mono text-sm rounded-lg border border-gray-300 px-3 py-2 focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-100">{{ old('state_age_minimums', $value) }}</textarea>
                                    <button type="submit"
                                            {{ $readOnly ? 'disabled' : '' }}
                                            class="mt-2 w-full px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold disabled:bg-gray-300 disabled:cursor-not-allowed">
                                        Save
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>

                    @if($hasError)
                        <div class="mt-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">
                            {{ $errors->first($errorKey) }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
    @endforeach

    {{-- Advanced / engineer view — closed by default. Lets engineers see the
         raw key/value/version table when needed without cluttering the
         operator-facing view above. --}}
    <details class="bg-white rounded-2xl border border-gray-200 p-6">
        <summary class="font-semibold text-gray-800 cursor-pointer select-none">
            Show advanced settings (engineer view)
        </summary>
        <p class="text-xs text-gray-500 mt-2 mb-4">
            Raw <code class="bg-gray-100 px-1 rounded">settings</code> table contents.
            All edits flow through the friendly cards above; this view is read-only.
        </p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="text-left py-2 text-xs text-gray-500">Key</th>
                        <th class="text-left py-2 text-xs text-gray-500">Value</th>
                        <th class="text-left py-2 text-xs text-gray-500">Version</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($settings as $setting)
                    <tr>
                        <td class="py-2 pr-3 font-mono text-xs text-gray-600 break-all">{{ $setting->key }}</td>
                        <td class="py-2 pr-3 font-mono text-xs text-brand-600 break-all">{{ $setting->value }}</td>
                        <td class="py-2 text-xs text-gray-400">v{{ $setting->version }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>

@push('scripts')
<script>
    // Surface a success toast when a setting was just saved (server set the
    // 'saved_key' flash key). The session('status') banner from the layout
    // also fires; the toast is the lighter-weight confirmation per the
    // settings-redesign spec.
    @if(session('saved_key'))
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof window.showToast === 'function') {
            window.showToast(@json(session('status', 'Saved.')), 'success');
        }
    });
    @endif

    // Toggle behaviour: clicking a boolean toggle posts immediately. The
    // hidden <input name="value"> carries the new state so the controller
    // can read it without depending on checkbox semantics.
    document.querySelectorAll('[data-toggle-switch]').forEach((btn) => {
        if (btn.disabled) return;
        btn.addEventListener('click', () => {
            const form = btn.closest('form');
            const input = form.querySelector('input[name="value"]');
            const currentlyOn = btn.getAttribute('aria-checked') === 'true';
            input.value = currentlyOn ? 'false' : 'true';
            // requestSubmit() fires a real (cancelable) submit event so the
            // global confirmation modal can intercept it; plain .submit() would
            // bypass the modal. The modal submits the form on confirm.
            form.requestSubmit();
        });
    });
</script>
@endpush

@endsection
