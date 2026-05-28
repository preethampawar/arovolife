<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership card — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Print: front on page 1, back on page 2. The toolbar / chrome is
           hidden, and each card face is centred on its own A4 page. */
        /* Force the brand gradients + wave colours to render on paper and in
           saved-as-PDF. Most browsers strip background images/colours from
           print by default — these two properties opt back in. */
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

    {{-- Toolbar (hidden when printing) --}}
    <div class="no-print sticky top-0 z-10 bg-white border-b border-gray-200">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900 whitespace-nowrap">← Dashboard</a>
                <h1 class="text-base sm:text-lg font-bold text-gray-900 truncate">Membership card</h1>
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
        <p class="max-w-4xl mx-auto px-4 sm:px-6 pb-3 text-xs text-gray-500">
            Both buttons open your browser's print dialog — choose <span class="font-medium">Save as PDF</span> to download.
            The front prints on page 1 and the back on page 2.
        </p>
    </div>

    @php
        $name = $stats['name'] ?? '—';
        $adn = $stats['adn'] ?? '—';
        $joinDate = $stats['registration_date']?->format('d-m-Y');
    @endphp

    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 space-y-8">

        {{-- ── FRONT ─────────────────────────────────────────────────────── --}}
        <div class="mc-page mc-page--front">
            <div class="mc-card relative overflow-hidden w-full max-w-[540px] aspect-[1.586/1]
                        rounded-2xl border-4 border-brand-400
                        bg-gradient-to-br from-white via-brand-50/60 to-leaf-50/40
                        shadow-xl shadow-brand-500/10 ring-1 ring-inset ring-brand-200/40
                        p-6 flex flex-col">
                <span class="arovo-watermark pointer-events-none select-none absolute -right-6 -bottom-6
                             text-[7rem] font-bold text-brand-100/40 leading-none tracking-tight rotate-[-8deg]">arovolife</span>
                {{-- Bottom wave — the curve DIPS DOWN at both edges (control
                     points pull DOWN near corners) so fill stays thick at the
                     corners and only thins where the wave rises in the middle.
                     Inline SVG + CSS-var fills so it survives print + Save-as-PDF. --}}
                <svg class="arovo-wave pointer-events-none select-none absolute -left-6 -right-6 bottom-0 h-20"
                     viewBox="-40 0 620 80" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M-40,38 C80,55 200,20 280,38 C380,58 480,18 580,38 L580,90 L-40,90 Z"
                          fill="var(--color-leaf-200)" opacity="0.55"/>
                    <path d="M-40,52 C100,68 220,32 320,50 C420,68 500,30 580,50 L580,90 L-40,90 Z"
                          fill="var(--color-brand-300)" opacity="0.42"/>
                </svg>
                {{-- Top wave — mirrored. Same edge-dip strategy: control points
                     pull the curve DOWN at both corners so the corner fill is
                     thick (no visible gap near the rounded card corners). --}}
                <svg class="arovo-wave pointer-events-none select-none absolute -left-6 -right-6 top-0 h-14"
                     viewBox="-40 0 620 56" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M-40,0 L580,0 L580,36 C460,16 340,52 270,34 C200,16 80,52 -40,36 Z"
                          fill="var(--color-leaf-200)" opacity="0.55"/>
                    <path d="M-40,0 L580,0 L580,22 C450,6 330,30 260,18 C190,6 80,30 -40,22 Z"
                          fill="var(--color-brand-300)" opacity="0.42"/>
                </svg>
                <div class="relative flex-1 flex items-center gap-5">
                    <div class="w-[38%] shrink-0 flex items-center justify-center">
                        <img src="{{ asset('assets/arovolife-logos/arovolife-blue-logo.png') }}" alt="arovolife" class="max-h-20 w-auto object-contain">
                    </div>
                    <div class="flex-1 min-w-0">
                        <h2 class="text-lg sm:text-xl font-bold text-brand-600 border-b-2 border-brand-200 pb-1 mb-3">arovolife Direct Seller</h2>
                        <dl class="space-y-1.5 text-sm">
                            <div class="flex gap-2">
                                <dt class="text-brand-600 font-semibold shrink-0">ID :</dt>
                                <dd class="font-mono font-medium text-gray-900 tracking-wide">{{ $adn }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-brand-600 font-semibold shrink-0">Name :</dt>
                                <dd class="font-medium text-gray-900 uppercase leading-tight">{{ $name }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-brand-600 font-semibold shrink-0">Join Date :</dt>
                                <dd class="font-medium text-gray-900">{{ $joinDate ?: '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
                <p class="relative text-[10px] sm:text-[11px] text-brand-600/80 font-medium tracking-wide pt-2 border-t border-brand-200/40">
                    Quality you can trust · Value that returns · Service that listens
                </p>
            </div>
        </div>

        {{-- ── BACK ──────────────────────────────────────────────────────── --}}
        <div class="mc-page">
            <div class="mc-card relative overflow-hidden w-full max-w-[540px] aspect-[1.586/1]
                        rounded-2xl border-4 border-brand-400
                        bg-gradient-to-tl from-white via-leaf-50/40 to-brand-50/60
                        shadow-xl shadow-brand-500/10 ring-1 ring-inset ring-brand-200/40
                        p-6 flex flex-col">
                <span class="arovo-watermark pointer-events-none select-none absolute -right-6 -bottom-6
                             text-[7rem] font-bold text-brand-100/40 leading-none tracking-tight rotate-[-8deg]">arovolife</span>
                {{-- Bottom wave — same edge-dip strategy, colour pair mirrored. --}}
                <svg class="arovo-wave pointer-events-none select-none absolute -left-6 -right-6 bottom-0 h-20"
                     viewBox="-40 0 620 80" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M-40,38 C80,55 200,20 280,38 C380,58 480,18 580,38 L580,90 L-40,90 Z"
                          fill="var(--color-brand-200)" opacity="0.5"/>
                    <path d="M-40,52 C100,68 220,32 320,50 C420,68 500,30 580,50 L580,90 L-40,90 Z"
                          fill="var(--color-leaf-300)" opacity="0.45"/>
                </svg>
                {{-- Top wave — same strategy, colour pair mirrored vs front. --}}
                <svg class="arovo-wave pointer-events-none select-none absolute -left-6 -right-6 top-0 h-14"
                     viewBox="-40 0 620 56" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M-40,0 L580,0 L580,36 C460,16 340,52 270,34 C200,16 80,52 -40,36 Z"
                          fill="var(--color-brand-200)" opacity="0.5"/>
                    <path d="M-40,0 L580,0 L580,22 C450,6 330,30 260,18 C190,6 80,30 -40,22 Z"
                          fill="var(--color-leaf-300)" opacity="0.42"/>
                </svg>
                <p class="relative text-[11px] uppercase tracking-wider text-brand-600 font-semibold mb-2">Cardholder instructions</p>
                <ul class="relative space-y-1.5 text-[12px] sm:text-[13px] text-gray-700 leading-snug list-disc pl-4 flex-1">
                    <li>This ID card must be displayed at all times while in office and on customer premises.</li>
                    <li>This card must not be used for any other purpose.</li>
                    <li>In case of loss or damage, bring this to HR's notice immediately.</li>
                    <li>If found, please return it to the office address below or hand it over to the building security.</li>
                </ul>
                <div class="relative pt-2 mt-2 border-t border-brand-200/40">
                    <p class="text-[10px] text-gray-500 leading-snug">
                        <span class="font-semibold text-gray-700">Arovolife Private Limited</span> · CIN U46909TS2026PTC210896<br>
                        H. No. 6-51/2, Bank Colony, Pothireddipally, Sangareddy B/s Complex, Sangareddy, Medak — 502001, Telangana, India.<br>
                        <span class="text-gray-700">Helpline:</span> +91 88866 62949 · <span class="text-gray-700">Email:</span> support@arovolife.com
                    </p>
                </div>
            </div>
        </div>

    </div>

</body>
</html>
