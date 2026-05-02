<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.impersonation-banner')
    @include('partials.public-topnav')

    <main class="max-w-6xl mx-auto px-6 py-10">
        @if(session('status'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700">
            {{ session('status') }}
        </div>
        @endif

        @yield('content')
    </main>

    <footer class="border-t border-gray-200 mt-16 px-6 py-6 text-center text-xs text-gray-500">
        <div class="space-x-4">
            <a href="{{ route('content.show', 'terms') }}" class="hover:text-gray-700">Terms</a>
            <a href="{{ route('content.show', 'privacy') }}" class="hover:text-gray-700">Privacy</a>
            <a href="{{ route('content.show', 'ethics') }}" class="hover:text-gray-700">Code of Ethics</a>
            <a href="{{ route('content.show', 'grievance') }}" class="hover:text-gray-700">Grievance</a>
        </div>
        <p class="mt-3 text-gray-400">Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896</p>
    </footer>

</body>
</html>
