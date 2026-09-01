<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') — arovolife</title>
    @vite(['resources/css/app.css'])
    @include('partials._font-size-fouc')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    <div class="max-w-md mx-auto px-6 py-16 sm:py-24 text-center">

        <a href="{{ url('/') }}" class="inline-block text-lg font-bold tracking-tight text-brand-700">arovolife</a>

        <div class="mx-auto mt-10 mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-gray-100">
            @yield('icon')
        </div>

        <p class="text-sm font-semibold tracking-widest text-gray-400">@yield('code')</p>
        <h1 class="mt-2 text-2xl font-bold text-gray-900">@yield('heading')</h1>
        <p class="mt-3 text-sm leading-relaxed text-gray-600">@yield('message')</p>

        <div class="mt-8">
            <a href="{{ url('/') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-800">
                <x-lucide-house class="h-4 w-4" />
                Go to home
            </a>
        </div>

    </div>

</body>
</html>
