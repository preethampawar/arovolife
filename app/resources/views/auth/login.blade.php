<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <div class="max-w-md mx-auto px-6 py-12 sm:py-16">

        {{-- Header matches the homepage hero slider: small-caps eyebrow,
             bold sans headline, accent in solid brand colour. --}}
        <div class="text-center mb-8 lift-in" style="animation-delay: 60ms;">
            <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Welcome back</p>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight">
                Sign in to your <span class="text-brand-600">arovolife</span> account.
            </h1>
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

            @if(session('status'))
            <div class="mb-5 rounded-lg border border-leaf-200 bg-leaf-50 p-3.5 text-sm text-leaf-800">
                {{ session('status') }}
            </div>
            @endif

            <form method="POST" action="{{ route('login.post') }}" class="space-y-5">
                @csrf

                <div class="lift-in" style="animation-delay: 320ms;">
                    <label for="login" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">ADN</span>
                    </label>
                    <input id="login" name="login" type="text" autocomplete="username" required
                        value="{{ old('login') }}"
                        placeholder="9-digit ADN"
                        class="input-refined">
                    <p class="mt-1.5 text-[11px] text-slate-500">
                        Sign in with your <strong>9-digit ADN</strong>.
                    </p>
                </div>

                <div class="lift-in" style="animation-delay: 380ms;">
                    <label for="password" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Password</span>
                        <a href="{{ route('password.request') }}" class="text-[11px] text-brand-600 hover:text-brand-700 font-medium underline-offset-4 hover:underline">Forgot?</a>
                    </label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required
                        placeholder="••••••••"
                        class="input-refined font-mono tracking-widest">
                </div>

                {{-- Couple (joint) ADN disambiguation. Revealed only when the
                     identifier looks like an ADN (all digits) — see the script
                     below. Two spouses share one ADN; this picks which holder
                     to sign in as. Ignored server-side for solo ADNs / emails. --}}
                <div id="coupleRoleRow" class="hidden">
                    <label class="flex items-start gap-2 text-sm text-slate-600 cursor-pointer rounded-lg border border-slate-200 bg-slate-50/60 p-3">
                        <input type="checkbox" name="primary" value="1" checked
                            class="mt-0.5 rounded border-slate-300 text-brand-500 focus:ring-brand-500/40">
                        <span>
                            <span class="font-medium text-slate-700">Primary account holder</span>
                            <span class="block text-[11px] text-slate-400 leading-snug mt-0.5">
                                Joint (couple) ADNs are shared by two people. Leave this checked for the
                                primary holder, or uncheck to sign in as the spouse.
                            </span>
                        </span>
                    </label>
                </div>

                <div class="flex items-center justify-between lift-in" style="animation-delay: 440ms;">
                    <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                        <input type="checkbox" name="remember" class="rounded border-slate-300 text-brand-500 focus:ring-brand-500/40">
                        <span>Remember me</span>
                    </label>
                </div>

                <button type="submit"
                    class="btn-cta group w-full rounded-full bg-brand-500 hover:bg-brand-600 text-white py-3.5 text-sm font-semibold transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-brand-300/40 lift-in shadow-lg shadow-brand-500/30 hover:shadow-xl hover:shadow-brand-500/40"
                    style="animation-delay: 500ms;">
                    <span class="inline-flex items-center justify-center gap-2.5">
                        Sign in
                        <svg class="btn-arrow w-4 h-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 8h11M9 4l4 4-4 4"/>
                        </svg>
                    </span>
                </button>
            </form>

            <div class="mt-7 flex items-center gap-3 lift-in" style="animation-delay: 580ms;">
                <span class="h-px flex-1 bg-gradient-to-r from-transparent via-slate-300 to-transparent"></span>
                <p class="text-[12px] text-slate-500">
                    New to arovolife?
                    <a href="{{ route('contact.show') }}" class="text-brand-600 hover:text-brand-700 font-medium underline-offset-4 hover:underline">Talk to our team →</a>
                </p>
                <span class="h-px flex-1 bg-gradient-to-r from-transparent via-slate-300 to-transparent"></span>
            </div>
        </div>

        <p class="mt-8 text-center text-[11px] text-slate-400 lift-in" style="animation-delay: 660ms;">
            Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896
        </p>
    </div>

    <script>
        // Reveal the couple/primary selector only when the identifier looks
        // like an ADN (digits only). Email logins never see it. The server
        // applies the choice only when the ADN actually maps to a couple.
        (function () {
            var loginInput = document.getElementById('login');
            var row = document.getElementById('coupleRoleRow');
            if (!loginInput || !row) return;
            function sync() {
                var v = loginInput.value.trim();
                var looksLikeAdn = /^\d{4,}$/.test(v);
                row.classList.toggle('hidden', !looksLikeAdn);
            }
            loginInput.addEventListener('input', sync);
            sync();
        })();
    </script>

</body>
</html>
