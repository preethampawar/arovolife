{{-- Reusable Atomy-style sliding banner carousel.
     Vars: $slides (collection of Banner), $aspectClass (e.g. 'aspect-[1520/350]').
     The init script lives once in shop/index and drives every [data-carousel]. --}}
@php $aspectClass = $aspectClass ?? 'aspect-[1520/350]'; @endphp
<section class="relative mb-8 rounded-3xl overflow-hidden shadow-sm" data-carousel>
    <div class="relative {{ $aspectClass }} bg-gray-100 overflow-hidden">
        {{-- Horizontal track: slides side-by-side; translated left so each slides
             in from the right. --}}
        <div class="flex h-full" data-track style="will-change: transform;">
            @foreach($slides as $i => $b)
            <a href="{{ $b->link_url ?: '' }}" @if(! $b->link_url) onclick="return false;" @endif
               data-slide="{{ $i }}"
               class="relative block w-full h-full shrink-0">
                <img src="{{ $b->url() }}" alt="{{ $b->title }}" class="w-full h-full object-cover">
                @if($b->title || $b->caption)
                <div class="absolute inset-0 flex flex-col justify-center px-8 md:px-14 bg-gradient-to-r from-black/35 via-black/10 to-transparent">
                    @if($b->title)<h2 class="text-2xl md:text-4xl font-bold text-white drop-shadow-md">{{ $b->title }}</h2>@endif
                    @if($b->caption)<p class="mt-2 text-sm md:text-base text-white/90 max-w-md drop-shadow">{{ $b->caption }}</p>@endif
                </div>
                @endif
            </a>
            @endforeach
        </div>
    </div>
    @if($slides->count() > 1)
    <div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-2" data-dots>
        @foreach($slides as $i => $b)
        <button type="button" data-dot="{{ $i }}" aria-label="Show slide {{ $i + 1 }}"
            class="w-2.5 h-2.5 rounded-full transition-colors {{ $i === 0 ? 'bg-white' : 'bg-white/50 hover:bg-white/80' }}"></button>
        @endforeach
    </div>
    @endif
</section>
