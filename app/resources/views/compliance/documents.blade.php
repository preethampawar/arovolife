<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Compliance Documents — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <div class="max-w-3xl mx-auto px-6 py-12 sm:py-16">
        <div class="text-center mb-10">
            <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Transparency</p>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight mb-3">Compliance Documents</h1>
            <p class="text-base text-gray-600 max-w-prose mx-auto">
                Our statutory registrations, policies and certifications — published openly for anyone to view and download.
            </p>
        </div>

        @forelse($documents as $doc)
            @if($loop->first)<div class="space-y-3">@endif
            <div class="bg-white rounded-2xl border border-gray-200 p-5 flex items-start justify-between gap-4 shadow-sm hover:shadow-md transition-shadow">
                <div class="min-w-0 flex items-start gap-3">
                    <span class="shrink-0 w-10 h-10 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="font-semibold text-gray-900">{{ $doc->title }}</p>
                        @if($doc->description)
                            <p class="text-sm text-gray-600 mt-0.5 leading-snug">{{ $doc->description }}</p>
                        @endif
                        <p class="text-xs text-gray-500 mt-1">
                            {{ strtoupper(pathinfo($doc->original_name, PATHINFO_EXTENSION)) }} · {{ $doc->humanSize() }}
                            · Published {{ $doc->created_at->format('d M Y') }}
                        </p>
                    </div>
                </div>
                <a href="{{ route('compliance-documents.download', $doc->id) }}"
                   class="shrink-0 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                    Download
                </a>
            </div>
            @if($loop->last)</div>@endif
        @empty
            <div class="bg-white rounded-2xl border border-gray-200 p-10 text-center">
                <p class="text-gray-600">No compliance documents have been published yet. Please check back soon.</p>
            </div>
        @endforelse

        <p class="mt-10 text-center text-[11px] text-slate-400">
            Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896
        </p>
    </div>

</body>
</html>
