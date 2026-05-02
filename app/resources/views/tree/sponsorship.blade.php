@extends('layouts.app')
@section('title', 'My direct referrals')

@section('content')

<div class="mb-6">
    <h1 class="text-2xl font-bold mb-1">My direct referrals</h1>
    <p class="text-sm text-gray-600">
        People you sponsored directly. The
        <a href="{{ route('tree.binary') }}" class="text-brand-600 underline">binary tree</a>
        is a separate view.
    </p>
</div>

<div class="rounded-2xl border border-gray-200 bg-white">
    @if($direct->isEmpty())
    <div class="p-8 text-center text-sm text-gray-500">
        No direct referrals yet.
    </div>
    @else
    <table class="w-full text-sm">
        <thead class="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-200">
            <tr>
                <th class="px-5 py-3">ADN</th>
                <th class="px-5 py-3">Joined</th>
                <th class="px-5 py-3">Side</th>
                <th class="px-5 py-3">Depth</th>
            </tr>
        </thead>
        <tbody>
            @foreach($direct as $row)
            <tr class="border-b border-gray-100 last:border-0">
                <td class="px-5 py-3 font-mono font-bold text-brand-600 tracking-widest">{{ $row->adn }}</td>
                <td class="px-5 py-3 text-gray-700">{{ $row->effective_date->format('d M Y') }}</td>
                <td class="px-5 py-3 text-gray-700">{{ $row->placement_side === 'L' ? 'Left' : ($row->placement_side === 'R' ? 'Right' : '—') }}</td>
                <td class="px-5 py-3 text-gray-700">Level {{ $row->depth }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

<div class="mt-4">
    {{ $direct->links() }}
</div>

@endsection
