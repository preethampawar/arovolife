<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Family Card — arovolife</title>
    @include('partials._favicons')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Same print mechanics as the membership card: front on page 1, back
           on page 2, toolbar hidden, brand colours forced onto paper/PDF. */
        .mc-card, .mc-card * {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        @media print {
            @page { size: A4 portrait; margin: 18mm; }
            .no-print { display: none !important; }
            html, body { background: #ffffff !important; }
            body.wizard-stage { background: #ffffff !important; }
            .wizard-stage::before { display: none !important; }
            .mc-page { min-height: calc(100vh - 36mm); display: flex; align-items: center; justify-content: center; }
            .mc-page--front { break-after: page; page-break-after: always; }
            .mc-card { box-shadow: none !important; }
            .mc-card .arovo-watermark { display: none !important; }
        }
    </style>
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.recompute-projection-banner')

    {{-- Toolbar (hidden when printing) --}}
    <div class="no-print sticky top-0 z-10 bg-white border-b border-gray-200">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900 whitespace-nowrap">← Dashboard</a>
                <h1 class="text-base sm:text-lg font-bold text-gray-900 truncate">Family Card</h1>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-sm font-medium text-gray-700 transition-colors">
                    <x-lucide-download class="w-4 h-4" />
                    Download PDF
                </button>
                <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-sm font-medium text-white transition-colors">
                    <x-lucide-printer class="w-4 h-4" />
                    Print
                </button>
            </div>
        </div>
        <p class="max-w-4xl mx-auto px-4 sm:px-6 pb-3 text-xs text-gray-600">
            Both buttons open your browser's print dialog — choose <span class="font-medium">Save as PDF</span> to download.
            The front prints on page 1 and the back on page 2.
        </p>
    </div>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 space-y-8">

        {{-- ── FRONT ─────────────────────────────────────────────────────── --}}
        <div class="mc-page mc-page--front flex justify-center">
            @include('membership._family-card-front', ['compact' => false])
        </div>

        {{-- ── BACK ──────────────────────────────────────────────────────── --}}
        <div class="mc-page flex justify-center">
            @include('membership._family-card-back')
        </div>

    </div>

</body>
</html>
