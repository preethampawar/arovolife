<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grievance registered — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <div class="max-w-xl mx-auto px-6 py-16">
        <div class="rounded-2xl border border-emerald-200 bg-white p-8 shadow-sm text-center">
            <div class="mx-auto mb-5 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-2xl">✓</div>

            <h1 class="text-2xl font-bold text-gray-900 mb-2">Your grievance is registered</h1>

            <p class="text-sm text-gray-600 mb-6">
                Quote this complaint number in every follow-up.
            </p>

            <div class="rounded-xl bg-emerald-50 px-6 py-5 mb-6">
                <p class="text-xs uppercase tracking-wider text-emerald-700 mb-1">Complaint number</p>
                <p class="font-mono text-2xl font-bold text-emerald-800">{{ $ticketNo }}</p>
            </div>

            @if ($isAnonymous)
                {{-- Policy §6.5: anonymous complaints are accepted, but we cannot
                     report back. Saying so here is the only honest moment to say it. --}}
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-left text-sm text-amber-900 mb-6">
                    <p class="font-semibold mb-1">Write this number down now.</p>
                    <p>
                        You filed anonymously, so we have no way to send it to you, and no way to tell you
                        the outcome. This screen is the only place it appears. We will still investigate as
                        far as the evidence allows.
                    </p>
                </div>
            @else
                <div class="text-left text-sm text-gray-700 space-y-2 mb-6">
                    <p>We have emailed this number to you along with what happens next.</p>
                    <p>First substantive response by <strong>{{ $firstResponseBy }}</strong>.</p>
                    <p>Resolution by <strong>{{ $resolutionBy }}</strong>.</p>
                </div>

                <a href="{{ route('grievance.track') }}"
                   class="inline-block rounded-lg bg-brand-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-800">
                    Track this grievance
                </a>
            @endif
        </div>

        <p class="mt-6 text-center text-sm text-gray-600">
            Not satisfied at any stage? The full escalation route is in the
            <a href="{{ route('content.show', 'grievance') }}" class="underline">Grievance Redressal Policy</a>.
        </p>
    </div>
</body>
</html>
