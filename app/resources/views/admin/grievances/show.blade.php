@extends('admin.layouts.admin')
@section('title', 'Grievance '.$ticket->ticket_no)
@section('heading', 'Grievance '.$ticket->ticket_no)

@section('content')
<a href="{{ route('admin.grievances.index') }}" class="text-sm text-sunrise-400 underline">← Grievance queue</a>

@if (session('status'))
    <div class="mt-4 rounded-lg border border-emerald-600/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="mt-4 rounded-lg border border-rose-600/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
        {{ $errors->first() }}
    </div>
@endif

<div class="mt-5 grid gap-6 lg:grid-cols-3">

    {{-- ── Left: the complaint and its history ─────────────────────────────── --}}
    <div class="space-y-6 lg:col-span-2">

        <div class="rounded-xl border border-slate-700 bg-slate-900/40 p-6">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <h2 class="text-lg font-semibold text-slate-100">{{ $ticket->subject }}</h2>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $ticket->status->badgeClasses() }}">
                    {{ $ticket->status->label() }}
                </span>
            </div>
            <p class="whitespace-pre-line text-sm leading-relaxed text-slate-300">{{ $ticket->body }}</p>

            @if ($ticket->attachments->isNotEmpty())
                <h3 class="mt-5 text-xs font-semibold uppercase tracking-wider text-slate-400">Evidence</h3>
                <ul class="mt-2 space-y-1.5 text-sm">
                    @foreach ($ticket->attachments as $attachment)
                        <li>
                            <a href="{{ route('admin.grievances.attachment', [$ticket->id, $attachment->id]) }}"
                               class="text-sunrise-400 underline">{{ $attachment->original_name }}</a>
                            <span class="text-slate-500">({{ $attachment->humanSize() }}) — opening this is audit-logged</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="rounded-xl border border-slate-700 bg-slate-900/40 p-6">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-400">Timeline</h2>
            <ol class="space-y-4 border-l-2 border-slate-800 pl-4">
                @foreach ($timeline as $event)
                    <li class="text-sm">
                        <p class="font-medium text-slate-200">
                            {{ $event->kind->label() }}
                            @if (! $event->kind->isVisibleToComplainant())
                                <span class="ml-1 rounded bg-slate-700 px-1.5 py-0.5 text-[10px] uppercase text-slate-300">internal</span>
                            @endif
                        </p>
                        @if ($event->note)
                            <p class="whitespace-pre-line text-slate-400">{{ $event->note }}</p>
                        @endif
                        <p class="text-xs text-slate-500">
                            {{ $event->created_at->format('d M Y, H:i') }}
                            @if ($event->actor)
                                · {{ $event->actor->full_name ?? $event->actor->email }}
                            @endif
                        </p>
                    </li>
                @endforeach
            </ol>
        </div>

        @if (! $ticket->status->isSettled())
            <form method="POST" action="{{ route('admin.grievances.respond', $ticket->id) }}"
                  class="space-y-3 rounded-xl border border-slate-700 bg-slate-900/40 p-6"
                  data-confirm="Send this response?"
                  data-confirm-title="Record a response"
                  data-confirm-impact="This stops the first-response SLA clock and is visible to the complainant.">
                @csrf
                <label for="note" class="block text-sm font-medium text-slate-300">Respond</label>
                <textarea id="note" name="note" rows="4" maxlength="5000" required
                          class="w-full rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100"></textarea>
                <button type="submit" class="rounded-lg bg-sunrise-500 px-4 py-2 text-sm font-semibold text-white hover:bg-sunrise-600">
                    Record response
                </button>
            </form>
        @endif

        {{-- Internal notes stay available after resolution: an investigation
             often outlives the answer given to the complainant. --}}
        <form method="POST" action="{{ route('admin.grievances.internal-note', $ticket->id) }}"
              class="space-y-3 rounded-xl border border-slate-700 bg-slate-900/40 p-6">
            @csrf
            <label for="internal_note" class="block text-sm font-medium text-slate-300">
                Internal note
                <x-help-tip text="Never shown to the complainant. Use this for findings that name another distributor or a staff member." />
            </label>
            <textarea id="internal_note" name="note" rows="3" maxlength="5000" required
                      placeholder="What you found, who you spoke to, what is still open"
                      class="w-full rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100 placeholder-slate-500"></textarea>
            <button type="submit" class="rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">
                Add internal note
            </button>
        </form>
    </div>

    {{-- ── Right: facts and actions ────────────────────────────────────────── --}}
    <div class="space-y-6">

        <div class="rounded-xl border border-slate-700 bg-slate-900/40 p-5 text-sm">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-400">Complaint</h2>
            <dl class="space-y-2.5">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Complainant</dt>
                    <dd class="text-right text-slate-200">{{ $ticket->displayReporter() }}</dd>
                </div>
                @unless ($ticket->is_anonymous)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Email</dt>
                        <dd class="text-right text-slate-200">{{ $ticket->reporter_email ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Phone</dt>
                        <dd class="text-right text-slate-200">{{ $ticket->reporter_phone ?? '—' }}</dd>
                    </div>
                @endunless
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Distributor</dt>
                    <dd class="text-right text-slate-200">
                        @if ($ticket->distributor)
                            <a href="{{ route('admin.distributors.show', $ticket->distributor->id) }}" class="text-sunrise-400 underline">
                                {{ $ticket->distributor->adn }}
                            </a>
                        @else — @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Channel</dt>
                    <dd class="text-right text-slate-200">{{ $ticket->channel->label() }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Category</dt>
                    <dd class="text-right text-slate-200">{{ $ticket->category->label() }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Escalation step</dt>
                    <dd class="text-right text-slate-200">{{ $ticket->escalation_level->value }} — {{ $ticket->escalation_level->label() }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-slate-800 pt-2.5">
                    <dt class="text-slate-500">Handled by</dt>
                    <dd class="text-right text-slate-200">
                        {{ $ticket->assignedTo?->full_name ?? $ticket->assignedTo?->email ?? 'Unassigned' }}
                    </dd>
                </div>
            </dl>

            @unless ($ticket->status->isSettled())
                <form method="POST" action="{{ route('admin.grievances.assign', $ticket->id) }}" class="mt-3">
                    @csrf
                    @if ($ticket->assigned_to_user_id === auth()->id())
                        <button type="submit" name="assigned_to_user_id" value=""
                                class="w-full rounded-lg border border-slate-700 px-3 py-1.5 text-xs text-slate-300 hover:bg-slate-800">
                            Release this grievance
                        </button>
                    @else
                        <button type="submit" name="assigned_to_user_id" value="{{ auth()->id() }}"
                                class="w-full rounded-lg border border-slate-700 px-3 py-1.5 text-xs text-slate-300 hover:bg-slate-800">
                            Assign to me
                        </button>
                    @endif
                </form>
            @endunless
        </div>

        <div class="rounded-xl border border-slate-700 bg-slate-900/40 p-5 text-sm">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-400">SLA clocks</h2>
            <dl class="space-y-2.5">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Acknowledged</dt>
                    <dd class="text-right {{ $ticket->acknowledgement_breached_at ? 'text-rose-300' : 'text-slate-200' }}">
                        {{ $ticket->acknowledged_at?->format('d M, H:i') ?? 'due '.$ticket->sla_acknowledgement_at?->format('d M, H:i') }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">First response</dt>
                    <dd class="text-right {{ $ticket->first_response_breached_at ? 'text-rose-300' : 'text-slate-200' }}">
                        {{ $ticket->first_response_at?->format('d M') ?? 'due '.$ticket->sla_first_response_at?->format('d M') }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Resolution</dt>
                    <dd class="text-right {{ $ticket->resolution_breached_at ? 'text-rose-300' : 'text-slate-200' }}">
                        {{ $ticket->resolved_at?->format('d M') ?? 'due '.$ticket->sla_resolution_at?->format('d M') }}
                    </dd>
                </div>
                @if ($statusUpdateDueAt)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Next progress update</dt>
                        <dd class="text-right text-slate-200">{{ $statusUpdateDueAt->format('d M') }}</dd>
                    </div>
                @endif
                @if ($ticket->retention_until)
                    <div class="flex justify-between gap-3 border-t border-slate-800 pt-2.5">
                        <dt class="text-slate-500">Retain until</dt>
                        <dd class="text-right text-slate-200">{{ $ticket->retention_until->format('d M Y') }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        @if (! $ticket->status->isSettled())
            <div class="space-y-4 rounded-xl border border-slate-700 bg-slate-900/40 p-5">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Actions</h2>

                @if ($nextLevel)
                    <form method="POST" action="{{ route('admin.grievances.escalate', $ticket->id) }}" class="space-y-2"
                          data-confirm="Escalate to the {{ $nextLevel->label() }}?"
                          data-confirm-title="Escalate this grievance"
                          data-confirm-impact="The {{ $nextLevel->label() }} will be notified and takes ownership.">
                        @csrf
                        <input type="text" name="reason" maxlength="1000" placeholder="Reason (optional)"
                               class="w-full rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100 placeholder-slate-500">
                        <button type="submit" class="w-full rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">
                            Escalate to {{ $nextLevel->label() }}
                        </button>
                    </form>
                @else
                    <p class="text-xs leading-relaxed text-slate-500">
                        This grievance is with the Compliance Committee — the last internal step. Steps 5 to 8 of
                        the published matrix are statutory authorities the complainant approaches directly.
                    </p>
                @endif

                @unless ($ticket->third_party_dependent)
                    <form method="POST" action="{{ route('admin.grievances.third-party', $ticket->id) }}" class="space-y-2"
                          data-confirm="Extend the resolution window to 60 days?"
                          data-confirm-title="Flag a third-party dependency"
                          data-confirm-impact="This can only be done once. A progress update to the complainant becomes due every 15 days.">
                        @csrf
                        <input type="text" name="reason" maxlength="1000" required
                               placeholder="Which third party, and why"
                               class="w-full rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100 placeholder-slate-500">
                        <button type="submit" class="w-full rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">
                            Waiting on a third party
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.grievances.status-update', $ticket->id) }}" class="space-y-2"
                          data-confirm="Send this progress update?"
                          data-confirm-title="Progress update"
                          data-confirm-impact="Emailed to the complainant and recorded on the timeline.">
                        @csrf
                        <textarea name="note" rows="3" maxlength="2000" required placeholder="Where the matter stands"
                                  class="w-full rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100 placeholder-slate-500"></textarea>
                        <button type="submit" class="w-full rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">
                            Send progress update
                        </button>
                    </form>
                @endunless

                <form method="POST" action="{{ route('admin.grievances.resolve', $ticket->id) }}" class="space-y-2"
                      data-confirm="Resolve this grievance?"
                      data-confirm-title="Record the resolution"
                      data-confirm-impact="The complainant is emailed the outcome together with how to escalate if they disagree.">
                    @csrf
                    <textarea name="resolution_note" rows="3" maxlength="5000" required
                              placeholder="What was done to resolve it"
                              class="w-full rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100 placeholder-slate-500"></textarea>
                    <button type="submit" class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                        Resolve
                    </button>
                </form>
            </div>
        @elseif ($ticket->status === \App\Modules\Grievance\Enums\TicketStatus::Resolved)
            <form method="POST" action="{{ route('admin.grievances.close', $ticket->id) }}"
                  class="space-y-2 rounded-xl border border-slate-700 bg-slate-900/40 p-5"
                  data-confirm="Close this grievance?"
                  data-confirm-title="Final closure"
                  data-confirm-impact="Starts the 7-year retention clock. The complainant can no longer reply on this complaint.">
                @csrf
                <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Close</h2>
                <input type="text" name="note" maxlength="1000" placeholder="Closing note (optional)"
                       class="w-full rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100 placeholder-slate-500">
                <button type="submit" class="w-full rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">
                    Close grievance
                </button>
            </form>
        @endif
    </div>
</div>

<x-confirm-modal />
@endsection
