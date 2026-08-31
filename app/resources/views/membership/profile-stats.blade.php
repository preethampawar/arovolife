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
                    <x-lucide-upload class="w-4 h-4" />
                    Download PDF
                </button>
                <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-700 hover:bg-brand-800 text-sm font-medium text-white transition-colors">
                    <x-lucide-scale class="w-4 h-4" />
                    Print
                </button>
            </div>
        </div>
        <p class="max-w-3xl mx-auto px-4 sm:px-6 pb-3 text-xs text-gray-600">
            Both buttons open your browser's print dialog — choose <span class="font-medium">Save as PDF</span> to download.
        </p>
    </div>

    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8">
        <div class="ps-sheet bg-white rounded-2xl border border-gray-200 shadow-sm p-6 sm:p-8">

            {{-- ── Header: company name + document title ─────────────────── --}}
            <div class="flex items-center gap-4 pb-5 mb-6 border-b border-gray-200">
                <img src="{{ asset('assets/arovolife-logos/arovolife-blue-logo.png') }}" alt="arovolife" class="h-12 w-auto object-contain">
                <div class="min-w-0">
                    <p class="text-lg sm:text-xl font-bold text-brand-700 leading-tight">Arovolife Private Limited</p>
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
                <p class="text-[11px] text-gray-600 leading-snug">
                    <span class="font-semibold text-gray-700">Arovolife Private Limited</span> · CIN U46909TS2026PTC210896
                </p>
                <p class="text-[11px] text-gray-600 mt-1 leading-snug">
                    <a href="tel:+918886662949" class="hover:text-brand-800">+91 88866 62949</a>
                    <span class="text-gray-600">|</span>
                    <a href="mailto:support@arovolife.com" class="hover:text-brand-800">support@arovolife.com</a>
                    <span class="text-gray-600">|</span>
                    <a href="https://www.arovolife.com" class="hover:text-brand-800">www.arovolife.com</a>
                </p>
            </div>

        </div>
    </div>

</body>
</html>
