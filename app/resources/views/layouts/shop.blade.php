<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Shop') — arovolife</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    {{-- Flash messages --}}
    @if(session('status'))
    <div class="max-w-7xl mx-auto px-6 mt-4">
        <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    </div>
    @endif

    {{-- Main content --}}
    <main class="max-w-7xl mx-auto px-6 py-8">
        @yield('content')
    </main>

    {{-- Footer --}}
    <footer class="bg-gray-900 text-gray-400 mt-16 py-10">
        <div class="max-w-7xl mx-auto px-6 flex flex-col md:flex-row items-start md:items-center justify-between gap-4 text-xs">
            <p>&copy; {{ date('Y') }} Arovolife Private Limited. CIN U46909TS2026PTC210896.</p>
            <div class="flex gap-4">
                <a href="{{ route('content.show', 'terms') }}" class="hover:text-white">Terms</a>
                <a href="{{ route('content.show', 'privacy') }}" class="hover:text-white">Privacy</a>
                <a href="{{ route('content.show', 'grievance') }}" class="hover:text-white">Grievance</a>
            </div>
        </div>
    </footer>

</body>
</html>
