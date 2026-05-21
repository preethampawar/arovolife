<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Choose a new password — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <div class="max-w-md mx-auto px-6 py-12 sm:py-16">

        <div class="text-center mb-8 lift-in" style="animation-delay: 60ms;">
            <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Account recovery</p>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight">
                Choose a <span class="text-brand-600">new password</span>.
            </h1>
            <p class="mt-4 text-base text-gray-600 max-w-prose mx-auto">
                You'll be signed in automatically once the new password is saved.
            </p>
        </div>

        <div class="card-refined p-7 sm:p-8 lift-in" style="animation-delay: 200ms;">

            @if($errors->any())
            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-3.5">
                <ul class="text-sm text-red-700 space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <form method="POST" action="{{ route('password.reset.submit', ['token' => $token]) }}" class="space-y-5">
                @csrf

                <div class="lift-in" style="animation-delay: 320ms;">
                    <label for="email" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Email address</span>
                    </label>
                    <input id="email" name="email" type="email" autocomplete="email" required readonly
                        value="{{ old('email', $email) }}"
                        class="input-refined bg-slate-50 cursor-not-allowed">
                </div>

                <div class="lift-in" style="animation-delay: 380ms;">
                    <label for="password" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">New password</span>
                        <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                    </label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8"
                        placeholder="••••••••"
                        class="input-refined font-mono tracking-widest">
                    <p class="mt-1.5 text-xs text-slate-500">
                        At least 8 characters. Long phrases of unrelated words work best. Common or breached passwords are rejected.
                    </p>
                </div>

                <div class="lift-in" style="animation-delay: 440ms;">
                    <label for="password_confirmation" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Confirm new password</span>
                        <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                    </label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="8"
                        placeholder="••••••••"
                        class="input-refined font-mono tracking-widest">
                </div>

                <button type="submit"
                    class="btn-cta group w-full rounded-full bg-brand-500 hover:bg-brand-600 text-white py-3.5 text-sm font-semibold transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-brand-300/40 lift-in shadow-lg shadow-brand-500/30 hover:shadow-xl hover:shadow-brand-500/40"
                    style="animation-delay: 500ms;">
                    <span class="inline-flex items-center justify-center gap-2.5">
                        Reset password &amp; sign in
                        <svg class="btn-arrow w-4 h-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 8h11M9 4l4 4-4 4"/>
                        </svg>
                    </span>
                </button>
            </form>
        </div>

        <p class="mt-8 text-center text-[11px] text-slate-400 lift-in" style="animation-delay: 580ms;">
            Reset link expires 60 minutes after it's sent. If yours has expired,
            <a href="{{ route('password.request') }}" class="text-brand-600 hover:text-brand-700 underline-offset-4 hover:underline">request a new one</a>.
        </p>
    </div>

</body>
</html>
