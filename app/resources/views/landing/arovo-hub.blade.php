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
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 mb-1">Health Services</h2>
                    <p class="text-sm text-gray-600">Wellness solutions for you and your family.</p>
                </div>
            </a>

            <a href="/p/social-contribution"
               class="group flex flex-col gap-4 bg-white rounded-2xl border border-gray-200 p-8 hover:shadow-md hover:border-brand-300 transition-all">
                <div class="w-12 h-12 rounded-xl bg-brand-50 flex items-center justify-center text-brand-700 group-hover:bg-brand-100 transition-colors">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 mb-1">Social Contribution</h2>
                    <p class="text-sm text-gray-600">Our commitment to community and the environment.</p>
                </div>
            </a>

            <a href="{{ route('shop.index') }}"
               class="group flex flex-col gap-4 bg-white rounded-2xl border border-gray-200 p-8 hover:shadow-md hover:border-brand-300 transition-all">
                <div class="w-12 h-12 rounded-xl bg-brand-50 flex items-center justify-center text-brand-700 group-hover:bg-brand-100 transition-colors">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 mb-1">Online Shopping</h2>
                    <p class="text-sm text-gray-600">Browse our full range of products.</p>
                </div>
            </a>

            <a href="/p/online-courses"
               class="group flex flex-col gap-4 bg-white rounded-2xl border border-gray-200 p-8 hover:shadow-md hover:border-brand-300 transition-all">
                <div class="w-12 h-12 rounded-xl bg-brand-50 flex items-center justify-center text-brand-700 group-hover:bg-brand-100 transition-colors">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 3.741-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5"/>
                    </svg>
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
