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
            <div class="mc-card relative overflow-hidden w-full max-w-[540px] aspect-[1.586/1]
                        rounded-2xl border-4 border-brand-400
                        bg-gradient-to-tl from-white via-leaf-50/40 to-brand-50/60
                        shadow-xl shadow-brand-500/10 ring-1 ring-inset ring-brand-200/40
                        p-6 flex flex-col">
                <span class="arovo-watermark pointer-events-none select-none absolute -right-6 -bottom-6
                             text-[7rem] font-bold text-brand-100/40 leading-none tracking-tight rotate-[-8deg]">arovolife</span>
                <svg class="arovo-wave pointer-events-none select-none absolute -left-6 -right-6 bottom-0 h-20"
                     viewBox="-40 0 620 80" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M-40,38 C80,55 200,20 280,38 C380,58 480,18 580,38 L580,90 L-40,90 Z"
                          fill="var(--color-brand-200)" opacity="0.5"/>
                    <path d="M-40,52 C100,68 220,32 320,50 C420,68 500,30 580,50 L580,90 L-40,90 Z"
                          fill="var(--color-leaf-300)" opacity="0.45"/>
                </svg>
                <svg class="arovo-wave pointer-events-none select-none absolute -left-6 -right-6 top-0 h-14"
                     viewBox="-40 0 620 56" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M-40,0 L580,0 L580,36 C460,16 340,52 270,34 C200,16 80,52 -40,36 Z"
                          fill="var(--color-brand-200)" opacity="0.5"/>
                    <path d="M-40,0 L580,0 L580,22 C450,6 330,30 260,18 C190,6 80,30 -40,22 Z"
                          fill="var(--color-leaf-300)" opacity="0.42"/>
                </svg>
                <p class="relative text-[11px] uppercase tracking-wider text-brand-700 font-semibold mb-2">About this card</p>
                <ul class="relative space-y-1.5 text-[12px] sm:text-[13px] text-gray-700 leading-snug list-disc pl-4 flex-1">
                    <li>This card shows that the holder can register up to five family members on My Profile. It does not list them.</li>
                    <li>It is not an insurance policy. It gives no health cover or treatment, and no right to any payment.</li>
                    <li>Keep your family list on My Profile up to date. You can remove a family member at any time.</li>
                </ul>
                <div class="relative pt-2 mt-2 border-t border-brand-200/40 text-center">
                    <p class="text-[10px] text-gray-600 leading-snug">
                        <span class="font-semibold text-gray-700">Arovolife Private Limited</span> · CIN U46909TS2026PTC210896<br>
                        H No 6-51/2, Bank Colony, Pothireddipally, Sangareddy B/s Complex, Sangareddy, Medak – 502001, Telangana, India.
                    </p>
                    <p class="text-[10px] text-gray-600 mt-1 leading-snug">
                        <a href="tel:+918886662949" class="hover:underline">+91 88866 62949</a> <span class="text-gray-600">|</span>
                        <a href="mailto:support@arovolife.com" class="hover:underline">support@arovolife.com</a><br>
                        <a href="https://www.arovolife.com" class="hover:underline">www.arovolife.com</a>
                    </p>
                </div>
            </div>
        </div>

    </div>

</body>
</html>
