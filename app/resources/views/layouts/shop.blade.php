<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Shop') — arovolife</title>
    @vite(['resources/css/app.css'])
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage overflow-x-hidden">

    @include('partials.public-topnav')

    {{-- Full-bleed banner slot — sits flush under the header, edge to edge. --}}
    @yield('banner')

    {{-- Flash messages --}}
    @if(session('status'))
    <div class="max-w-7xl mx-auto px-4 sm:px-6 mt-4">
        <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    </div>
    @endif

    {{-- Main content. min-w-0 keeps wide content (product grids, tree, etc.)
         inside their own scroll containers instead of widening the page. --}}
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 min-w-0 max-w-full">
        @yield('content')
    </main>

    <x-confirm-modal />
    @include('partials._toast-container')

    {{-- Footer --}}
    <footer class="bg-gray-900 text-gray-400 mt-16 py-10">
        <div class="max-w-7xl mx-auto px-6 flex flex-col md:flex-row items-start md:items-center justify-between gap-4 text-xs">
            <p>&copy; {{ date('Y') }} Arovolife Private Limited. CIN U46909TS2026PTC210896.</p>
            <div class="flex flex-wrap gap-4">
                <a href="{{ route('content.show', 'terms') }}" class="hover:text-white">Terms</a>
                <a href="{{ route('content.show', 'privacy') }}" class="hover:text-white">Privacy</a>
                <a href="{{ route('content.show', 'grievance') }}" class="hover:text-white">Grievance</a>
                <a href="{{ route('compliance-documents.index') }}" class="hover:text-white">Compliance Documents</a>
            </div>
        </div>
        <div class="max-w-7xl mx-auto px-6 mt-4 text-xs text-gray-500">
            Customer Care:
            <a href="tel:+918886662949" class="hover:text-white">+91 88866 62949</a> ·
            <a href="mailto:support@arovolife.com" class="hover:text-white">support@arovolife.com</a> ·
            9:30 am – 5:30 pm, every day except Sundays &amp; public holidays
        </div>
    </footer>

</body>
</html>
