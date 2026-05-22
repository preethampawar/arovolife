@extends('admin.layouts.admin')
@section('title', 'Pending registrations')
@section('heading', 'Pending registrations')

@section('content')

<p class="text-sm text-gray-700 mb-4">
    Customers who created an account but never finished the wizard. Most are
    drop-offs at step 9 (Documents) or the final Confirm step. Use this page
    to upload missing documents on their behalf and trigger the final
    placement so the ADN is issued.
</p>

@if(session('status'))
    <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-800">
        {{ session('status') }}
    </div>
@endif

<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    @if($stuck->isEmpty())
        <div class="p-8 text-center text-sm text-gray-500">
            No pending registrations. Every account that has been created has
            either completed registration or is still actively progressing.
        </div>
    @else
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 bg-gray-50/50">
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Customer</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Account created</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Furthest step</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Draft</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @foreach($stuck as $row)
            <tr class="hover:bg-gray-50/50 transition-colors">
                <td class="px-4 py-3">
                    <p class="text-gray-800">{{ $row->full_name ?: '—' }}</p>
                    <p class="text-xs text-gray-700">{{ $row->email }}</p>
                    @if($row->phone_e164)
                        <p class="text-xs text-gray-600 font-mono tracking-tight">{{ $row->phone_e164 }}</p>
                    @endif
                </td>
                <td class="px-4 py-3 text-xs text-gray-700">
                    {{ \Carbon\Carbon::parse($row->created_at)->format('d M Y') }}<br>
                    <span class="text-gray-500">{{ \Carbon\Carbon::parse($row->created_at)->diffForHumans() }}</span>
                </td>
                <td class="px-4 py-3 text-xs text-gray-700">
                    @if($row->draft_step)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-[11px] font-medium text-amber-800">
                            Step {{ $row->draft_step }}/10
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-50 border border-gray-200 px-2 py-0.5 text-[11px] font-medium text-gray-600">
                            No draft
                        </span>
                    @endif
                </td>
                <td class="px-4 py-3 text-xs text-gray-700">
                    @if($row->draft_expires_at)
                        Expires {{ \Carbon\Carbon::parse($row->draft_expires_at)->format('d M Y') }}
                    @else
                        <span class="text-gray-500">—</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    @if($row->draft_step)
                        <a href="{{ route('admin.pending-registrations.show', $row->id) }}"
                            class="inline-flex items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-3 py-1.5 text-xs transition-colors">
                            Help finish →
                        </a>
                    @else
                        <span class="text-xs text-gray-500">No draft — use "Add Distributor"</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

<div class="mt-4">{{ $stuck->links() }}</div>

@endsection
