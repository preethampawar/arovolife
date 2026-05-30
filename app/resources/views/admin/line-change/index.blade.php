@extends('admin.layouts.admin')
@section('title', 'Line-change requests')
@section('heading', 'Line-change requests')

@section('content')

<p class="text-sm text-gray-600 mb-4">
    Distributors requesting a move of their <strong>Genos placement</strong> to a different
    parent. Approving moves their placement only — the sponsor is never changed.
</p>

@if(session('status'))
<div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-3 py-2 text-sm text-green-800">
    {{ session('status') }}
</div>
@endif

<div class="flex items-center gap-2 mb-6">
    <a href="{{ route('admin.line-changes.index', ['tab' => 'pending']) }}"
        class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors
            {{ $currentTab === 'decided' ? 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50' : 'border-brand-500 bg-brand-500 text-white' }}">
        Pending
        <span class="inline-flex items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold
            {{ $currentTab === 'decided' ? 'bg-gray-100 text-gray-700' : 'bg-white/25 text-white' }}">{{ $pendingCount }}</span>
    </a>
    <a href="{{ route('admin.line-changes.index', ['tab' => 'decided']) }}"
        class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors
            {{ $currentTab === 'decided' ? 'border-brand-500 bg-brand-500 text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50' }}">
        Decided
        <span class="inline-flex items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold
            {{ $currentTab === 'decided' ? 'bg-white/25 text-white' : 'bg-gray-100 text-gray-700' }}">{{ $decidedCount }}</span>
    </a>
</div>

<div class="rounded-2xl border border-gray-200 bg-white">
    @if($rows->isEmpty())
    <div class="p-8 text-center text-sm text-gray-500">
        {{ $currentTab === 'decided' ? 'No decided requests yet.' : 'No pending line-change requests.' }}
    </div>
    @else
    <table class="w-full text-sm">
        <thead class="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-200">
            <tr>
                <th class="px-5 py-3">Requester</th>
                <th class="px-5 py-3">Current parent</th>
                <th class="px-5 py-3">Requested parent</th>
                <th class="px-5 py-3">Requested</th>
                <th class="px-5 py-3">Status</th>
                <th class="px-5 py-3 text-right">Action</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
            <tr class="border-b border-gray-100 last:border-0">
                <td class="px-5 py-3 font-mono font-bold text-brand-600 tracking-widest">{{ $row->distributor?->adn ?? '—' }}</td>
                <td class="px-5 py-3 font-mono text-gray-700">{{ $row->fromPlacementParent?->adn ?? '—' }}</td>
                <td class="px-5 py-3 font-mono text-gray-700">{{ $row->toPlacementParent?->adn ?? '—' }}</td>
                <td class="px-5 py-3 text-gray-700">{{ $row->requested_at->format('d M Y H:i') }}</td>
                <td class="px-5 py-3 text-gray-700">{{ ucfirst($row->status) }}</td>
                <td class="px-5 py-3 text-right">
                    <a href="{{ route('admin.line-changes.show', $row->id) }}"
                        class="inline-flex items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-3 py-1.5 text-xs transition-colors">
                        {{ $row->status === 'pending' ? 'Review →' : 'View →' }}
                    </a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

<div class="mt-4">{{ $rows->links() }}</div>

@endsection
