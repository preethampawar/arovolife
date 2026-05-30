<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Stats — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Force the brand tints + status pills to render on paper and in
           saved-as-PDF. Most browsers strip background colours from print by
           default — these two properties opt back in. */
        .ps-sheet, .ps-sheet * {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        @media print {
            @page { size: A4 portrait; margin: 16mm; }
            .no-print { display: none !important; }
            html, body { background: #ffffff !important; }
            body.wizard-stage { background: #ffffff !important; }
            .wizard-stage::before { display: none !important; }
            .ps-sheet { box-shadow: none !important; border-color: #e5e7eb !important; }
        }
    </style>
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    {{-- Toolbar (hidden when printing) --}}
    <div class="no-print sticky top-0 z-10 bg-white border-b border-gray-200">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900 whitespace-nowrap">← Dashboard</a>
                <h1 class="text-base sm:text-lg font-bold text-gray-900 truncate">Profile Stats</h1>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-sm font-medium text-gray-700 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                    Download PDF
                </button>
                <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-sm font-medium text-white transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/></svg>
                    Print
                </button>
            </div>
        </div>
        <p class="max-w-3xl mx-auto px-4 sm:px-6 pb-3 text-xs text-gray-500">
            Both buttons open your browser's print dialog — choose <span class="font-medium">Save as PDF</span> to download.
        </p>
    </div>

    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8">
        <div class="ps-sheet bg-white rounded-2xl border border-gray-200 shadow-sm p-6 sm:p-8">

            {{-- ── Header: company name + document title ─────────────────── --}}
            <div class="flex items-center gap-4 pb-5 mb-6 border-b border-gray-200">
                <img src="{{ asset('assets/arovolife-logos/arovolife-blue-logo.png') }}" alt="arovolife" class="h-12 w-auto object-contain">
                <div class="min-w-0">
                    <p class="text-lg sm:text-xl font-bold text-brand-600 leading-tight">Arovolife Private Limited</p>
                    <p class="text-sm text-gray-600">Profile Stats</p>
                </div>
            </div>

            {{-- ── The shared ID-card stats panel (read-only) ────────────── --}}
            @include('partials._id-card-panel', [
                'idCardStats' => $idCardStats,
                'idPhotoUrl'  => $idPhotoUrl,
                'readonly'    => true,
            ])

            {{-- ── Contact footer (printable-page convention) ────────────── --}}
            <div class="mt-8 pt-4 border-t border-gray-200 text-center">
                <p class="text-[11px] text-gray-500 leading-snug">
                    <span class="font-semibold text-gray-700">Arovolife Private Limited</span> · CIN U46909TS2026PTC210896
                </p>
                <p class="text-[11px] text-gray-600 mt-1 leading-snug">
                    <a href="tel:+918886662949" class="hover:text-brand-600">+91 88866 62949</a>
                    <span class="text-gray-400">|</span>
                    <a href="mailto:support@arovolife.com" class="hover:text-brand-600">support@arovolife.com</a>
                    <span class="text-gray-400">|</span>
                    <a href="https://www.arovolife.com" class="hover:text-brand-600">www.arovolife.com</a>
                </p>
            </div>

        </div>
    </div>

</body>
</html>
