@extends('layouts.app')
@section('title', 'My requests')

@section('content')
@php
    use App\Modules\Identity\Models\DistributorRequest;
    $badge = [
        'submitted' => 'bg-amber-100 text-amber-800', 'under_review' => 'bg-blue-100 text-blue-800',
        'approved' => 'bg-green-100 text-green-700', 'rejected' => 'bg-red-100 text-red-700',
    ];
@endphp
<div class="max-w-5xl">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">My requests</h1>
            <p class="text-sm text-gray-600">Formal requests about your own record, and where each one stands.</p>
        </div>
        <a href="{{ route('my.requests.create') }}" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800">New request</a>
    </div>

    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold mb-1">What goes where</p>
        <ul class="list-disc list-inside space-y-0.5">
            <li><strong>Mobile, email or address change</strong> — update them yourself on <a href="{{ route('profile.show') }}" class="underline">My profile</a> (OTP-verified). No request needed.</li>
            <li><strong>Name or date-of-birth correction, name change, membership transfer, ID cancellation</strong> — file a request here with the supporting document.</li>
            <li><strong>Poaching, competitive business, e-commerce selling, stocking or under-cutting by another distributor</strong> — these are complaints: <a href="{{ route('my.grievances.create') }}" class="underline">raise a grievance</a> and pick that category.</li>
        </ul>
    </div>

    @if($requests->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
            <p class="text-gray-900 font-medium mb-1">You have not filed any requests.</p>
            <p class="text-sm text-gray-600">Requests are free and change nothing until arovolife approves them.</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Number</th>
                        <th class="px-4 py-3">Request</th>
                        <th class="px-4 py-3">Requested</th>
                        <th class="px-4 py-3">Filed</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($requests as $item)
                    <tr>
                        <td class="px-4 py-3 font-mono"><a href="{{ route('my.requests.show', $item) }}" class="text-brand-700 hover:text-brand-800">{{ $item->request_no }}</a></td>
                        <td class="px-4 py-3">{{ $item->typeLabel() }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $item->requestedSummary() }}</td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $item->submitted_at?->timezone('Asia/Kolkata')->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3"><span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badge[$item->status] ?? 'bg-gray-100 text-gray-600' }}">{{ $item->statusLabel() }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
