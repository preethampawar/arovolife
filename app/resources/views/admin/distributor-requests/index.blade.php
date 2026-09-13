@extends('admin.layouts.admin')
@section('title', 'Distributor requests')
@section('heading', 'Distributor requests')

@section('content')
@php
    $badge = [
        'submitted' => 'bg-amber-100 text-amber-800', 'under_review' => 'bg-blue-100 text-blue-800',
        'approved' => 'bg-green-100 text-green-700', 'rejected' => 'bg-red-100 text-red-700',
    ];
    $openCount = ($counts['submitted'] ?? 0) + ($counts['under_review'] ?? 0);
@endphp

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Distributors file formal requests about their own record. Approving a name or date-of-birth request updates the record (audit-logged); approving a membership transfer or ID cancellation is an acknowledgement — compliance then carries it out with the account tools on the distributor's page. Every decision is emailed to the distributor.
</div>

<p class="text-sm text-gray-600 mb-4">{{ \App\Modules\Shared\Support\IndianNumber::format($openCount) }} open · {{ \App\Modules\Shared\Support\IndianNumber::format($counts['approved'] ?? 0) }} approved · {{ \App\Modules\Shared\Support\IndianNumber::format($counts['rejected'] ?? 0) }} rejected</p>

<x-filter-bar :filters="$filters" />

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($requests->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-600 text-center">No requests match these filters.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-600 w-12">S.No.</th>
                    <th class="px-4 py-2 text-left text-gray-600">Filed</th>
                    <th class="px-4 py-2 text-left text-gray-600">Number</th>
                    <th class="px-4 py-2 text-left text-gray-600">Type</th>
                    <th class="px-4 py-2 text-left text-gray-600">Distributor</th>
                    <th class="px-4 py-2 text-left text-gray-600">Requested</th>
                    <th class="px-4 py-2 text-center text-gray-600">Status</th>
                    <th class="px-4 py-2 text-right text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($requests as $item)
                <tr>
                    <td class="px-4 py-2 text-gray-500 tabular-nums">{{ $requests->firstItem() + $loop->index }}</td>
                    <td class="px-4 py-2 text-gray-600 whitespace-nowrap">{{ $item->submitted_at?->timezone('Asia/Kolkata')->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-2 font-mono">{{ $item->request_no }}</td>
                    <td class="px-4 py-2 font-medium">{{ $item->typeLabel() }}</td>
                    <td class="px-4 py-2"><span class="font-mono">{{ $item->distributor->adn ?? '—' }}</span> <span class="text-gray-600">{{ $item->distributor->user->full_name ?? '' }}</span></td>
                    <td class="px-4 py-2 text-gray-700">{{ $item->requestedSummary() }}</td>
                    <td class="px-4 py-2 text-center"><span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badge[$item->status] ?? 'bg-gray-100 text-gray-600' }}">{{ $item->statusLabel() }}</span></td>
                    <td class="px-4 py-2 text-right"><a href="{{ route('admin.distributor-requests.show', $item) }}" class="text-brand-700 hover:text-brand-800 font-medium">Review</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $requests->links() }}</div>
    @endif
</div>
@endsection
