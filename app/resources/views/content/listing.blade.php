<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }} — arovolife</title>
    @vite(['resources/css/app.css'])
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <main class="max-w-5xl mx-auto px-6 py-10">
        <h1 class="text-3xl font-bold text-gray-900 mb-8">{{ $heading }}</h1>

        @if($pages->isEmpty())
            <p class="text-gray-500 text-sm">{{ $emptyMsg }}</p>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($pages as $page)
                    <a href="/p/{{ $page->slug }}"
                       class="block bg-white rounded-2xl border border-gray-200 p-6 hover:shadow-md hover:border-brand-300 transition-all">
                        <h2 class="text-base font-semibold text-gray-900 mb-1 leading-snug">{{ $page->title }}</h2>
                        @if($page->published_at)
                            <p class="text-xs text-gray-400 mb-3">{{ $page->published_at->format('d M Y') }}</p>
                        @endif
                        <p class="text-sm text-gray-600 leading-relaxed">
                            {{ Str::limit(strip_tags((string) $page->body), 200) }}
                        </p>
                    </a>
                @endforeach
            </div>
        @endif
    </main>

    <footer class="border-t border-gray-200 mt-8 px-6 py-6 text-center text-xs text-gray-500">
        Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896
    </footer>

</body>
</html>
