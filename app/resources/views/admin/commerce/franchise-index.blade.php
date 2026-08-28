@extends('admin.layouts.admin')
@section('title', 'Franchise Applications')
@section('heading', 'Franchise Applications')

@section('content')

@if(! $flagActive)
<div class="rounded-xl border border-amber-200 bg-amber-50 p-4 mb-6 text-sm text-amber-900">
    <p class="font-semibold mb-1">Feature flag is OFF</p>
    <p>The Franchise feature is currently disabled. Distributors cannot submit applications.
       Enable <strong>FranchiseFeature</strong> from
       <a href="{{ route('admin.feature-flags.index') }}" class="underline">Feature Flags</a>
       once the DSA §6.2 notice period and legal opinion (R-24) are satisfied.</p>
</div>
@endif

<div class="flex items-center gap-3 mb-6 flex-wrap">
    <a href="{{ route('admin.commerce.franchise.index') }}"
       class="px-3 py-1 rounded-full text-xs font-medium border {{ !request()->query('status') ? 'bg-brand-500 text-white border-brand-500' : 'bg-white text-gray-700 border-gray-200 hover:border-brand-500' }}">
        All
    </a>
    @foreach(['pending_approval', 'active', 'rejected', 'suspended'] as $s)
    <a href="{{ route('admin.commerce.franchise.index', ['status' => $s]) }}"
       class="px-3 py-1 rounded-full text-xs font-medium border {{ request()->query('status') === $s ? 'bg-brand-500 text-white border-brand-500' : 'bg-white text-gray-700 border-gray-200 hover:border-brand-500' }}">
        {{ ucwords(str_replace('_', ' ', $s)) }}
        @if(isset($statusCounts[$s])) ({{ $statusCounts[$s] }}) @endif
    </a>
    @endforeach
</div>

@if(session('success'))
<div class="rounded-xl border border-green-200 bg-green-50 p-4 mb-6 text-sm text-green-800">
    {{ session('success') }}
</div>
@endif

<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Distributor</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">ADC Centre</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Applied</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($franchises as $f)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-brand-600 font-medium text-xs">{{ $f->code }}</td>
                    <td class="px-4 py-3 text-gray-700">
                        <div>{{ $f->operator->user->full_name ?? '—' }}</div>
                        <div class="text-xs text-gray-400 font-mono">{{ $f->operator->adn ?? '—' }}</div>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600">
                        @if($f->district || $f->state)
                            {{ $f->district }}{{ $f->district && $f->state ? ', ' : '' }}{{ $f->state }}
                            @if($f->pincode)<div class="text-gray-400">{{ $f->pincode }}</div>@endif
                        @else
                            <span class="italic text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600">{{ $f->areteCenter?->name ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                            @if($f->status === 'active') bg-green-100 text-green-800
                            @elseif($f->status === 'pending_approval') bg-amber-100 text-amber-800
                            @elseif($f->status === 'rejected') bg-red-100 text-red-700
                            @else bg-gray-100 text-gray-700
                            @endif">
                            {{ ucwords(str_replace('_', ' ', $f->status)) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        {{ $f->applied_at?->format('d M Y') ?? '—' }}
                    </td>
                    <td class="px-4 py-3">
                        @if($f->status === 'pending_approval')
                        <div class="flex items-center gap-2">
                            <form method="POST" action="{{ route('admin.commerce.franchise.approve', $f) }}"
                                data-confirm="Approve franchise application {{ $f->code }}?"
                                data-confirm-title="Approve franchise">
                                @csrf
                                <button type="submit"
                                    class="text-xs text-green-600 hover:text-green-700 font-medium">Approve</button>
                            </form>
                            <span class="text-gray-300">|</span>
                            <form method="POST" action="{{ route('admin.commerce.franchise.reject', $f) }}"
                                data-confirm="Reject franchise application {{ $f->code }}?"
                                data-confirm-title="Reject franchise">
                                @csrf
                                <button type="submit"
                                    class="text-xs text-red-600 hover:text-red-700 font-medium">Reject</button>
                            </form>
                        </div>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">No franchise applications yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($franchises->hasPages())
    <div class="px-4 py-4 border-t border-gray-200">{{ $franchises->links() }}</div>
    @endif
</div>

@endsection
