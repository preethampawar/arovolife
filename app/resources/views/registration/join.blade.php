@extends('layouts.app')
@section('title', 'Register with arovolife')

@section('content')
<div class="max-w-md mx-auto">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">Register with arovolife</h1>
    <p class="text-sm text-gray-600 mb-6">
        @if($sponsorLocked)
            You've been invited by an existing Direct Seller — their ADN is filled
            in below. Enter the placement ADN (often the same person, or someone
            else in their tree) and we'll show you their name to confirm before
            you continue.
        @else
            Enter the ADN of the Direct Seller who invited you, plus the placement
            ADN under whose Genos (placement tree) you'd like to be placed. They're often the
            same. Your sponsor can find both numbers in their dashboard.
        @endif
    </p>

    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('join.submit') }}"
          class="space-y-5 bg-white rounded-2xl border border-gray-200 p-6 sm:p-8">
        @csrf

        <div>
            <label for="sponsor_adn" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Sponsor ADN <span class="text-red-700">*</span></label>
            <input type="text" id="sponsor_adn" name="sponsor_adn" required
                   value="{{ $sponsorAdn }}"
                   placeholder="111222333"
                   inputmode="numeric"
                   pattern="^[0-9]{9}(-S)?$"
                   maxlength="11"
                   autocomplete="off"
                   spellcheck="false"
                   data-adn-input="sponsor"
                   @if($sponsorLocked) readonly tabindex="-1" @endif
                   class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono uppercase tracking-widest focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 @if($sponsorLocked) bg-gray-50 text-gray-700 cursor-not-allowed @endif">
            <p data-adn-name="sponsor" class="mt-1.5 text-xs text-gray-500 min-h-[1.25rem]">
                @if($sponsorLocked)
                    Looking up sponsor name…
                @else
                    The Direct Seller who invited you.
                @endif
            </p>
        </div>

        <div>
            <label for="placement_adn" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Placement ADN <span class="text-red-700">*</span></label>
            <input type="text" id="placement_adn" name="placement_adn" required
                   value="{{ $placementAdn }}"
                   placeholder="111222333"
                   inputmode="numeric"
                   pattern="^[0-9]{9}(-S)?$"
                   maxlength="11"
                   autocomplete="off"
                   spellcheck="false"
                   data-adn-input="placement"
                   class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono uppercase tracking-widest focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500">
            <p data-adn-name="placement" class="mt-1.5 text-xs text-gray-500 min-h-[1.25rem]">
                Often the same as the Sponsor ADN. Ask your sponsor if you're unsure.
            </p>
        </div>

        <button type="submit"
            class="w-full rounded-full bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 shadow-lg shadow-brand-500/30">
            Continue to Orientation →
        </button>
    </form>

    <p class="mt-6 text-center text-xs text-gray-500">
        Don't have a sponsor's ADN?
        <a href="{{ route('contact.show', ['reason' => 'join_us']) }}" class="text-brand-700 hover:text-brand-800 font-medium">Contact us</a>
        and we'll connect you with one.
    </p>
</div>

<script>
(function () {
    // ADNs are 9 digits (optional `-S` couple-secondary suffix).
    // Strip spaces and force trailing 'S' uppercase as the user types.
    document.querySelectorAll('[data-adn-input]').forEach((el) => {
        el.addEventListener('input', () => {
            el.value = el.value.replace(/\s+/g, '').replace(/s$/, 'S');
            scheduleLookup(el);
        });
    });

    // Debounced live lookup against /join/lookup. Renders the resolved
    // distributor name into the matching <p data-adn-name="…"> sibling.
    const lookupUrl = @json(route('join.lookup'));
    const timers = {};
    const idleCopy = {
        sponsor:   "The Direct Seller who invited you.",
        placement: "Often the same as the Sponsor ADN. Ask your sponsor if you're unsure.",
    };

    function nameEl(key) {
        return document.querySelector('[data-adn-name="' + key + '"]');
    }

    function paint(key, state, text) {
        const el = nameEl(key);
        if (!el) return;
        el.classList.remove('text-gray-500', 'text-green-700', 'text-red-600', 'text-amber-700');
        el.classList.add(
            state === 'ok'       ? 'text-green-700'
            : state === 'bad'    ? 'text-red-600'
            : state === 'warn'   ? 'text-amber-700'
            : 'text-gray-500'
        );
        el.textContent = text;
    }

    function scheduleLookup(input) {
        const key = input.getAttribute('data-adn-input');
        const adn = input.value.trim();
        clearTimeout(timers[key]);

        if (adn === '') {
            paint(key, 'idle', idleCopy[key] || '');
            return;
        }
        if (!/^[0-9]{9}(-S)?$/i.test(adn)) {
            paint(key, 'bad', 'ADN must be exactly 9 digits.');
            return;
        }

        paint(key, 'idle', 'Looking up name…');
        timers[key] = setTimeout(() => doLookup(key, adn), 300);
    }

    function doLookup(key, adn) {
        fetch(lookupUrl + '?adn=' + encodeURIComponent(adn), { headers: { Accept: 'application/json' } })
            .then((r) => r.ok ? r.json() : null)
            .then((json) => {
                if (!json) {
                    paint(key, 'idle', idleCopy[key] || '');
                    return;
                }
                if (!json.found) {
                    paint(key, 'bad', 'No distributor with that ADN.');
                    return;
                }
                var emailSuffix = json.email_masked ? ' · ' + json.email_masked : '';
                if (json.is_secondary) {
                    paint(key, 'warn', '✓ ' + json.name + emailSuffix + ' (couple-secondary — use the primary spouse\'s ADN instead).');
                    return;
                }
                paint(key, 'ok', '✓ ' + json.name + emailSuffix);
            })
            .catch(() => paint(key, 'idle', idleCopy[key] || ''));
    }

    // On page load, if the sponsor came in pre-filled (locked) or the
    // user is editing pre-populated input, kick the lookups immediately.
    document.querySelectorAll('[data-adn-input]').forEach((el) => {
        if (el.value.trim() !== '') {
            scheduleLookup(el);
        }
    });
})();
</script>
@endsection
