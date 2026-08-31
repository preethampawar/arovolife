@extends('layouts.app')
@section('title', 'Arete Development Centres')

@section('content')
@php
    use App\Modules\Compensation\Models\AreteCenter;
    $inp = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500';
    $lbl = 'block text-xs font-medium text-gray-600 mb-1';
@endphp
<div class="max-w-6xl">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Arete Development Centres</h1>
        <p class="text-sm text-gray-600">Find an Arete Development Centre near you — training, product demonstration and distributor support centres across the country.</p>
    </div>

    {{-- Form-purpose note (platform convention). --}}
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        Use the filters to narrow the list by centre, state or city. Contact numbers are for arovolife distributors only — please do not share them outside the network.
        Centres are not shops: products are not sold over the counter there.
    </div>

    <form method="GET" class="mb-6 grid grid-cols-1 sm:grid-cols-4 gap-4 rounded-2xl border border-gray-200 bg-white p-4">
        <div>
            <label class="{{ $lbl }}" for="f-centre">Centre <x-help-tip text="Pick one centre, or leave as “All” to list every active centre." /></label>
            <select id="f-centre" name="centre" class="{{ $inp }}">
                <option value="">All centres</option>
                @foreach($centreOptions as $option)
                <option value="{{ $option->id }}" @selected($filters['centre'] === $option->id)>{{ $option->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="{{ $lbl }}" for="f-state">State / UT</label>
            <select id="f-state" name="state" class="{{ $inp }}" onchange="this.form.city.value=''; this.form.submit()">
                <option value="">All states</option>
                @foreach($stateOptions as $stateName)
                <option value="{{ $stateName }}" @selected($filters['state'] === $stateName)>{{ $stateName }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="{{ $lbl }}" for="f-city">City <x-help-tip text="Choose a state first to narrow the city list." /></label>
            <select id="f-city" name="city" class="{{ $inp }}">
                <option value="">All cities</option>
                @foreach($cityOptions as $cityName)
                <option value="{{ $cityName }}" @selected($filters['city'] === $cityName)>{{ $cityName }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-3">
            <button type="submit" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800">Search</button>
            <a href="{{ route('my.adc.directory') }}" class="text-sm text-gray-600 hover:text-gray-800">Reset</a>
        </div>
    </form>

    @if($centers->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
            <p class="text-gray-900 font-medium mb-1">No centres match these filters.</p>
            <p class="text-sm text-gray-600">Try a different state or city, or reset the filters.</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-600">
                    <tr>
                        <th class="px-4 py-3">S.No</th>
                        <th class="px-4 py-3">City</th>
                        <th class="px-4 py-3">Centre name</th>
                        <th class="px-4 py-3">Weekly off</th>
                        <th class="px-4 py-3">Contact person</th>
                        <th class="px-4 py-3">Contact number</th>
                        <th class="px-4 py-3">Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($centers as $i => $center)
                    <tr>
                        <td class="px-4 py-3 text-gray-500">{{ $i + 1 }}</td>
                        <td class="px-4 py-3">{{ $center->city ?: '—' }}@if($center->state)<span class="block text-xs text-gray-500">{{ $center->state }}</span>@endif</td>
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $center->name }}
                            @if($center->opening_time && $center->closing_time)<span class="block text-xs text-gray-500">{{ substr((string) $center->opening_time, 0, 5) }} – {{ substr((string) $center->closing_time, 0, 5) }}</span>@endif
                        </td>
                        <td class="px-4 py-3">{{ AreteCenter::WEEKLY_OFF_OPTIONS[$center->weekly_off] ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $center->contact_person ?: '—' }}</td>
                        <td class="px-4 py-3 font-mono">
                            @if($center->contact_number)<a href="tel:{{ $center->contact_number }}" class="text-brand-700 hover:text-brand-800">{{ $center->contact_number }}</a>@else — @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700">
                            {{ collect([$center->address_line_1, $center->address_line_2, $center->landmark])->filter()->join(', ') ?: ($center->location ?: '—') }}
                            @if($center->pincode)<span class="block text-xs text-gray-500">PIN {{ $center->pincode }}</span>@endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-500">{{ \App\Modules\Shared\Support\IndianNumber::format($centers->count()) }} centre{{ $centers->count() === 1 ? '' : 's' }} listed.</p>
    @endif
</div>
@endsection
