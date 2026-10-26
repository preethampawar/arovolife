@extends('admin.layouts.admin')
@section('title', 'Plan change — ' . $page->title)
@section('heading', 'Proposed change: ' . $page->title)

@section('content')

<div class="max-w-4xl">

    <div class="mb-4">
        <a href="{{ route('admin.content.edit', $page) }}" class="text-sm text-gray-600 hover:text-gray-900">{{ svg('lucide-arrow-left', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} Back to the page</a>
    </div>

    @php
        $stateLabel = [
            'pending' => 'Waiting for approval',
            'approved' => 'Approved — live',
            'rejected' => 'Rejected',
            'withdrawn' => 'Withdrawn',
        ][$revision->state] ?? $revision->state;
    @endphp

    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
            <h2 class="text-base font-semibold text-gray-900">{{ $stateLabel }}</h2>
            <span class="text-xs text-gray-600">Proposed by {{ $revision->requester?->full_name ?? 'a removed account' }} on {{ $revision->created_at->format('d M Y, H:i') }}</span>
        </div>

        <h3 class="text-sm font-medium text-gray-700 mb-1">What changed, and why</h3>
        <p class="text-sm text-gray-900 whitespace-pre-line mb-4">{{ $revision->note }}</p>

        @if($revision->decided_at)
        <h3 class="text-sm font-medium text-gray-700 mb-1">Decision</h3>
        <p class="text-sm text-gray-900 mb-1">{{ ucfirst($revision->state) }} by {{ $revision->decider?->full_name ?? 'a removed account' }} on {{ $revision->decided_at->format('d M Y, H:i') }}.</p>
        @if($revision->decision_note)
        <p class="text-sm text-gray-900 whitespace-pre-line">{{ $revision->decision_note }}</p>
        @endif
        @endif

        @if($changed !== [] && $revision->isPending())
        <p class="text-xs text-gray-600 mt-2">Changes: {{ collect($changed)->map(fn ($field) => ['title' => 'title', 'meta_description' => 'search description', 'status' => 'status', 'body' => 'page text'][$field])->join(', ') }}.</p>
        @endif
        @if(in_array('status', $changed, true) && $revision->isPending())
        <p class="text-sm font-semibold text-red-800 mt-2">This change also moves the page from {{ $page->status }} to {{ $revision->status }}.</p>
        @endif
    </div>

    @if($revision->isPending())
        @if($problem)
        <div class="rounded-xl border border-red-300 bg-red-50 p-4 mb-6 text-sm text-red-800" role="alert">
            <p class="font-semibold mb-1">This change cannot be approved as it stands.</p>
            <p class="leading-relaxed">{{ $problem }}</p>
        </div>
        @endif
        @unless($noticeOn)
        <div class="rounded-xl border border-red-300 bg-red-50 p-4 mb-6 text-sm text-red-800" role="alert">
            The plan-change notice is switched off, so distributors would not be told about this change. It cannot be approved until the notice is switched on.
        </div>
        @endunless
    @endif

    @if($revision->isPending() && $page->status === 'published' && $revision->status !== 'published')
    <div class="rounded-xl border border-red-300 bg-red-50 p-4 mb-6 text-sm text-red-800" role="alert">
        Approving this takes the Compensation Plan off the site. Registration stops until it is published again, because every joiner has to agree to it.
    </div>
    @endif

    {{-- Raw output is safe here: the proposed body was cleaned with the
         `policy` purifier profile when it was proposed, and the entry is
         parsed from that body. --}}
    @if($newestEntry && in_array('body', $changed, true))
    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 mb-6 text-sm text-amber-900">
        <p class="font-semibold mb-1">What distributors will be told: {{ $newestEntry->label }}</p>
        <div class="policy-editor-surface">{!! $newestEntry->html !!}</div>
        <p class="mt-2 text-xs">Check any date this gives. A change applies from the start of the day after it is approved, unless it gives a later date. It must never give a date before that.</p>
    </div>
    @endif

    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="text-base font-semibold text-gray-900 mb-1">What is different in the text</h2>
        <p class="text-xs text-gray-600 mb-4">Paragraphs, headings, list items and table rows that were removed or added. Everything else stays as it is.</p>
        @if($diff === [])
        <p class="text-sm text-gray-600">The text is unchanged.</p>
        @else
        <ul class="space-y-1.5 text-sm">
            @foreach($diff as $row)
            <li class="rounded-md px-3 py-1.5 {{ $row['type'] === 'added' ? 'bg-green-50 text-green-900' : 'bg-red-50 text-red-900 line-through decoration-red-400' }}">
                <span class="sr-only">{{ $row['type'] === 'added' ? 'Added:' : 'Removed:' }}</span>
                <span aria-hidden="true" class="font-mono mr-1.5 no-underline">{{ $row['type'] === 'added' ? '+' : '−' }}</span>{{ $row['text'] }}
            </li>
            @endforeach
        </ul>
        @endif
    </div>

    @if($revision->isPending())
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-2">
        @if($canDecide && ! $isRequester)
        <form method="POST" action="{{ route('admin.content.plan-changes.approve', $revision) }}" class="bg-white rounded-2xl border border-green-200 p-6"
            data-confirm="Approve this plan change?"
            data-confirm-title="Confirm approval"
            data-confirm-impact="The change goes live on the Compensation Plan now. Every distributor is shown it at their next sign-in and emailed after 3 days if they have not confirmed. It applies from the start of tomorrow unless it gives a later date.">
            @csrf
            <h3 class="text-sm font-semibold text-green-800 mb-2">Approve</h3>
            <label for="approve_note" class="block text-xs text-gray-600 mb-1">Note (optional)</label>
            <textarea id="approve_note" name="decision_note" rows="2" maxlength="1000"
                class="w-full rounded-lg bg-white border border-gray-200 px-3 py-2 text-sm mb-3 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"></textarea>
            <x-ui.button :disabled="$problem !== null || ! $noticeOn">Approve and publish</x-ui.button>
        </form>
        @endif

        @if($canDecide)
        <form method="POST" action="{{ route('admin.content.plan-changes.reject', $revision) }}" class="bg-white rounded-2xl border border-red-200 p-6"
            data-confirm="Reject this plan change?"
            data-confirm-title="Confirm rejection"
            data-confirm-impact="The change is closed and the live page stays as it is. The person who proposed it can propose a corrected one.">
            @csrf
            <h3 class="text-sm font-semibold text-red-700 mb-2">Reject</h3>
            <label for="reject_note" class="block text-xs text-gray-600 mb-1">Why? <span class="text-red-600">*</span></label>
            <textarea id="reject_note" name="decision_note" rows="2" maxlength="1000" required
                class="w-full rounded-lg bg-white border border-gray-200 px-3 py-2 text-sm mb-3 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"></textarea>
            @error('decision_note')<p class="mb-2 text-xs text-red-700">{{ $message }}</p>@enderror
            <x-ui.button variant="danger">Reject</x-ui.button>
        </form>
        @endif

        @if($isRequester)
        <form method="POST" action="{{ route('admin.content.plan-changes.withdraw', $revision) }}" class="bg-white rounded-2xl border border-gray-200 p-6"
            data-confirm="Withdraw your change?"
            data-confirm-title="Confirm withdrawal"
            data-confirm-impact="Your proposed change is closed and the live page stays as it is. You can propose a new one afterwards.">
            @csrf
            <h3 class="text-sm font-semibold text-gray-800 mb-2">Withdraw</h3>
            <p class="text-xs text-gray-600 mb-3">You proposed this change, so another person has to approve it. You can withdraw it to fix something and propose it again.</p>
            <x-ui.button variant="secondary">Withdraw</x-ui.button>
        </form>
        @endif
    </div>
    @endif

    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-900 mb-1">{{ $revision->title }}</h2>
        <p class="text-xs text-gray-600 mb-4">The whole page as proposed.</p>
        <div class="page-body policy-editor-surface">{!! $revision->body !!}</div>
    </div>

</div>

@include('admin.content._policy-styles')

@endsection
