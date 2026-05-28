@extends('admin.layouts.admin')
@section('title', 'Line-change review')
@section('heading', 'Line-change review')

@section('content')

@if($errors->any())
<div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-700">
    @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
</div>
@endif

<div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
    <p class="font-semibold mb-1">Binary placement change only</p>
    <p class="leading-relaxed">Approving this request moves the distributor's position in the binary
        tree under the requested parent. Their <strong>sponsor is not changed</strong>.</p>
</div>

<div class="rounded-2xl border border-gray-200 bg-white p-6 mb-6">
    <p class="text-xs text-gray-500 uppercase tracking-wider mb-3">Request</p>
    <dl class="text-sm grid grid-cols-2 gap-y-2">
        <dt class="text-gray-600">Requester name</dt>
        <dd class="text-gray-900">{{ $lcr->distributor?->user?->full_name ?? '—' }}</dd>

        <dt class="text-gray-600">Requester ADN</dt>
        <dd class="font-mono font-bold text-brand-600 tracking-widest">{{ $lcr->distributor?->adn ?? '—' }}</dd>

        <dt class="text-gray-600">Requester email</dt>
        <dd class="text-gray-900">{{ $lcr->distributor?->user?->email ?? '—' }}</dd>

        <dt class="text-gray-600">Current placement parent</dt>
        <dd class="text-gray-900">
            {{ $lcr->fromPlacementParent?->user?->full_name ?? '—' }}
            @if($lcr->fromPlacementParent?->adn)
                <span class="font-mono text-gray-600">({{ $lcr->fromPlacementParent->adn }})</span>
            @endif
        </dd>

        <dt class="text-gray-600">Requested placement parent</dt>
        <dd class="text-gray-900">
            {{ $lcr->toPlacementParent?->user?->full_name ?? '—' }}
            @if($lcr->toPlacementParent?->adn)
                <span class="font-mono text-gray-600">({{ $lcr->toPlacementParent->adn }})</span>
            @endif
        </dd>

        <dt class="text-gray-600">Reason given</dt>
        <dd class="text-gray-900">{{ $lcr->reason ?: '—' }}</dd>

        <dt class="text-gray-600">Requested at</dt>
        <dd class="text-gray-900">{{ $lcr->requested_at->format('d M Y H:i') }}</dd>

        <dt class="text-gray-600">Status</dt>
        <dd class="text-gray-900">{{ ucfirst($lcr->status) }}</dd>

        @if($lcr->status !== 'pending')
        <dt class="text-gray-600">Decision note</dt>
        <dd class="text-gray-900">{{ $lcr->decision_note ?: '—' }}</dd>
        @endif
    </dl>
</div>

@if($lcr->status === 'pending')
    @if($freeSides === [])
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900 mb-6">
        <p class="font-semibold mb-1">No free leg under the requested parent</p>
        <p>Both legs are taken, so this move cannot be approved. Reject it with a reason below.</p>
    </div>
    @else
    <div class="mb-6">
        <form method="POST" action="{{ route('admin.line-changes.approve', $lcr->id) }}"
            class="rounded-2xl border border-green-200 bg-green-50 p-6 space-y-3"
            data-confirm="Approve this line change and move the placement now?"
            data-confirm-title="Confirm approval"
            data-confirm-impact="Moves the distributor's binary placement under the requested parent on the chosen leg. Sponsor is unchanged. This cannot be undone via this screen.">
            @csrf
            <p class="text-base font-semibold text-green-800">Approve & move placement</p>
            <label class="block text-xs text-green-800">
                Leg to place on
                <x-help-tip text="Which side of the requested parent to attach the distributor to. Only free legs are listed; the first free leg is preselected." />
            </label>
            <select name="chosen_side" required
                class="w-full rounded-lg border border-green-300 bg-white px-3 py-2 text-sm focus:border-green-500 focus:ring-green-500">
                @foreach($freeSides as $s)
                    <option value="{{ $s }}">{{ $s === 'L' ? 'Left (L)' : 'Right (R)' }}</option>
                @endforeach
            </select>
            <button type="submit"
                class="w-full inline-flex justify-center items-center rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium px-4 py-2.5 text-sm transition-colors">
                Approve
            </button>
        </form>
    </div>
    @endif

    <form method="POST" action="{{ route('admin.line-changes.reject', $lcr->id) }}"
        class="rounded-2xl border border-red-200 bg-red-50 p-6 space-y-3"
        data-confirm="Reject this line-change request?"
        data-confirm-title="Confirm rejection"
        data-confirm-impact="The distributor is emailed your reason. No placement changes.">
        @csrf
        <p class="text-base font-semibold text-red-800">Reject request</p>
        <label class="block text-xs text-red-800">
            Reason
            <x-help-tip text="Sent verbatim to the distributor. 8–1024 characters." />
        </label>
        <p class="text-xs text-red-700">This reason is emailed verbatim to the distributor.</p>
        <textarea name="decision_note" required minlength="8" maxlength="1024" rows="3"
            class="w-full rounded-lg border border-red-300 bg-white px-3 py-2 text-sm focus:border-red-500 focus:ring-red-500"
            placeholder="e.g. The requested parent is not eligible for this move."></textarea>
        <button type="submit"
            class="w-full inline-flex justify-center items-center rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium px-4 py-2.5 text-sm transition-colors">
            Reject
        </button>
    </form>
@endif

<a href="{{ route('admin.line-changes.index') }}" class="inline-block mt-6 text-sm text-gray-500 hover:text-gray-700">
    ← Back to queue
</a>

@endsection
