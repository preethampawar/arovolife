<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $page->title }} — arovolife</title>
    @if($page->meta_description)
    <meta name="description" content="{{ $page->meta_description }}">
    @endif
    @vite(['resources/css/app.css'])
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
    <style>
        .page-body h1 { font-size: 2rem; font-weight: 700; margin: 1.5rem 0 0.75rem; color: #111827; }
        .page-body h2 { font-size: 1.5rem; font-weight: 700; margin: 1.25rem 0 0.5rem; color: #111827; }
        .page-body h3 { font-size: 1.2rem; font-weight: 600; margin: 1rem 0 0.5rem; color: #111827; }
        .page-body p  { margin: 0.75rem 0; line-height: 1.65; color: #374151; }
        .page-body ul { list-style: disc; padding-left: 1.5rem; margin: 0.75rem 0; }
        .page-body ol { list-style: decimal; padding-left: 1.5rem; margin: 0.75rem 0; }
        .page-body li { margin: 0.25rem 0; color: #374151; }
        .page-body a  { color: #1f9a8e; text-decoration: underline; }
        .page-body blockquote { border-left: 3px solid #2ab3a6; padding-left: 1rem; margin: 1rem 0; color: #4b5563; font-style: italic; }
        .page-body strong { font-weight: 600; color: #111827; }
        .page-body pre { background: #f3f4f6; padding: 0.75rem; border-radius: 0.5rem; overflow-x: auto; font-size: 0.875rem; }
        .page-body code { background: #f3f4f6; padding: 0.1rem 0.3rem; border-radius: 0.25rem; font-size: 0.875em; }
    </style>
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    {{-- Content --}}
    <main class="max-w-4xl mx-auto px-6 py-10">
        <article class="bg-white rounded-2xl border border-gray-200 p-8 md:p-12">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ $page->title }}</h1>
            @if($page->published_at)
            <p class="text-xs text-gray-500 mb-8">Published {{ $page->published_at->format('d M Y') }}</p>
            @endif
            <div class="page-body">
                {!! $page->body !!}
            </div>
        </article>
    </main>

    {{-- Footer --}}
    <footer class="border-t border-gray-200 mt-8 px-6 py-6 text-center text-xs text-gray-500">
        Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896
    </footer>

</body>
</html>
