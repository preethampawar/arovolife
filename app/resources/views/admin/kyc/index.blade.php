@extends('admin.layouts.admin')
@section('title', 'KYC review queue')
@section('heading', 'KYC review queue')

@section('content')

<p class="text-sm text-gray-600 mb-4">
    Distributors awaiting an admin decision on their PAN, Aadhaar, bank, and address-proof documents.
</p>

{{-- Tabs: "Pending" (new + resubmitted) vs "Rejected" (awaiting applicant
     resubmission). Rejected cases were previously hidden from the queue
     entirely, leaving no UI path back. --}}
<div class="flex items-center gap-2 mb-6">
    <a href="{{ route('admin.kyc.index', ['tab' => 'pending']) }}"
        class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors
            {{ $currentTab === 'rejected' ? 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50' : 'border-brand-500 bg-brand-500 text-white' }}">
        Pending review
        <span class="inline-flex items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold
            {{ $currentTab === 'rejected' ? 'bg-gray-100 text-gray-700' : 'bg-white/25 text-white' }}">
            {{ $pendingCount }}
        </span>
    </a>
    <a href="{{ route('admin.kyc.index', ['tab' => 'rejected']) }}"
        class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors
            {{ $currentTab === 'rejected' ? 'border-red-500 bg-red-500 text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50' }}">
        Rejected — awaiting re-upload
        <span class="inline-flex items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold
            {{ $currentTab === 'rejected' ? 'bg-white/25 text-white' : 'bg-gray-100 text-gray-700' }}">
            {{ $rejectedCount }}
        </span>
    </a>
</div>

<div class="rounded-2xl border border-gray-200 bg-white">
    @if($pending->isEmpty())
    <div class="p-8 text-center text-sm text-gray-500">
        @if($currentTab === 'rejected')
            No rejected submissions waiting on the applicant.
        @else
            No pending KYC submissions.
        @endif
    </div>
    @else
    <table class="w-full text-sm">
        <thead class="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-200">
            <tr>
                <th class="px-5 py-3 w-12">S.No</th>
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
                <td class="px-5 py-3 text-gray-500">{{ $loop->iteration }}</td>
                <td class="px-5 py-3 font-mono font-bold text-brand-600 tracking-widest">
                    {{ $row->adn }}
                    @if($resubmittedIds->contains($row->id) && $currentTab !== 'rejected')
                        <span class="ml-2 inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-[10px] font-medium text-amber-800 tracking-normal">
                            Resubmitted
                        </span>
                    @endif
                </td>
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
                    @if($row->kyc_documents_count === 0 && $currentTab !== 'rejected')
                        <a href="{{ route('admin.distributors.show', $row->id) }}"
                            class="inline-flex items-center rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 font-medium px-3 py-1.5 text-xs transition-colors">
                            View profile →
                        </a>
                    @else
                        <a href="{{ route('admin.kyc.show', $row->id) }}"
                            class="inline-flex items-center rounded-lg
                                {{ $currentTab === 'rejected' ? 'bg-red-500 hover:bg-red-600' : 'bg-brand-500 hover:bg-brand-600' }}
                                text-white font-medium px-3 py-1.5 text-xs transition-colors">
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
