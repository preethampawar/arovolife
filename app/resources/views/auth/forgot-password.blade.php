<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Reset password — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <div class="max-w-md mx-auto px-6 py-12 sm:py-16">

        <div class="text-center mb-8 lift-in" style="animation-delay: 60ms;">
            <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Account recovery</p>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight">
                Reset your <span class="text-brand-600">arovolife</span> password.
            </h1>
            <p class="mt-4 text-base text-gray-600 max-w-prose mx-auto">
                Enter the email associated with your account. We'll send you a link to choose a new password.
            </p>
        </div>

        <div class="card-refined p-7 sm:p-8 lift-in" style="animation-delay: 200ms;">

            @if(session('status'))
            <div class="mb-5 rounded-lg border border-leaf-200 bg-leaf-50 p-3.5 text-sm text-leaf-800">
                {{ session('status') }}
            </div>
            @endif

            @if($errors->any())
            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-3.5">
                <ul class="text-sm text-red-700 space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
                @csrf

                <div class="lift-in" style="animation-delay: 320ms;">
                    <label for="email" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Email address</span>
                    </label>
                    <input id="email" name="email" type="email" autocomplete="email" required
                        value="{{ old('email') }}"
                        placeholder="you@example.com"
                        class="input-refined">
                </div>

                <button type="submit"
                    class="btn-cta group w-full rounded-full bg-brand-500 hover:bg-brand-600 text-white py-3.5 text-sm font-semibold transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-brand-300/40 lift-in shadow-lg shadow-brand-500/30 hover:shadow-xl hover:shadow-brand-500/40"
                    style="animation-delay: 380ms;">
                    <span class="inline-flex items-center justify-center gap-2.5">
                        Send reset link
                        <svg class="btn-arrow w-4 h-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 8h11M9 4l4 4-4 4"/>
                        </svg>
                    </span>
                </button>
            </form>

            <div class="mt-7 flex items-center gap-3 lift-in" style="animation-delay: 460ms;">
                <span class="h-px flex-1 bg-gradient-to-r from-transparent via-slate-300 to-transparent"></span>
                <p class="text-[12px] text-slate-500">
                    Remembered it?
                    <a href="{{ route('login') }}" class="text-brand-600 hover:text-brand-700 font-medium underline-offset-4 hover:underline">Back to sign in →</a>
                </p>
                <span class="h-px flex-1 bg-gradient-to-r from-transparent via-slate-300 to-transparent"></span>
            </div>
        </div>

        <p class="mt-8 text-center text-[11px] text-slate-400 lift-in" style="animation-delay: 540ms;">
            Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896
        </p>
    </div>

</body>
</html>
