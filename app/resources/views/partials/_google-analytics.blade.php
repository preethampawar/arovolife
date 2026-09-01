{{-- Google Analytics 4 (gtag.js) behind a DPDP Act 2023 §5/§6 consent gate.
     Emitted only when GOOGLE_ANALYTICS_ID is configured
     (config('arovolife.analytics.google_id')). Skipped on the admin
     console — internal staff actions should not inflate public-funnel metrics,
     and admin pages render PII we don't want sent to GA.

     INTENTIONALLY NOT included on auth/reset-password — the reset token is
     in the URL path and GA4's page_location would ship it to Google.

     Consent model (DPDP §5 — notice; §6 — consent lifecycle):
       localStorage['arovolife_ga_consent'] = 'granted' → GA loads silently.
       'denied'                                         → GA never loads.
       absent (first visit)                             → GA stays off; the
         §5 notice banner is injected at DOMContentLoaded. Accept / Decline
         both (a) POST to /analytics-consent for the server-side audit record
         and (b) store the decision in localStorage for subsequent page loads.
         The banner provides a withdrawal route on the preference page.

     Withdrawal / change of mind: /profile#analytics-preferences
       (linked in the banner and in profile/show). --}}
@php $googleAnalyticsId = config('arovolife.analytics.google_id'); @endphp
@if(!empty($googleAnalyticsId))
<script>
(function () {
    'use strict';

    var CONSENT_KEY = 'arovolife_ga_consent';
    var GA_ID = {!! json_encode($googleAnalyticsId) !!};
    var CONSENT_URL = '{{ route('analytics.consent.store') }}';
    var CSRF_TOKEN  = document.querySelector('meta[name="csrf-token"]')
        ? document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        : '';

    function readConsent() {
        try { return window.localStorage.getItem(CONSENT_KEY); } catch (e) { return null; }
    }

    function storeConsent(value) {
        try { window.localStorage.setItem(CONSENT_KEY, value); } catch (e) { /* private mode: session-only */ }
    }

    function postConsent(decision) {
        // Fire-and-forget: the audit log is best-effort and must never block
        // the user's choice being honoured in the browser. If the POST fails
        // (offline, network error) the localStorage preference is still set.
        try {
            fetch(CONSENT_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ decision: decision }),
                keepalive: true,
            });
        } catch (e) { /* intentionally swallowed */ }
    }

    function loadGa() {
        if (window.__arovolifeGaLoaded) { return; }
        window.__arovolifeGaLoaded = true;
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
        window.gtag('js', new Date());
        window.gtag('config', GA_ID, { anonymize_ip: true });
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA_ID);
        document.head.appendChild(s);
    }

    function buildBanner() {
        var banner = document.createElement('div');
        banner.id = 'ga-consent-banner';
        banner.setAttribute('role', 'region');
        banner.setAttribute('aria-label', 'Analytics consent');
        banner.className = 'fixed inset-x-0 bottom-0 z-50 border-t border-gray-200 bg-white shadow-lg';

        var inner = document.createElement('div');
        inner.className = 'mx-auto max-w-5xl px-4 py-4 sm:flex sm:items-start sm:justify-between sm:gap-6 sm:px-6';

        var textWrap = document.createElement('div');
        textWrap.className = 'min-w-0 flex-1';

        var heading = document.createElement('p');
        heading.className = 'text-sm font-semibold text-gray-900 mb-1';
        heading.textContent = 'Analytics cookies';
        textWrap.appendChild(heading);

        var body = document.createElement('p');
        body.id = 'ga-consent-text';
        body.className = 'text-sm text-gray-600 leading-relaxed';
        // §5(1)/(2) notice: purpose, data collected, recipient, cross-border transfer.
        body.textContent = 'We use Google Analytics 4 to understand how pages are used. '
            + 'It collects page views, approximate location (country/city level), device type and '
            + 'your anonymised IP address. This data is sent to Google LLC in the United States '
            + '(cross-border transfer). Analytics tracking starts only after you accept — '
            + 'you can change this at any time from your ';

        var prefLink = document.createElement('a');
        prefLink.href = '/profile#analytics-preferences';
        prefLink.className = 'underline text-brand-700 hover:text-brand-800';
        prefLink.textContent = 'preferences';
        body.appendChild(prefLink);

        var suffix = document.createTextNode(' or our ');
        body.appendChild(suffix);

        var privLink = document.createElement('a');
        privLink.href = '/p/privacy';
        privLink.className = 'underline text-brand-700 hover:text-brand-800';
        privLink.textContent = 'Privacy Policy';
        body.appendChild(privLink);

        body.appendChild(document.createTextNode('.'));
        textWrap.appendChild(body);

        var actions = document.createElement('div');
        actions.className = 'mt-3 flex shrink-0 gap-3 sm:mt-0 sm:items-center';

        var decline = document.createElement('button');
        decline.type = 'button';
        decline.textContent = 'Decline';
        decline.setAttribute('aria-label', 'Decline analytics tracking');
        decline.className = 'rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-600 focus:ring-offset-2';
        decline.addEventListener('click', function () {
            postConsent('denied');
            storeConsent('denied');
            banner.remove();
        });

        var accept = document.createElement('button');
        accept.type = 'button';
        accept.textContent = 'Accept';
        accept.setAttribute('aria-label', 'Accept analytics tracking');
        accept.className = 'rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-600 focus:ring-offset-2';
        accept.addEventListener('click', function () {
            postConsent('granted');
            storeConsent('granted');
            banner.remove();
            loadGa();
        });

        actions.appendChild(decline);
        actions.appendChild(accept);
        inner.appendChild(textWrap);
        inner.appendChild(actions);
        banner.appendChild(inner);
        document.body.appendChild(banner);
    }

    var consent = readConsent();
    if (consent === 'granted') {
        loadGa();
        return;
    }
    if (consent === 'denied') {
        return;
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildBanner);
    } else {
        buildBanner();
    }
})();
</script>
@endif
