<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }} — arovolife</title>
    @vite(['resources/css/app.css'])
    @include('partials._theme-fouc')
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <main class="max-w-6xl mx-auto px-6 py-12 sm:py-16">
        <div class="text-center mb-10 sm:mb-12 max-w-2xl mx-auto">
            <p class="text-sm font-medium text-brand-700 uppercase tracking-wider mb-3">arovolife</p>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight mb-3">{{ $heading }}</h1>
            @isset($intro)
                <p class="text-base text-gray-600 leading-relaxed">{{ $intro }}</p>
            @endisset
        </div>

        @if($pages->isEmpty())
            <div class="max-w-xl mx-auto bg-white rounded-3xl border border-gray-200 p-10 text-center">
                <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-brand-50 text-brand-700 flex items-center justify-center">
                    {{ svg('lucide-'.($icon ?? 'file-text'), 'w-7 h-7') }}
                </div>
                <p class="text-gray-600">{{ $emptyMsg }}</p>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($pages as $page)
                    @php $featured = $loop->first; @endphp
                    <a href="{{ route('content.show', $page->slug) }}"
                       class="group flex flex-col h-full bg-white rounded-3xl border border-gray-200 shadow-sm hover:shadow-xl hover:border-brand-300 hover:-translate-y-1 transition-all duration-300 {{ $featured ? 'sm:col-span-2 lg:col-span-3 p-8 sm:p-10' : 'p-7' }}">
                        <div class="flex items-center gap-3 mb-5">
                            <span class="shrink-0 {{ $featured ? 'w-12 h-12' : 'w-10 h-10' }} rounded-xl bg-brand-50 text-brand-700 flex items-center justify-center">
                                {{ svg('lucide-'.($icon ?? 'file-text'), $featured ? 'w-6 h-6' : 'w-5 h-5') }}
                            </span>
                            @if($page->published_at)
                                <span class="text-xs font-medium text-gray-600 uppercase tracking-wider">{{ $page->published_at->format('d M Y') }}</span>
                            @endif
                            @if($featured && $pages->count() > 1)
                                <span class="ml-auto text-[11px] font-semibold uppercase tracking-wider text-brand-700 bg-brand-50 rounded-full px-3 py-1">Latest</span>
                            @endif
                        </div>
                        <h2 class="{{ $featured ? 'text-2xl sm:text-3xl' : 'text-xl' }} font-bold text-gray-900 mb-3 leading-snug group-hover:text-brand-700 transition-colors">{{ $page->title }}</h2>
                        <p class="{{ $featured ? 'text-base max-w-3xl' : 'text-sm' }} text-gray-600 leading-relaxed mb-6">
                            {{ Str::limit(strip_tags((string) $page->body), $featured ? 360 : 180) }}
                        </p>
                        <span class="mt-auto self-start inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 group-hover:translate-x-1 transition-transform">
                            Read more
                            <x-lucide-arrow-right class="w-4 h-4" />
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </main>

    <footer class="border-t border-gray-200 mt-8 px-6 py-6 text-center text-xs text-gray-600">
        Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896
    </footer>

</body>
</html>
