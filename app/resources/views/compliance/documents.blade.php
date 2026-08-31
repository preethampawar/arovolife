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
            <p class="text-sm font-medium text-brand-700 uppercase tracking-wider mb-3">Transparency</p>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight mb-3">Compliance Documents</h1>
            <p class="text-base text-gray-600 max-w-prose mx-auto">
                Our statutory registrations, policies and certifications — published openly for anyone to view and download.
            </p>
        </div>

        @forelse($documents as $doc)
            @if($loop->first)<div class="space-y-3">@endif
            <div class="bg-white rounded-2xl border border-gray-200 p-5 flex items-start justify-between gap-4 shadow-sm hover:shadow-md transition-shadow">
                <div class="min-w-0 flex items-start gap-3">
                    <span class="shrink-0 w-10 h-10 rounded-lg bg-brand-50 text-brand-700 flex items-center justify-center">
                        <x-lucide-file-text class="w-5 h-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="font-semibold text-gray-900">{{ $doc->title }}</p>
                        @if($doc->description)
                            <p class="text-sm text-gray-600 mt-0.5 leading-snug">{{ $doc->description }}</p>
                        @endif
                        <p class="text-xs text-gray-600 mt-1">
                            {{ strtoupper(pathinfo($doc->original_name, PATHINFO_EXTENSION)) }} · {{ $doc->humanSize() }}
                            · Published {{ $doc->created_at->format('d M Y') }}
                        </p>
                    </div>
                </div>
                <a href="{{ route('compliance-documents.download', $doc->id) }}"
                   class="shrink-0 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-medium transition-colors">
                    <x-lucide-download class="w-4 h-4" />
                    Download
                </a>
            </div>
            @if($loop->last)</div>@endif
        @empty
            <div class="bg-white rounded-2xl border border-gray-200 p-10 text-center">
                <p class="text-gray-600">No compliance documents have been published yet. Please check back soon.</p>
            </div>
        @endforelse

        <p class="mt-10 text-center text-[11px] text-slate-600">
            Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896
        </p>
    </div>

</body>
</html>
