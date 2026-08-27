@extends('admin.layouts.admin')
@section('title', 'Feature flags')
@section('heading', 'Feature flags')

@section('content')
<div class="max-w-3xl">
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold mb-1">Feature flags</p>
        <p class="leading-relaxed">Runtime switches that enable or disable platform features. Some (like the registration killswitch) have wide impact; every toggle is audit-logged.</p>
        <p class="leading-relaxed mt-2">
            <span class="font-semibold">Bonus dependency hierarchy</span> — the compensation engines build on each other, so switch them on in this order:
            <span class="font-medium">Genos Sales Bonus</span> first (its daily cut-off produces the slab achievements that Mentorship, Growth Booster and Fortune consume, and the repurchase engine applies its holds there), then
            <span class="font-medium">Rank Bonus</span> (its rank:check run writes the rank qualifications that Growth Booster's prior-month-rank exclusion, Fortune's tier gates and Lifetime Awards all read).
            A dependent bonus left ON without its prerequisites computes on missing data — e.g. with Rank Bonus OFF, Growth Booster treats every distributor as never ranked. Arete Development Center Bonus is independent.
            Each card below lists its prerequisites.
        </p>
    </div>

    <p class="text-sm text-gray-600 mb-6">
        Runtime toggles for rollouts and killswitches. Every change writes an
        <code class="px-1 rounded bg-gray-100 text-gray-800">audit_log</code>
        entry of action
        <code class="px-1 rounded bg-gray-100 text-gray-800">feature_flag.toggled</code>.
    </p>

    {{-- Status flash is rendered once by the admin layout. --}}

    <div class="space-y-4">
        @foreach ($flags as $key => $flag)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 flex items-start justify-between">
                <div class="pr-4">
                    <div class="flex items-center gap-2 mb-1">
                        <code class="text-xs text-gray-600">{{ $key }}</code>
                        @if ($flag['active'])
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-800">Active</span>
                        @else
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800">Inactive</span>
                        @endif
                    </div>
                    <div class="text-base font-medium text-gray-900">{{ $flag['label'] }} <x-help-tip text="Toggling this flag enables or disables the feature platform-wide for all users on arovolife; the change is audit-logged and reversible." /></div>
                    <div class="text-sm text-gray-600 mt-1">{{ $flag['description'] }}</div>
                    @if (! empty($flag['requires']))
                        <div class="text-xs mt-2 flex flex-wrap items-center gap-1.5">
                            <span class="text-gray-600 font-medium">Requires:</span>
                            @foreach ($flag['requires'] as $reqKey)
                                @php $req = $flags[$reqKey] ?? null; @endphp
                                @if ($req !== null)
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium {{ $req['active'] ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">
                                        {{ $req['label'] }} — {{ $req['active'] ? 'ON' : 'OFF' }}
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
                <form method="POST" action="{{ route('admin.feature-flags.toggle', $key) }}" class="shrink-0"
                    data-confirm="{{ $flag['active'] ? 'Deactivate' : 'Activate' }} the &lsquo;{{ $flag['label'] }}&rsquo; flag?"
                    data-confirm-title="Confirm feature flag change"
                    data-confirm-changes='[{"label":"State","from":"{{ $flag['active'] ? 'Active' : 'Inactive' }}","to":"{{ $flag['active'] ? 'Inactive' : 'Active' }}"}]'
                    data-confirm-impact="{{ $flag['active'] ? 'Disables' : 'Enables' }} this feature platform-wide for all users on arovolife. The change is audit-logged and reversible by toggling it back.">
                    @csrf
                    @if ($flag['active'])
                        <input type="hidden" name="action" value="deactivate">
                        <button type="submit" class="px-3 py-1.5 text-sm rounded-md bg-amber-600 text-white hover:bg-amber-700 font-semibold transition-colors">
                            Deactivate
                        </button>
                    @else
                        <input type="hidden" name="action" value="activate">
                        <button type="submit" class="px-3 py-1.5 text-sm rounded-md bg-green-600 text-white hover:bg-green-700 font-semibold transition-colors">
                            Activate
                        </button>
                    @endif
                </form>
            </div>
        @endforeach
    </div>
</div>
@endsection
