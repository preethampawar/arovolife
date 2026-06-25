@extends('admin.layouts.admin')
@section('title', 'Arete Centers')
@section('heading', 'Arete Development Centers')

@section('content')

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Each Arete Development Center is assigned to one distributor who earns the ADC Bonus (3% of member BV, capped at ₹1,00,000/month). Centers must be approved by the company before members can be added.
</div>

<div class="flex justify-end mb-4">
    <a href="{{ route('admin.compensation.adc-bonus.centers.create') }}"
       class="px-4 py-1.5 rounded-lg bg-brand-500 text-white text-sm hover:bg-brand-600 transition-colors">+ Add Center</a>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($centers->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-400 text-center">No centers yet — add one above.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500">Name</th>
                    <th class="px-4 py-2 text-left text-gray-500">Location</th>
                    <th class="px-4 py-2 text-left text-gray-500">Assigned distributor</th>
                    <th class="px-4 py-2 text-center text-gray-500">Status</th>
                    <th class="px-4 py-2 text-right text-gray-500">Members</th>
                    <th class="px-4 py-2 text-left text-gray-500">Approved</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($centers as $center)
                @php
                    $sc = ['active' => 'bg-green-100 text-green-700', 'inactive' => 'bg-gray-100 text-gray-500'];
                @endphp
                <tr>
                    <td class="px-4 py-2 font-medium">{{ $center->name }}</td>
                    <td class="px-4 py-2 text-gray-600">{{ $center->location ?? '—' }}</td>
                    <td class="px-4 py-2 font-mono">{{ $center->assignedDistributor->adn ?? '—' }}</td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$center->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($center->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-right">{{ number_format($center->members_count) }}</td>
                    <td class="px-4 py-2 text-gray-600">
                        {{ $center->approved_at ? \Illuminate\Support\Carbon::parse($center->approved_at)->format('d M Y') : '—' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $centers->links() }}</div>
    @endif
</div>

@endsection
