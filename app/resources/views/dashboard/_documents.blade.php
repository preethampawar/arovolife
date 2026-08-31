{{-- ── Documents — quick-access cards to printable / downloadable assets.
     Only Membership Card is implemented today; the others are Phase 4+
     placeholders, rendered as disabled "Coming soon" so the surface is
     discoverable but the missing wiring is honest. --}}
@php $docsLayout = $docsLayout ?? 'row'; @endphp
<div class="{{ $docsLayout === 'stacked' ? 'h-full flex flex-col' : '' }}">
    <div class="flex items-baseline justify-between mb-3">
        <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">Documents</p>
    </div>

    @php
        $docs = [
            [
                'title'    => 'arovolife Direct Seller Application',
                'desc'     => 'Your registration details on file with arovolife — view, print or save as PDF.',
                'url'      => route('direct-seller-application.show'),
                'accent'   => 'border-leaf-500',
                'tile_bg'  => 'bg-leaf-50',
                'tile_txt' => 'text-leaf-700',
                'icon'      => 'file-text',
            ],
            [
                'title'    => 'Membership Card',
                'desc'     => 'View, print or download your front-and-back arovolife ID card.',
                'url'      => route('membership-card.show'),
                'accent'   => 'border-brand-500',
                'tile_bg'  => 'bg-brand-50',
                'tile_txt' => 'text-brand-700',
                'icon'      => 'credit-card',
            ],
            [
                'title'    => 'TDS (Tax Statements)',
                'desc'     => 'Quarterly TDS certificates and the annual Form 26AS reconciliation.',
                'url'      => route('tax-statements.show'),
                'accent'   => 'border-amber-500',
                'tile_bg'  => 'bg-amber-50',
                'tile_txt' => 'text-amber-700',
                'icon'      => 'trending-up',
            ],
        ];
    @endphp

    <div class="grid gap-4 {{ $docsLayout === 'stacked' ? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 flex-1 content-start' : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3' }}">
        @foreach($docs as $doc)
            @if($doc['url'])
                <a href="{{ $doc['url'] }}" target="_blank" rel="noopener"
                   class="group block rounded-2xl bg-white shadow-sm hover:shadow-lg p-5 border-t-4 {{ $doc['accent'] }} transition-all duration-300 hover:-translate-y-0.5">
                    <div class="flex items-start gap-3 mb-3">
                        <span class="shrink-0 w-10 h-10 rounded-lg {{ $doc['tile_bg'] }} {{ $doc['tile_txt'] }} flex items-center justify-center">
                            {{ svg('lucide-'.$doc['icon'], 'w-5 h-5') }}
                        </span>
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900 leading-snug">{{ $doc['title'] }}</p>
                        </div>
                    </div>
                    <p class="text-xs text-gray-600 leading-relaxed mb-3">{{ $doc['desc'] }}</p>
                    <p class="text-xs font-semibold text-brand-700 group-hover:translate-x-0.5 transition-transform inline-flex items-center gap-1">
                        Open →
                    </p>
                </a>
            @else
                <div class="block rounded-2xl bg-white shadow-sm p-5 border-t-4 {{ $doc['accent'] }} opacity-80">
                    <div class="flex items-start gap-3 mb-3">
                        <span class="shrink-0 w-10 h-10 rounded-lg {{ $doc['tile_bg'] }} {{ $doc['tile_txt'] }} flex items-center justify-center">
                            {{ svg('lucide-'.$doc['icon'], 'w-5 h-5') }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900 leading-snug">{{ $doc['title'] }}</p>
                        </div>
                        <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] uppercase tracking-wider font-semibold bg-gray-100 text-gray-600 border border-gray-200">
                            Coming soon
                        </span>
                    </div>
                    <p class="text-xs text-gray-600 leading-relaxed">{{ $doc['desc'] }}</p>
                </div>
            @endif
        @endforeach
    </div>
</div>
