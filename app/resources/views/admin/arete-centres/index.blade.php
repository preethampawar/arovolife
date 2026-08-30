@extends('admin.layouts.admin')
@section('title', 'Arete Centres')
@section('heading', 'Arete Development Centre registry')

@section('content')
@include('admin.arete-centres._tabs')
@php
    use App\Modules\Compensation\Models\AreteCenter;
    use App\Modules\Shared\Support\IndianNumber;

    $inp = 'border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400';
    $canDiscipline = auth()->user()?->can('compliance.discipline') ?? false;
@endphp

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Every Arete Development Centre is a training, product-demonstration and support centre — never a shop or outlet. A distributor centre is assigned to the distributor who earns the ADC Bonus (3% of member BV, capped at ₹1,00,000/month); a company centre has no owner. Only <strong>active</strong> centres appear in the registration Step 11 picker and on the profile page; the company default is pre-selected there.
</div>

@if(session('success'))
<div class="rounded-lg border border-green-200 bg-green-50 p-3 mb-4 text-sm text-green-800">{{ session('success') }}</div>
@endif
@if($errors->any())
<div class="rounded-lg border border-red-200 bg-red-50 p-3 mb-4 text-sm text-red-700">
    <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif

<div class="flex flex-wrap justify-end items-center gap-2 mb-4">
    <a href="{{ route('admin.arete-centres.create') }}"
       class="px-4 py-1.5 rounded-lg bg-brand-700 text-white text-sm hover:bg-brand-800 transition-colors">+ Add Centre</a>
</div>

<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 bg-white rounded-xl border border-gray-200 p-4">
    <div>
        <label class="block text-xs text-gray-600 mb-1">Status</label>
        <select name="status" class="{{ $inp }}">
            <option value="">All</option>
            <option value="active" @selected($filters['status'] === 'active')>Active</option>
            <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-600 mb-1">State</label>
        <select name="state" class="{{ $inp }}">
            <option value="">All states</option>
            @foreach($states as $stateName)
            <option value="{{ $stateName }}" @selected($filters['state'] === $stateName)>{{ $stateName }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-600 mb-1">City / district</label>
        <input type="text" name="city" value="{{ $filters['city'] }}" list="adc-city-list" class="{{ $inp }} w-40" placeholder="Starts with…">
        <datalist id="adc-city-list">
            @foreach(AreteCenter::query()->whereNotNull('city')->distinct()->orderBy('city')->limit(200)->pluck('city') as $cityName)
            <option value="{{ $cityName }}"></option>
            @endforeach
        </datalist>
    </div>
    <div>
        <label class="block text-xs text-gray-600 mb-1">Type</label>
        <select name="type" class="{{ $inp }}">
            <option value="">All</option>
            @foreach(AreteCenter::TYPES as $key => $label)
            <option value="{{ $key }}" @selected($filters['type'] === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-600 mb-1">Phase</label>
        <select name="phase" class="{{ $inp }}">
            <option value="">All</option>
            @foreach(array_keys(AreteCenter::PHASES) as $phaseNo)
            <option value="{{ $phaseNo }}" @selected($filters['phase'] === (string) $phaseNo)>Phase {{ $phaseNo }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-600 mb-1">Owner ADN</label>
        <input type="text" name="adn" value="{{ $filters['adn'] }}" class="{{ $inp }} w-36 font-mono">
    </div>
    <div>
        <label class="block text-xs text-gray-600 mb-1">Centre name</label>
        <input type="text" name="q" value="{{ $filters['q'] }}" class="{{ $inp }} w-44">
    </div>
    <button type="submit" class="px-4 py-1.5 rounded-lg bg-brand-700 text-white text-sm hover:bg-brand-800 transition-colors">Filter</button>
    <a href="{{ route('admin.arete-centres.index') }}" class="text-sm text-gray-600 hover:text-gray-800">Reset</a>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($centers->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-600 text-center">No centres match — add one above or clear the filters.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600">Sr</th>
                    <th class="px-3 py-2 text-left text-gray-600">Centre name</th>
                    <th class="px-3 py-2 text-left text-gray-600">City</th>
                    <th class="px-3 py-2 text-left text-gray-600">State</th>
                    <th class="px-3 py-2 text-left text-gray-600">Owner</th>
                    <th class="px-3 py-2 text-left text-gray-600">Type</th>
                    <th class="px-3 py-2 text-center text-gray-600">Phase</th>
                    <th class="px-3 py-2 text-center text-gray-600">Status</th>
                    <th class="px-3 py-2 text-left text-gray-600">Contact</th>
                    <th class="px-3 py-2 text-left text-gray-600">Weekly off</th>
                    <th class="px-3 py-2 text-left text-gray-600 min-w-[14rem]">Address</th>
                    <th class="px-3 py-2 text-right text-gray-600">Members</th>
                    <th class="px-3 py-2 text-right text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($centers as $center)
                @php $address = $center->displayAddress() ?: ($center->location ?? ''); @endphp
                <tr>
                    <td class="px-3 py-2 text-gray-500">{{ $centers->firstItem() + $loop->index }}</td>
                    <td class="px-3 py-2 font-medium">
                        {{ $center->name }}
                        @if($center->is_company_default)
                        <span class="ml-1 inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-brand-100 text-brand-700">Default</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600">{{ $center->city ?? $center->district ?? '—' }}</td>
                    <td class="px-3 py-2 text-gray-600">{{ $center->state ?? '—' }}</td>
                    <td class="px-3 py-2">
                        @if($center->assignedDistributor)
                        <span class="font-mono">{{ $center->assignedDistributor->adn }}</span>
                        <span class="block text-gray-500">{{ $center->assignedDistributor->user->full_name ?? '' }}</span>
                        @else — @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600">{{ AreteCenter::TYPES[$center->centre_type] ?? ucfirst($center->centre_type) }}</td>
                    <td class="px-3 py-2 text-center">
                        <span title="{{ AreteCenter::PHASES[$center->development_phase] ?? '' }}">{{ $center->development_phase }}</span>
                        @if($center->monthly_cap_override_paise !== null)
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-orange-100 text-orange-700"
                              title="Monthly cap overridden to ₹{{ IndianNumber::format(intdiv($center->monthly_cap_override_paise, 100)) }} — development-phase penalty">
                            Cap ₹{{ IndianNumber::format(intdiv($center->monthly_cap_override_paise, 100)) }}
                        </span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $center->isActive() ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}"
                              @if($center->deactivation_reason) title="{{ $center->deactivation_reason }}" @endif>
                            {{ ucfirst($center->status) }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-gray-600">
                        {{ $center->contact_person ?? '—' }}
                        @if($center->contact_number)<span class="block font-mono">{{ $center->contact_number }}</span>@endif
                    </td>
                    <td class="px-3 py-2 text-gray-600">{{ $center->weekly_off ? (AreteCenter::WEEKLY_OFF_OPTIONS[$center->weekly_off] ?? $center->weekly_off) : '—' }}</td>
                    <td class="px-3 py-2 text-gray-600 min-w-[14rem] max-w-[22rem] whitespace-normal break-words">{{ $address !== '' ? $address : '—' }}</td>
                    <td class="px-3 py-2 text-right">{{ IndianNumber::format($center->members_count) }}</td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        <a href="{{ route('admin.arete-centres.edit', $center) }}" class="text-brand-700 hover:text-brand-800 font-medium">Edit</a>
                        @if($center->isActive())
                            @if(! $center->is_company_default)
                            <form method="POST" action="{{ route('admin.arete-centres.default', $center) }}" class="inline ml-2"
                                  data-confirm="Make “{{ $center->name }}” the company default centre?" data-confirm-title="Set company default"
                                  data-confirm-impact="New registrations will have this centre pre-selected at Step 11. The previous default stays active.">
                                @csrf
                                <button type="submit" class="text-gray-600 hover:text-gray-800">Set default</button>
                            </form>
                            @if($canDiscipline)
                            <button type="button" class="ml-2 text-red-600 hover:text-red-700"
                                    onclick="document.getElementById('deactivate-{{ $center->id }}').classList.toggle('hidden')">Deactivate</button>
                            @endif
                            @endif
                        @elseif($canDiscipline)
                            <form method="POST" action="{{ route('admin.arete-centres.status', [$center, 'activate']) }}" class="inline ml-2"
                                  data-confirm="Activate “{{ $center->name }}”?" data-confirm-title="Activate centre"
                                  data-confirm-impact="The centre becomes selectable again at Step 11 and on profiles.">
                                @csrf
                                <button type="submit" class="text-green-700 hover:text-green-800">Activate</button>
                            </form>
                        @endif
                    </td>
                </tr>
                @if($canDiscipline && $center->isActive() && ! $center->is_company_default)
                <tr id="deactivate-{{ $center->id }}" class="hidden bg-red-50">
                    <td colspan="13" class="px-4 py-3">
                        <form method="POST" action="{{ route('admin.arete-centres.status', [$center, 'deactivate']) }}" class="flex flex-wrap items-end gap-3"
                              data-confirm="Deactivate “{{ $center->name }}”?" data-confirm-title="Deactivate centre"
                              data-confirm-impact="The centre disappears from Step 11 and the profile picker. Distributors already connected to it are not moved.">
                            @csrf
                            <div class="flex-1 min-w-[16rem]">
                                <label class="block text-xs text-red-800 mb-1">Reason for deactivation <span class="text-red-500">*</span></label>
                                <input type="text" name="reason" required maxlength="500" class="{{ $inp }} w-full">
                            </div>
                            <button type="submit" class="px-4 py-1.5 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700">Confirm deactivate</button>
                        </form>
                    </td>
                </tr>
                @endif
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $centers->links() }}</div>
    @endif
</div>

@endsection
