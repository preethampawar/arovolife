{{-- Google Analytics 4 (gtag.js) behind a DPDP Act 2023 §5 consent gate.
     Emitted only when GOOGLE_ANALYTICS_ID is configured
     (config('arovolife.analytics.google_id')). Skipped on the admin
     console — internal staff actions shouldn't inflate public-funnel
     metrics, and admin pages render PII we don't want sent to GA.

     Consent model:
       localStorage['arovolife_ga_consent'] = 'granted' → load GA silently.
       'denied'                                         → never load GA.
       absent (first visit)                             → GA stays off; a
       consent banner is injected into <body> at DOMContentLoaded. Accept
       stores 'granted' and loads GA immediately; Decline stores 'denied'.
       The banner is built by this script rather than a separate body
       partial so every page that includes this loader (three layouts plus
       the standalone public/auth views) is covered automatically — no
       second include to forget. localStorage is per-browser, which is the
       agreed mechanism for both guests and authenticated distributors. --}}
@php $googleAnalyticsId = config('arovolife.analytics.google_id'); @endphp
@if(!empty($googleAnalyticsId))
<script>
(function () {
    'use strict';

    var CONSENT_KEY = 'arovolife_ga_consent';
    var GA_ID = {!! json_encode($googleAnalyticsId) !!};

    function readConsent() {
        try { return window.localStorage.getItem(CONSENT_KEY); } catch (e) { return null; }
    }

    function storeConsent(value) {
        try { window.localStorage.setItem(CONSENT_KEY, value); } catch (e) { /* private mode: session-only choice */ }
    }

    function loadGa() {
        if (window.__arovolifeGaLoaded) { return; }
        window.__arovolifeGaLoaded = true;
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
        window.gtag('js', new Date());
        window.gtag('config', GA_ID);
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
        banner.className = 'fixed inset-x-0 bottom-0 z-50 border-t border-gray-200 bg-white p-4 shadow-lg sm:flex sm:items-center sm:justify-between sm:gap-4 sm:px-6';

        var text = document.createElement('p');
        text.id = 'ga-consent-text';
        text.className = 'text-sm text-gray-700';
        text.textContent = 'arovolife uses Google Analytics to understand how this site is used. '
            + 'If you accept, we collect page views, your approximate location and your device type '
            + 'for analytics only. Nothing is collected unless you accept, and you can decline '
            + 'without losing any feature.';
        banner.appendChild(text);

        var actions = document.createElement('div');
        actions.className = 'mt-3 flex shrink-0 gap-3 sm:mt-0';

        var decline = document.createElement('button');
        decline.type = 'button';
        decline.textContent = 'Decline';
        decline.setAttribute('aria-label', 'Decline analytics');
        decline.className = 'rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2';
        decline.addEventListener('click', function () {
            storeConsent('denied');
            banner.remove();
        });

        var accept = document.createElement('button');
        accept.type = 'button';
        accept.textContent = 'Accept';
        accept.setAttribute('aria-label', 'Accept analytics');
        accept.className = 'rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2';
        accept.addEventListener('click', function () {
            storeConsent('granted');
            banner.remove();
            loadGa();
        });

        actions.appendChild(decline);
        actions.appendChild(accept);
        banner.appendChild(actions);
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
