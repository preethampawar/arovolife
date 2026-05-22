@extends('admin.layouts.admin')
@section('title', 'KYC review queue')
@section('heading', 'KYC review queue')

@section('content')

<p class="text-sm text-gray-600 mb-6">
    Distributors who completed registration and are waiting for an admin to approve their
    PAN, Aadhaar, bank, and address-proof documents.
</p>

<div class="rounded-2xl border border-gray-200 bg-white">
    @if($pending->isEmpty())
    <div class="p-8 text-center text-sm text-gray-500">No pending KYC submissions.</div>
    @else
    <table class="w-full text-sm">
        <thead class="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-200">
            <tr>
                <th class="px-5 py-3">ADN</th>
                <th class="px-5 py-3">Email</th>
                <th class="px-5 py-3">Submitted</th>
                <th class="px-5 py-3">Docs</th>
                <th class="px-5 py-3 text-right">Action</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pending as $row)
            <tr class="border-b border-gray-100 last:border-0">
                <td class="px-5 py-3 font-mono font-bold text-brand-600 tracking-widest">{{ $row->adn }}</td>
                <td class="px-5 py-3 text-gray-700">{{ $row->user->email }}</td>
                <td class="px-5 py-3 text-gray-700">{{ $row->created_at->format('d M Y H:i') }}</td>
                <td class="px-5 py-3 text-gray-700">
                    @if($row->kyc_documents_count === 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-[11px] font-medium text-amber-800">
                            Awaiting documents
                        </span>
                    @else
                        {{ $row->kyc_documents_count }}
                    @endif
                </td>
                <td class="px-5 py-3 text-right">
                    @if($row->kyc_documents_count === 0)
                        <a href="{{ route('admin.distributors.show', $row->id) }}"
                            class="inline-flex items-center rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 font-medium px-3 py-1.5 text-xs transition-colors">
                            View profile →
                        </a>
                    @else
                        <a href="{{ route('admin.kyc.show', $row->id) }}"
                            class="inline-flex items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-3 py-1.5 text-xs transition-colors">
                            Review →
                        </a>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

<div class="mt-4">{{ $pending->links() }}</div>

@endsection
