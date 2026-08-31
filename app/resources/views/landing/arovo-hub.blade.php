<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arovo Hub — arovolife</title>
    <meta name="description" content="Explore arovolife's hub — health services, social contribution, online shopping and online courses.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <main class="max-w-5xl mx-auto px-6 py-12">
        <h1 class="text-3xl font-bold text-gray-900 mb-3">Arovo Hub</h1>
        <p class="text-gray-600 mb-10 max-w-2xl">Discover everything arovolife has to offer — from wellness resources to community initiatives and skill-building programs.</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <a href="/p/health-services"
               class="group flex flex-col gap-4 bg-white rounded-2xl border border-gray-200 p-8 hover:shadow-md hover:border-brand-300 transition-all">
                <div class="w-12 h-12 rounded-xl bg-brand-50 flex items-center justify-center text-brand-700 group-hover:bg-brand-100 transition-colors">
                    <x-lucide-heart class="w-6 h-6" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 mb-1">Health Services</h2>
                    <p class="text-sm text-gray-600">Wellness solutions for you and your family.</p>
                </div>
            </a>

            <a href="/p/social-contribution"
               class="group flex flex-col gap-4 bg-white rounded-2xl border border-gray-200 p-8 hover:shadow-md hover:border-brand-300 transition-all">
                <div class="w-12 h-12 rounded-xl bg-brand-50 flex items-center justify-center text-brand-700 group-hover:bg-brand-100 transition-colors">
                    <x-lucide-users-round class="w-6 h-6" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 mb-1">Social Contribution</h2>
                    <p class="text-sm text-gray-600">Our commitment to community and the environment.</p>
                </div>
            </a>

            <a href="{{ route('shop.index') }}"
               class="group flex flex-col gap-4 bg-white rounded-2xl border border-gray-200 p-8 hover:shadow-md hover:border-brand-300 transition-all">
                <div class="w-12 h-12 rounded-xl bg-brand-50 flex items-center justify-center text-brand-700 group-hover:bg-brand-100 transition-colors">
                    <x-lucide-shopping-bag class="w-6 h-6" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 mb-1">Online Shopping</h2>
                    <p class="text-sm text-gray-600">Browse our full range of products.</p>
                </div>
            </a>

            <a href="/p/online-courses"
               class="group flex flex-col gap-4 bg-white rounded-2xl border border-gray-200 p-8 hover:shadow-md hover:border-brand-300 transition-all">
                <div class="w-12 h-12 rounded-xl bg-brand-50 flex items-center justify-center text-brand-700 group-hover:bg-brand-100 transition-colors">
                    <x-lucide-graduation-cap class="w-6 h-6" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 mb-1">Online Courses</h2>
                    <p class="text-sm text-gray-600">Skill-building programs to support your journey.</p>
                </div>
            </a>
        </div>
    </main>

    <footer class="border-t border-gray-200 mt-8 px-6 py-6 text-center text-xs text-gray-600">
        Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896
    </footer>

</body>
</html>
