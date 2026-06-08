<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Find My ID — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <div class="max-w-lg mx-auto px-6 py-12 sm:py-16">

        <div class="text-center mb-8 lift-in" style="animation-delay: 60ms;">
            <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Account recovery</p>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight">
                Find my <span class="text-brand-600">ID</span>.
            </h1>
            <p class="text-sm text-gray-600 mt-3">
                Forgotten your Distributor Number (ADN)? Enter your registered name and PAN to retrieve it.
                Your PAN is only used to verify your identity — it is never stored or shown.
            </p>
        </div>

        @if($result)
            {{-- Match found — show the ADN. --}}
            <div class="card-refined p-6 sm:p-7 mb-6 bg-leaf-50 border border-leaf-200 lift-in text-center" style="animation-delay: 120ms;">
                <p class="text-sm font-semibold text-leaf-700 mb-1">We found your account</p>
                <p class="text-xs text-gray-600 mb-4">{{ $result['name'] }} · {{ $result['state'] }}</p>
                <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">Your Distributor Number (ADN)</p>
                <p class="text-3xl font-bold font-mono text-gray-900 tracking-wider">{{ $result['adn'] }}</p>
                <a href="{{ route('login') }}"
                   class="inline-block mt-5 px-6 py-2.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors">
                    Log in →
                </a>
            </div>
        @else
            @if(!empty($error))
                <div class="card-refined p-4 mb-6 bg-amber-50 border border-amber-200 lift-in" style="animation-delay: 100ms;">
                    <p class="text-sm text-amber-800">{{ $error }}</p>
                </div>
            @endif

            @if($errors->any())
                <div class="card-refined p-4 mb-6 bg-red-50 border border-red-200">
                    <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('find-my-id.lookup') }}" class="card-refined p-6 sm:p-7 space-y-5 lift-in" style="animation-delay: 120ms;">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Registered full name</label>
                    <input type="text" name="full_name" required maxlength="255" value="{{ old('full_name') }}"
                        autocomplete="name"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">PAN</label>
                    <input type="text" name="pan" required maxlength="10" value="{{ old('pan') }}"
                        placeholder="ABCDE1234F" autocomplete="off" spellcheck="false"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono uppercase tracking-wider focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                        style="text-transform: uppercase;">
                    <p class="text-xs text-gray-500 mt-1.5">Used only to verify it's you. We match it securely and never store or display it.</p>
                </div>

                {{-- DPDP Act 2023 §5-6 — informed consent before processing the PAN. --}}
                <div class="pt-1 border-t border-gray-100">
                    <label class="flex items-start gap-2.5 text-xs text-gray-600 cursor-pointer leading-relaxed pt-3">
                        <input type="checkbox" name="consent_privacy" value="1" required
                            {{ old('consent_privacy') ? 'checked' : '' }}
                            class="mt-0.5 rounded border-gray-300 text-brand-500 focus:ring-brand-500/40">
                        <span>
                            I agree to arovolife using my PAN solely to verify my identity and retrieve my ADN. It is
                            matched as a secure one-way hash and is not stored from this form. See our
                            <a href="{{ route('content.show', 'privacy') }}" target="_blank" rel="noopener"
                               class="text-brand-600 hover:text-brand-700 font-medium underline-offset-4 hover:underline">Privacy Policy</a>.
                        </span>
                    </label>
                    @error('consent_privacy')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <button type="submit"
                    class="w-full rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-bold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                    Find my ID
                </button>
            </form>

            <p class="text-center text-xs text-gray-500 mt-5">
                Still stuck? <a href="{{ route('contact.show') }}" class="text-brand-600 hover:text-brand-700 font-medium">Contact support</a>.
            </p>
        @endif

    </div>
</body>
</html>
