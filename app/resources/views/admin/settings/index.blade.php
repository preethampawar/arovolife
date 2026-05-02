@extends('admin.layouts.admin')
@section('title', 'Settings')
@section('heading', 'Platform Settings')

@section('content')

<div class="max-w-2xl space-y-6">

    {{-- Placement rule (read-only — invariant per ADR-0003) --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-1">Placement rule</h3>
        <p class="text-xs text-gray-500 mb-3">
            Placement is fixed by ADR-0003: every new joiner arrives via a referral link
            carrying <code class="bg-gray-100 px-1 rounded">sponsor</code>,
            <code class="bg-gray-100 px-1 rounded">placement</code>, and an optional
            <code class="bg-gray-100 px-1 rounded">side</code>. The engine places exactly
            at <code class="bg-gray-100 px-1 rounded">placement.&lt;side&gt;</code>;
            when no side is given, left is preferred and right is the fallback. There is
            no spine walk and no admin-tunable strategy.
        </p>
    </div>

    {{-- State-aware age rule (US-1.12) --}}
    @php
        $ageRow  = $settings['compliance.state_age_minimums'] ?? null;
        $ageJson = $ageRow ? $ageRow->value : '{"MH":21}';
    @endphp
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-1">State-wise minimum age</h3>
        <p class="text-xs text-gray-500 mb-4">
            JSON map of state code → minimum age. Any state not listed defaults to 18.
            Example: <code class="bg-gray-100 px-1 rounded">{"MH":21}</code>.
        </p>

        @if($errors->has('state_age_minimums'))
        <div class="rounded-lg bg-red-50 border border-red-200 p-3 mb-4 text-sm text-red-700">
            {{ $errors->first('state_age_minimums') }}
        </div>
        @endif

        <form method="POST" action="{{ route('admin.settings.age-rules') }}">
            @csrf
            <textarea name="state_age_minimums" rows="3" maxlength="2048"
                class="w-full font-mono text-sm rounded-lg border border-gray-300 px-3 py-2 focus:border-brand-500 focus:ring-brand-500">{{ old('state_age_minimums', $ageJson) }}</textarea>

            <button type="submit"
                class="mt-3 px-6 py-2.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Save Age Rules
            </button>
        </form>
    </div>

    {{-- Read-only info --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-3">All Settings</h3>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200">
                    <th class="text-left py-2 text-xs text-gray-500">Key</th>
                    <th class="text-left py-2 text-xs text-gray-500">Value</th>
                    <th class="text-left py-2 text-xs text-gray-500">Version</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @foreach($settings as $setting)
                <tr>
                    <td class="py-2 font-mono text-xs text-gray-600">{{ $setting->key }}</td>
                    <td class="py-2 font-mono text-xs text-brand-600">{{ $setting->value }}</td>
                    <td class="py-2 text-xs text-gray-400">v{{ $setting->version }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection
