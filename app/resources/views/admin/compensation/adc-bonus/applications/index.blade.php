@extends('admin.layouts.admin')
@section('title', 'ADC Applications')
@section('heading', 'Arete Development Centre applications')

@section('content')
@php
    $inp = 'border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400';
    $badge = [
        'submitted' => 'bg-amber-100 text-amber-800', 'under_review' => 'bg-blue-100 text-blue-800',
        'needs_changes' => 'bg-orange-100 text-orange-800', 'approved' => 'bg-green-100 text-green-700',
        'rejected' => 'bg-red-100 text-red-700',
    ];
    $openCount = ($counts['submitted'] ?? 0) + ($counts['under_review'] ?? 0) + ($counts['needs_changes'] ?? 0);
@endphp

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Distributors apply to open an Arete Development Centre; approving an application creates the centre in the registry at Phase 1 with the applicant as its owner. Every decision is audit-logged and emailed to the applicant.
</div>

<div class="flex items-center justify-between mb-4">
    <p class="text-sm text-gray-600">{{ \App\Modules\Shared\Support\IndianNumber::format($openCount) }} open · {{ \App\Modules\Shared\Support\IndianNumber::format($counts['approved'] ?? 0) }} approved · {{ \App\Modules\Shared\Support\IndianNumber::format($counts['rejected'] ?? 0) }} rejected</p>
    <a href="{{ route('admin.compensation.adc-bonus.centers.index') }}" class="text-sm text-brand-700 hover:text-brand-800 font-medium">Centre registry →</a>
</div>

<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 bg-white rounded-xl border border-gray-200 p-4">
    <div>
        <label class="block text-xs text-gray-600 mb-1">Status</label>
        <select name="status" class="{{ $inp }}">
            <option value="open" @selected($filters['status'] === 'open')>Open (needs action)</option>
            <option value="all" @selected($filters['status'] === 'all')>All</option>
            @foreach(\App\Modules\Compensation\Models\AreteCenterApplication::STATUSES as $key => $label)
            <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
            @endforeach
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
        <label class="block text-xs text-gray-600 mb-1">Search</label>
        <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Centre name, city, pincode or ADN" class="{{ $inp }} w-64">
    </div>
    <button type="submit" class="px-4 py-1.5 rounded-lg bg-brand-700 text-white text-sm hover:bg-brand-800 transition-colors">Filter</button>
    <a href="{{ route('admin.compensation.adc-bonus.applications.index') }}" class="text-sm text-gray-600 hover:text-gray-800">Reset</a>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($applications->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-600 text-center">No applications match these filters.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-600">Submitted</th>
                    <th class="px-4 py-2 text-left text-gray-600">Proposed centre</th>
                    <th class="px-4 py-2 text-left text-gray-600">City · State</th>
                    <th class="px-4 py-2 text-left text-gray-600">Applicant</th>
                    <th class="px-4 py-2 text-right text-gray-600">Size (sq ft)</th>
                    <th class="px-4 py-2 text-center text-gray-600">Status</th>
                    <th class="px-4 py-2 text-right text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($applications as $app)
                <tr>
                    <td class="px-4 py-2 text-gray-600 whitespace-nowrap">{{ $app->submitted_at?->timezone('Asia/Kolkata')->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-2 font-medium">{{ $app->centre_name }}</td>
                    <td class="px-4 py-2 text-gray-600">{{ $app->city }} · {{ $app->state }}</td>
                    <td class="px-4 py-2"><span class="font-mono">{{ $app->distributor->adn ?? '—' }}</span> <span class="text-gray-600">{{ $app->distributor->user->full_name ?? '' }}</span></td>
                    <td class="px-4 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::format($app->premises_sqft) }}</td>
                    <td class="px-4 py-2 text-center"><span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badge[$app->status] ?? 'bg-gray-100 text-gray-600' }}">{{ $app->statusLabel() }}</span></td>
                    <td class="px-4 py-2 text-right"><a href="{{ route('admin.compensation.adc-bonus.applications.show', $app) }}" class="text-brand-700 hover:text-brand-800 font-medium">Review</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $applications->links() }}</div>
    @endif
</div>
@endsection
