<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage overflow-x-hidden">

    @include('partials.impersonation-banner')
    @include('partials.public-topnav')

    {{-- min-w-0 + max-w-full guarantee wide children (e.g. genealogy tree
         canvas) stay inside their own overflow container instead of pushing
         the document past the viewport. Padding scales: px-4 on phones,
         sm:px-6 from 640px, lg:px-8 from 1024px. --}}
    <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 lg:py-10 min-w-0 max-w-full">
        @if(session('status'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700">
            {{ session('status') }}
        </div>
        @endif

        @yield('content')
    </main>

    <footer class="border-t border-gray-200 mt-12 sm:mt-16 px-4 sm:px-6 py-6 text-center text-xs text-gray-700">
        <div class="flex flex-wrap justify-center gap-x-4 gap-y-1">
            <a href="{{ route('content.show', 'terms') }}" class="hover:text-gray-900">Terms</a>
            <a href="{{ route('content.show', 'privacy') }}" class="hover:text-gray-900">Privacy</a>
            <a href="{{ route('content.show', 'ethics') }}" class="hover:text-gray-900">Code of Ethics</a>
            <a href="{{ route('content.show', 'grievance') }}" class="hover:text-gray-900">Grievance</a>
        </div>
        <p class="mt-3 text-gray-600">Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896</p>
    </footer>

    {{-- Platform-wide confirmation modal. Any form marked with data-confirm
         is intercepted and confirmed here before submitting. --}}
    <x-confirm-modal />

</body>
</html>
