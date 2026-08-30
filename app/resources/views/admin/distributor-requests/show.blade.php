@extends('admin.layouts.admin')
@section('title', 'Request '.$item->request_no)
@section('heading', $item->typeLabel().' — '.$item->request_no)

@section('content')
@php
    use App\Modules\Identity\Models\DistributorRequest;
    $badge = [
        'submitted' => 'bg-amber-100 text-amber-800', 'under_review' => 'bg-blue-100 text-blue-800',
        'approved' => 'bg-green-100 text-green-700', 'rejected' => 'bg-red-100 text-red-700',
    ];
    $distributor = $item->distributor;
    $ist = fn ($t) => $t?->timezone('Asia/Kolkata')->format('d M Y, H:i') ?? '—';
    $btn = 'px-4 py-2 rounded-lg text-sm font-medium transition-colors';
    $before = $item->snapshot_before ?? [];
    $d = $item->details;
@endphp

<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('admin.distributor-requests.index') }}" class="text-sm text-gray-600 hover:text-gray-700">← All requests</a>
    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badge[$item->status] ?? 'bg-gray-100 text-gray-600' }}">{{ $item->statusLabel() }}</span>
</div>

@if(session('success'))
<div class="rounded-lg border border-green-200 bg-green-50 p-3 mb-4 text-sm text-green-800">{{ session('success') }}</div>
@endif
@if($errors->any())
<div class="rounded-lg border border-red-200 bg-red-50 p-3 mb-4 text-sm text-red-700">
    <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Distributor</h2>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <dt class="text-gray-500">Name on record</dt><dd>{{ $distributor->user->full_name ?? '—' }}</dd>
                <dt class="text-gray-500">ADN</dt><dd class="font-mono"><a href="{{ route('admin.distributors.show', $distributor->id) }}" class="text-brand-700 hover:text-brand-800">{{ $distributor->adn }}</a></dd>
                <dt class="text-gray-500">Date of birth on record</dt><dd>{{ $distributor->user->date_of_birth ? \Illuminate\Support\Carbon::parse($distributor->user->date_of_birth)->format('d M Y') : '—' }}</dd>
                <dt class="text-gray-500">PAN</dt><dd class="font-mono">{{ $distributor->pan_masked ?? '—' }}</dd>
                <dt class="text-gray-500">Email</dt><dd>{{ $distributor->user->email ?? '—' }}</dd>
                <dt class="text-gray-500">Mobile</dt><dd class="font-mono">{{ $distributor->user->phone_e164 ?? '—' }}</dd>
                <dt class="text-gray-500">Account status</dt><dd>{{ $distributor->status }}</dd>
            </dl>
        </section>

        <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Request</h2>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <dt class="text-gray-500">Type</dt><dd class="font-medium">{{ $item->typeLabel() }}</dd>
                @if(in_array($item->type, [DistributorRequest::TYPE_NAME_CORRECTION, DistributorRequest::TYPE_NAME_CHANGE], true))
                <dt class="text-gray-500">Name when filed</dt><dd>{{ $before['full_name'] ?? '—' }}</dd>
                <dt class="text-gray-500">Requested name</dt><dd class="font-medium">{{ $d['requested_full_name'] ?? '—' }}</dd>
                @elseif($item->type === DistributorRequest::TYPE_DOB_CORRECTION)
                <dt class="text-gray-500">DOB when filed</dt><dd>{{ ! empty($before['date_of_birth']) ? \Illuminate\Support\Carbon::parse($before['date_of_birth'])->format('d M Y') : '—' }}</dd>
                <dt class="text-gray-500">Requested DOB</dt><dd class="font-medium">{{ ! empty($d['requested_date_of_birth']) ? \Illuminate\Support\Carbon::parse($d['requested_date_of_birth'])->format('d M Y') : '—' }}</dd>
                @elseif($item->type === DistributorRequest::TYPE_MEMBERSHIP_TRANSFER)
                <dt class="text-gray-500">Transfer to</dt><dd class="font-medium">{{ $d['transferee_name'] ?? '—' }}</dd>
                <dt class="text-gray-500">Relationship</dt><dd>{{ DistributorRequest::RELATIONSHIPS[$d['relationship'] ?? ''] ?? '—' }}</dd>
                <dt class="text-gray-500">Relation's mobile</dt><dd class="font-mono">{{ $d['transferee_mobile'] ?? '—' }}</dd>
                <dt class="text-gray-500">Relation's email</dt><dd>{{ $d['transferee_email'] ?? '—' }}</dd>
                @endif
                <dt class="text-gray-500">Reason</dt><dd class="whitespace-pre-line">{{ $item->reason }}</dd>
            </dl>
        </section>

        <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Documents</h2>
            @if($item->documents->isEmpty())
                <p class="text-sm text-gray-600">No documents on file{{ $item->type === DistributorRequest::TYPE_ID_CANCELLATION ? ' — none are required for a cancellation' : '' }}.</p>
            @else
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($item->documents as $doc)
                <li class="py-2 flex items-center justify-between gap-3">
                    <div>
                        <p class="font-medium text-gray-900">{{ $doc->typeLabel() }}</p>
                        <p class="text-xs text-gray-500">{{ $doc->original_name }} · {{ \App\Modules\Shared\Support\IndianNumber::format(intdiv($doc->size_bytes, 1024)) }} KB · uploaded {{ $ist($doc->created_at) }}</p>
                    </div>
                    <a href="{{ route('admin.distributor-requests.document', [$item, $doc]) }}" target="_blank" rel="noopener" class="text-brand-700 hover:text-brand-800 font-medium whitespace-nowrap">Open ↗</a>
                </li>
                @endforeach
            </ul>
            @endif
        </section>
    </div>

    <div class="space-y-6">
        <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Decision</h2>
            <dl class="text-xs grid grid-cols-2 gap-y-1.5 text-gray-700 mb-4">
                <dt class="text-gray-500">Filed</dt><dd>{{ $ist($item->submitted_at) }}</dd>
                <dt class="text-gray-500">Last reviewed</dt><dd>{{ $ist($item->reviewed_at) }}</dd>
                <dt class="text-gray-500">Reviewed by</dt><dd>{{ $item->reviewedBy?->full_name ?? '—' }}</dd>
                @if($item->applied_at)<dt class="text-gray-500">Record updated</dt><dd>{{ $ist($item->applied_at) }}</dd>@endif
            </dl>
            @if($item->admin_notes)
            <div class="rounded-lg bg-gray-50 border border-gray-200 p-3 text-sm text-gray-700 mb-4">
                <p class="text-xs text-gray-500 mb-1">Note / reason shown to the distributor</p>{{ $item->admin_notes }}
            </div>
            @endif

            @if($item->appliesOnApproval())
            <p class="text-xs text-gray-600 mb-3">Approving <strong>updates the distributor's record</strong> to the requested value and writes a before/after audit entry. Check the document first — the new value must match it exactly.</p>
            @else
            <p class="text-xs text-gray-600 mb-3">Approving <strong>does not change the record</strong>. It acknowledges the request; then carry it out from the <a href="{{ route('admin.distributors.show', $distributor->id) }}" class="text-brand-700 underline">distributor's page</a> ({{ $item->type === DistributorRequest::TYPE_ID_CANCELLATION ? 'Terminate / close the account' : 'the relation registers and completes KYC; then transfer the position' }}).</p>
            @endif

            @if(! $canDecide)
                <p class="text-xs text-gray-500">Deciding this request needs the <code>{{ $item->decidePermission() }}</code> permission.</p>
            @elseif($item->isOpen())
            <div class="space-y-3">
                @if($item->status === DistributorRequest::STATUS_SUBMITTED)
                <form method="POST" action="{{ route('admin.distributor-requests.decide', [$item, 'review']) }}"
                      data-confirm="Mark this request as under review?" data-confirm-title="Start review" data-confirm-impact="Tells the queue someone is looking at it. No email is sent.">
                    @csrf
                    <button type="submit" class="{{ $btn }} w-full border border-gray-300 text-gray-700 hover:bg-gray-50">Mark under review</button>
                </form>
                @endif

                <form method="POST" action="{{ route('admin.distributor-requests.decide', [$item, 'approve']) }}"
                      data-confirm="Approve this request?" data-confirm-title="Approve request"
                      data-confirm-impact="{{ $item->appliesOnApproval() ? 'Updates the distributor\'s record to: '.$item->requestedSummary().'. Audit-logged and emailed to the distributor.' : 'Emails the distributor that the request is approved. You then carry it out from their page.' }}">
                    @csrf
                    <label class="block text-xs text-gray-600 mb-1">Note to distributor (optional)</label>
                    <input type="text" name="reason" maxlength="1000" class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm mb-2">
                    <button type="submit" class="{{ $btn }} w-full bg-green-600 text-white hover:bg-green-700">Approve</button>
                </form>

                <form method="POST" action="{{ route('admin.distributor-requests.decide', [$item, 'reject']) }}"
                      data-confirm="Reject this request?" data-confirm-title="Reject request" data-confirm-impact="The distributor is emailed your reason. They may file again with better documents.">
                    @csrf
                    <label class="block text-xs text-gray-600 mb-1">Reason <span class="text-red-500">*</span></label>
                    <textarea name="reason" rows="2" maxlength="1000" required class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm mb-2"></textarea>
                    <button type="submit" class="{{ $btn }} w-full bg-red-600 text-white hover:bg-red-700">Reject</button>
                </form>
            </div>
            @else
                <p class="text-sm text-gray-600">This request is closed.</p>
            @endif
        </section>
    </div>
</div>
@endsection
