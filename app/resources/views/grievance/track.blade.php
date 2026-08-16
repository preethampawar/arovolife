<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Track your grievance — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <div class="max-w-2xl mx-auto px-6 py-12">

        <h1 class="text-2xl font-bold text-gray-900 mb-2">Track your grievance</h1>
        <p class="text-sm text-gray-600 mb-8">
            Enter your complaint number and the email you filed it with.
        </p>

        @if (session('status'))
            <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('grievance.track.lookup') }}"
              class="mb-10 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="ticket_no" class="block text-sm font-medium text-gray-700 mb-1.5">Complaint number</label>
                    <input id="ticket_no" name="ticket_no" type="text" required
                           value="{{ old('ticket_no', $ticketNo ?? '') }}" placeholder="GRV-260816-7KQ2M"
                           class="w-full rounded-lg border-gray-300 font-mono text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">Email used</label>
                    <input id="email" name="email" type="email" required value="{{ old('email') }}"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
            </div>
            <button type="submit" class="rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-600">
                Look up
            </button>
        </form>

        @if (! empty($notFound))
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
                We could not find a grievance matching that complaint number and email. Check both, or write to
                <a href="mailto:grievance@arovolife.com" class="underline">grievance@arovolife.com</a> quoting the number.
            </div>
        @endif

        @isset($ticket)
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                    <div>
                        <p class="font-mono text-sm font-semibold text-gray-500">{{ $ticket->ticket_no }}</p>
                        <h2 class="text-lg font-semibold text-gray-900">{{ $ticket->subject }}</h2>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $ticket->status->badgeClasses() }}">
                        {{ $ticket->status->label() }}
                    </span>
                </div>

                <dl class="grid gap-3 sm:grid-cols-2 text-sm mb-6">
                    <div>
                        <dt class="text-gray-500">Category</dt>
                        <dd class="text-gray-900">{{ $ticket->category->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Filed on</dt>
                        <dd class="text-gray-900">{{ $ticket->created_at->format('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Currently with</dt>
                        <dd class="text-gray-900">{{ $ticket->escalation_level->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Resolution due</dt>
                        <dd class="text-gray-900">{{ $ticket->sla_resolution_at?->format('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>

                <h3 class="text-sm font-semibold text-gray-900 mb-3">History</h3>
                <ol class="space-y-3 border-l-2 border-gray-100 pl-4">
                    @foreach ($timeline as $event)
                        <li class="text-sm">
                            <p class="text-gray-900 font-medium">{{ $event->kind->label() }}</p>
                            @if ($event->note)
                                <p class="text-gray-700">{{ $event->note }}</p>
                            @endif
                            <p class="text-xs text-gray-500">{{ $event->created_at->format('d M Y, H:i') }}</p>
                        </li>
                    @endforeach
                </ol>

                @if ($ticket->status->acceptsComplainantReply())
                    <form method="POST" action="{{ route('grievance.track.reply') }}" class="mt-6 space-y-3">
                        @csrf
                        <input type="hidden" name="ticket_no" value="{{ $ticket->ticket_no }}">
                        <input type="hidden" name="email" value="{{ $ticket->reporter_email }}">
                        <label for="note" class="block text-sm font-medium text-gray-700">Add something to this complaint</label>
                        <textarea id="note" name="note" rows="4" maxlength="2000" required
                                  class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500"></textarea>

                        @if ($ticket->escalation_level->next())
                            <label class="flex items-start gap-2.5 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                <input type="checkbox" name="request_escalation" value="1"
                                       class="mt-0.5 rounded border-amber-300 text-amber-600 focus:ring-amber-500">
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
        @endisset
    </div>
</body>
</html>
