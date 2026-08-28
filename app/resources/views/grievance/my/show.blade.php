@extends('layouts.app')
@section('title', 'Grievance '.$ticket->ticket_no)

@section('content')
<div class="max-w-3xl mx-auto">

    <a href="{{ route('my.grievances.index') }}" class="text-sm text-brand-700 underline">← All grievances</a>

    <div class="mt-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="font-mono text-sm font-semibold text-gray-600">{{ $ticket->ticket_no }}</p>
            <h1 class="text-2xl font-bold text-gray-900">{{ $ticket->subject }}</h1>
        </div>
        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $ticket->status->badgeClasses() }}">
            {{ $ticket->status->label() }}
        </span>
    </div>

    @if (session('status'))
        <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @error('note')
        <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>
    @enderror

    <dl class="mt-6 grid gap-4 sm:grid-cols-4 rounded-2xl border border-gray-200 bg-white p-5 text-sm shadow-sm">
        <div>
            <dt class="text-gray-600">Category</dt>
            <dd class="font-medium text-gray-900">{{ $ticket->category->label() }}</dd>
        </div>
        <div>
            <dt class="text-gray-600">Raised</dt>
            <dd class="font-medium text-gray-900">{{ $ticket->created_at->format('d M Y') }}</dd>
        </div>
        <div>
            <dt class="text-gray-600">Currently with</dt>
            <dd class="font-medium text-gray-900">{{ $ticket->escalation_level->label() }}</dd>
        </div>
        <div>
            <dt class="text-gray-600">Resolve by</dt>
            <dd class="font-medium text-gray-900">{{ $ticket->sla_resolution_at?->format('d M Y') ?? '—' }}</dd>
        </div>
    </dl>

    @if ($ticket->third_party_dependent)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            This grievance is waiting on a third party (a bank, payment gateway or authority). The resolution
            window is extended to 60 days and you will hear from us at least every 15 days.
        </div>
    @endif

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-900 mb-2">What you told us</h2>
        <p class="whitespace-pre-line text-sm text-gray-700">{{ $ticket->body }}</p>

        @if ($ticket->attachments->isNotEmpty())
            <h3 class="mt-5 text-sm font-semibold text-gray-900 mb-2">Evidence you attached</h3>
            <ul class="space-y-1.5 text-sm">
                @foreach ($ticket->attachments as $attachment)
                    <li>
                        <a href="{{ route('my.grievances.attachment', [$ticket->id, $attachment->id]) }}"
                           class="text-brand-700 underline">{{ $attachment->original_name }}</a>
                        <span class="text-gray-600">({{ $attachment->humanSize() }})</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($ticket->resolution_note)
        <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-6">
            <h2 class="text-sm font-semibold text-emerald-900 mb-2">How we resolved it</h2>
            <p class="whitespace-pre-line text-sm text-emerald-900">{{ $ticket->resolution_note }}</p>
            <p class="mt-3 text-xs text-emerald-800">
                Not satisfied? The escalation route is in the
                <a href="{{ route('content.show', 'grievance') }}" class="underline">Grievance Redressal Policy</a>.
                You may also approach a statutory authority directly.
            </p>
        </div>
    @endif

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-900 mb-4">History</h2>
        <ol class="space-y-4 border-l-2 border-gray-100 pl-4">
            @foreach ($timeline as $event)
                <li class="text-sm">
                    <p class="font-medium text-gray-900">{{ $event->kind->label() }}</p>
                    @if ($event->note)
                        <p class="whitespace-pre-line text-gray-700">{{ $event->note }}</p>
                    @endif
                    <p class="text-xs text-gray-600">{{ $event->created_at->format('d M Y, H:i') }}</p>
                </li>
            @endforeach
        </ol>
    </div>

    @if ($ticket->status->acceptsComplainantReply())
        <form method="POST" action="{{ route('my.grievances.reply', $ticket->id) }}" enctype="multipart/form-data"
              class="mt-6 space-y-3 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            <label for="note" class="block text-sm font-medium text-gray-700">Add something to this grievance</label>
            <textarea id="note" name="note" rows="4" maxlength="2000" required
                      class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500"></textarea>
            <input name="attachments[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">

            @if ($ticket->escalation_level->next())
                {{-- Policy §4 tells complainants that replying is how they
                     escalate. This is that promise, made explicit. --}}
                <label class="flex items-start gap-2.5 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <input type="checkbox" name="request_escalation" value="1"
                           class="mt-0.5 rounded border-amber-300 text-amber-700 focus:ring-amber-500">
                    <span>
                        <span class="font-medium">Escalate this to the {{ $ticket->escalation_level->next()->label() }}.</span>
                        Use this if you are not satisfied with how it is being handled.
                    </span>
                </label>
            @endif

            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Add reply
            </button>
        </form>
    @endif
</div>
@endsection
