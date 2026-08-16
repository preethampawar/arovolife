@extends('layouts.app')
@section('title', 'My grievances')

@section('content')
<div class="max-w-4xl mx-auto">

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">My grievances</h1>
            <p class="text-sm text-gray-600">Everything you have raised, and where each one stands.</p>
        </div>
        <a href="{{ route('my.grievances.create') }}"
           class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">
            Raise a grievance
        </a>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($tickets->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
            <p class="text-gray-900 font-medium mb-1">You have not raised any grievances.</p>
            <p class="text-sm text-gray-600">
                If something has gone wrong, raise it — every complaint gets a number and a resolution date.
            </p>
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Number</th>
                        <th class="px-4 py-3">Subject</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">Raised</th>
                        <th class="px-4 py-3">Resolve by</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($tickets as $ticket)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono text-xs">
                                <a href="{{ route('my.grievances.show', $ticket->id) }}" class="font-semibold text-brand-600 underline">
                                    {{ $ticket->ticket_no }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-gray-900">{{ \Illuminate\Support\Str::limit($ticket->subject, 48) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $ticket->category->label() }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $ticket->created_at->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $ticket->sla_resolution_at?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $ticket->status->badgeClasses() }}">
                                    {{ $ticket->status->label() }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $tickets->links() }}</div>
    @endif
</div>
@endsection
