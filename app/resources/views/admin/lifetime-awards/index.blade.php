@extends('admin.layouts.admin')
@section('title', 'Lifetime Awards')
@section('heading', 'Lifetime Awards & Milestones')

@section('content')

<div class="mb-6 rounded-lg border border-purple-200 bg-purple-50 p-4 text-sm text-purple-800">
    Lifetime awards are non-cash rewards issued the first time a distributor achieves a given rank. Mark them as delivered once the physical award has been dispatched.
</div>

{{-- Filter --}}
<form method="GET" class="flex gap-3 mb-6 items-end">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
            <option value="">All</option>
            <option value="pending" @selected(request('status') === 'pending')>Pending</option>
            <option value="delivered" @selected(request('status') === 'delivered')>Delivered</option>
            <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
        </select>
    </div>
    <button type="submit" class="px-4 py-1.5 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600">Filter</button>
    @if(request('status'))
        <a href="{{ route('admin.lifetime-awards.index') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
    @endif
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($milestones->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-400 text-center">No lifetime award milestones yet.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500">ADN</th>
                    <th class="px-4 py-2 text-left text-gray-500">Rank</th>
                    <th class="px-4 py-2 text-left text-gray-500">Triggered</th>
                    <th class="px-4 py-2 text-left text-gray-500">Award</th>
                    <th class="px-4 py-2 text-center text-gray-500">Status</th>
                    <th class="px-4 py-2 text-left text-gray-500">Delivered at</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($milestones as $milestone)
                @php
                $sc = ['pending' => 'bg-amber-100 text-amber-700', 'delivered' => 'bg-green-100 text-green-700', 'cancelled' => 'bg-red-100 text-red-700'];
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-mono">{{ $milestone->distributor?->adn ?? '—' }}</td>
                    <td class="px-4 py-2">
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-medium">
                            {{ $rankNames[$milestone->rank_number] ?? 'Rank '.$milestone->rank_number }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-gray-600">{{ \Illuminate\Support\Carbon::parse($milestone->triggered_month)->format('M Y') }}</td>
                    <td class="px-4 py-2 text-gray-700">{{ $milestone->award_description }}</td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$milestone->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($milestone->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-gray-500">
                        {{ $milestone->delivered_at ? $milestone->delivered_at->format('d M Y') : '—' }}
                    </td>
                    <td class="px-4 py-2">
                        @if($milestone->status === 'pending')
                        <form method="POST" action="{{ route('admin.lifetime-awards.deliver', $milestone->id) }}"
                              onsubmit="return confirm('Mark this lifetime award as delivered?')">
                            @csrf
                            <button type="submit"
                                    class="px-2 py-1 rounded bg-green-100 text-green-700 hover:bg-green-200 text-[10px] font-medium">
                                Mark Delivered
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $milestones->links() }}</div>
    @endif
</div>

@endsection
