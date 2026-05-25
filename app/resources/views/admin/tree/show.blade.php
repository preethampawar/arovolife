@extends('admin.layouts.admin')
@section('title', 'Tree — '.$self->adn)
@section('heading', $isCompanyRoot ? 'Company tree' : 'Distributor tree')

@section('content')

<div class="mb-4">
    <a href="{{ $isCompanyRoot ? route('admin.distributors.index') : route('admin.distributors.show', $self->id) }}" class="text-sm text-gray-500 hover:text-gray-700">← Back</a>
</div>

{{-- Admin context bar — whose tree, with action buttons --}}
<div class="mb-5 rounded-2xl border border-brand-200 bg-gradient-to-r from-brand-50 to-white p-4 sm:p-5 flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0 flex-1">
        <p class="text-[10px] uppercase tracking-wider text-brand-700 font-semibold mb-1">
            {{ $isCompanyRoot ? 'Viewing the entire company genealogy' : 'Viewing distributor tree (admin)' }}
        </p>
        <h2 class="text-lg font-bold text-gray-900 leading-tight">
            <span class="font-mono text-brand-700">{{ $self->adn }}</span>
            @if($self->user?->full_name)
                <span class="text-gray-700 font-normal">— {{ $self->user->full_name }}</span>
            @endif
        </h2>
        <p class="text-xs text-gray-500 mt-0.5">
            {{ $totalDescendants }} {{ $totalDescendants === 1 ? 'distributor' : 'distributors' }} below this node ·
            actual depth {{ $maxObservedDepth }}
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        @if(! $isCompanyRoot)
            <a href="{{ route('admin.distributors.show', $self->id) }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold transition-colors">
                View profile
            </a>
            @if($self->user_id && ! auth()->user()?->is($self->user))
                <form method="POST" action="{{ route('admin.impersonate.start', $self->user_id) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-sunrise-500 hover:bg-sunrise-600 text-white text-xs font-semibold transition-colors shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
                        Impersonate
                    </button>
                </form>
            @endif
        @else
            <a href="{{ route('admin.distributors.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold transition-colors">
                Pick a different root
            </a>
        @endif
    </div>
</div>

@include('tree._content', [
    'self'              => $self,
    'childByParentSide' => $childByParentSide,
    'maxDepth'          => $maxDepth,
    'totalDescendants'  => $totalDescendants,
    'maxObservedDepth'  => $maxObservedDepth,
    'contextTitle'      => $isCompanyRoot ? 'Company genealogy' : 'Subtree of '.$self->adn,
    'contextSubtitlePre'=> 'Showing this node and descendants up to ',
    'showSponsorshipLink' => false,
    'adminContext'      => true,
    'searchUrl'         => route('admin.tree.search'),
    'suggestUrl'        => route('admin.tree.suggest'),
    'rerootBase'        => url('/admin/tree'),
    'rerootKey'         => 'id',
])

@endsection
